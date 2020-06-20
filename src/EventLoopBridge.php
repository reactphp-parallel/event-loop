<?php

declare(strict_types=1);

namespace ReactParallel\EventLoop;

use parallel\Channel;
use parallel\Events;
use parallel\Future;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Rx\Observable;
use Rx\Subject\Subject;

use function count;
use function spl_object_hash;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;
use const WyriHaximus\Constants\Numeric\ZERO;

final class EventLoopBridge
{
    private LoopInterface $loop;

    private Events $events;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private TimerInterface $timer;

    /** @var array<string, Subject> */
    private array $channels = [];

    /** @var array<string, Deferred> */
    private array $futures = [];

    private bool $timerActive = FALSE_;

    public function __construct(LoopInterface $loop)
    {
        $this->loop   = $loop;
        $this->events = new Events();
        $this->events->setTimeout(ZERO);
    }

    public function observe(Channel $channel): Observable
    {
        $subject                                   = new Subject();
        $this->channels[spl_object_hash($channel)] = $subject;
        $this->events->addChannel($channel);

        $this->startTimer();

        return $subject;
    }

    public function await(Future $future): PromiseInterface
    {
        $deferred                                = new Deferred();
        $this->futures[spl_object_hash($future)] = $deferred;
        $this->events->addFuture(spl_object_hash($future), $future);

        $this->startTimer();

        return $deferred->promise();
    }

    private function startTimer(): void
    {
        if ($this->timerActive) {
            return;
        }

        // Call 1K times per second
        $this->timer       = $this->loop->addPeriodicTimer(0.001, function (): void {
            try {
                while ($event = $this->events->poll()) {
                    switch ($event->type) {
                        case Events\Event\Type::Read:
                            $this->handleReadEvent($event);
                            break;
                        case Events\Event\Type::Close:
                            $this->handleCloseEvent($event);
                            break;
                        case Events\Event\Type::Cancel:
                            $this->handleCancelEvent($event);
                            break;
                        case Events\Event\Type::Kill:
                            $this->handleKillEvent($event);
                            break;
                        case Events\Event\Type::Error:
                            $this->handleErrorEvent($event);
                            break;
                    }
                }
            } catch (Events\Error\Timeout $timeout) {
                // Catch and ignore this exception as it will trigger when events::poll() will have nothing for us
                // @ignoreException
            }

            $this->stopTimer();
        });
        $this->timerActive = TRUE_;
    }

    private function stopTimer(): void
    {
        if (count($this->channels) !== ZERO || count($this->futures) !== ZERO) {
            return;
        }

        $this->loop->cancelTimer($this->timer);
        $this->timerActive = FALSE_;
    }

    private function handleReadEvent(Events\Event $event): void
    {
        if ($event->object instanceof Future) {
            $this->handleFutureReadEvent($event);
        }

        if (! ($event->object instanceof Channel)) {
            return;
        }

        $this->handleChannelReadEvent($event);
    }

    private function handleFutureReadEvent(Events\Event $event): void
    {
        $this->futures[spl_object_hash($event->object)]->resolve($event->value);
    }

    private function handleChannelReadEvent(Events\Event $event): void
    {
        $this->channels[spl_object_hash($event->object)]->onNext($event->value);
        $this->events->addChannel($event->object);
    }

    private function handleCloseEvent(Events\Event $event): void
    {
        $this->channels[spl_object_hash($event->object)]->onCompleted();
        unset($this->channels[spl_object_hash($event->object)]);
    }

    private function handleCancelEvent(Events\Event $event): void
    {
        $this->futures[spl_object_hash($event->object)]->reject(new CanceledFuture());
        unset($this->futures[spl_object_hash($event->object)]);
    }

    private function handleKillEvent(Events\Event $event): void
    {
        $this->futures[spl_object_hash($event->object)]->reject(new KilledRuntime());
        unset($this->futures[spl_object_hash($event->object)]);
    }

    private function handleErrorEvent(Events\Event $event): void
    {
        if (! ($event->object instanceof Future)) {
            return;
        }

        $this->futures[spl_object_hash($event->object)]->reject($event->value);
        unset($this->futures[spl_object_hash($event->object)]);
    }
}
