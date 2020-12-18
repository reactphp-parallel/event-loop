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
use WyriHaximus\Metrics\Label;

use function count;
use function spl_object_hash;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;
use const WyriHaximus\Constants\Numeric\ZERO;

final class EventLoopBridge
{
    private const DEFAULT_SCALE_RANGE = [
        0.1,
        0.01,
        0.001,
    ];

    private const DEFAULT_SCALE_POSITION = 2;

    private LoopInterface $loop;

    private ?Metrics $metrics = null;

    private Events $events;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private TimerInterface $timer;

    /** @var array<string, Subject> */
    private array $channels = [];

    /** @var array<string, Deferred> */
    private array $futures = [];

    private bool $timerActive = FALSE_;

    /** @var array<float> */
    private array $scaleRange      = self::DEFAULT_SCALE_RANGE;
    private int $scalePosition     = self::DEFAULT_SCALE_POSITION;
    private int $scaleNoItemsCount = 0;

    public function __construct(LoopInterface $loop)
    {
        $this->loop   = $loop;
        $this->events = new Events();
        $this->events->setTimeout(ZERO);
    }

    public function withMetrics(Metrics $metrics): self
    {
        $self          = clone $this;
        $self->metrics = $metrics;

        return $self;
    }

    public function observe(Channel $channel): Observable
    {
        $subject                                   = new Subject();
        $this->channels[spl_object_hash($channel)] = $subject;
        $this->events->addChannel($channel);

        if ($this->metrics instanceof Metrics) {
            $this->metrics->channels()->gauge(new Label('state', 'active'))->incr();
        }

        $this->startTimer();

        return $subject;
    }

    public function await(Future $future): PromiseInterface
    {
        $deferred                                = new Deferred();
        $this->futures[spl_object_hash($future)] = $deferred;
        $this->events->addFuture(spl_object_hash($future), $future);

        if ($this->metrics instanceof Metrics) {
            $this->metrics->futures()->gauge(new Label('state', 'active'))->incr();
        }

        $this->startTimer();

        return $deferred->promise();
    }

    private function startTimer(): void
    {
        if ($this->timerActive) {
            return;
        }

        if ($this->metrics instanceof Metrics) {
            $this->metrics->timer()->counter(new Label('event', 'start'))->incr();
        }

        $this->runTimer();

        $this->timerActive = TRUE_;
    }

    private function stopTimer(): void
    {
        if (count($this->channels) !== ZERO || count($this->futures) !== ZERO) {
            return;
        }

        $this->loop->cancelTimer($this->timer);
        $this->timerActive = FALSE_;

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $this->metrics->timer()->counter(new Label('event', 'stop'))->incr();
    }

    private function runTimer(): void
    {
        $this->timer = $this->loop->addPeriodicTimer($this->scaleRange[$this->scalePosition], function (): void {
            $items = 0;

            try {
                while ($event = $this->events->poll()) {
                    $items++;
                    /**
                     * @phpstan-ignore-next-line
                     */
                    switch ($event->type) {
                        case Events\Event\Type::Read:
                            $this->handleReadEvent($event); /** @phpstan-ignore-line */
                            break;
                        case Events\Event\Type::Close:
                            $this->handleCloseEvent($event); /** @phpstan-ignore-line */
                            break;
                        case Events\Event\Type::Cancel:
                            $this->handleCancelEvent($event); /** @phpstan-ignore-line */
                            break;
                        case Events\Event\Type::Kill:
                            $this->handleKillEvent($event); /** @phpstan-ignore-line */
                            break;
                        case Events\Event\Type::Error:
                            $this->handleErrorEvent($event); /** @phpstan-ignore-line */
                            break;
                    }
                }
            } catch (Events\Error\Timeout $timeout) {
                // Catch and ignore this exception as it will trigger when events::poll() will have nothing for us
                // @ignoreException
            }

            $this->stopTimer();

            /** @phpstan-ignore-next-line */
            if ($items > 0 && isset($this->scaleRange[$this->scalePosition + 1])) {
                $this->loop->cancelTimer($this->timer);

                $this->scalePosition++;
                $this->runTimer();

                $this->scaleNoItemsCount = 0;
            }

            if ($items === 0) {
                $this->scaleNoItemsCount++;

                /** @phpstan-ignore-next-line */
                if ($this->scaleNoItemsCount > 10 && isset($this->scaleRange[$this->scalePosition - 1])) {
                    $this->loop->cancelTimer($this->timer);

                    $this->scalePosition--;
                    $this->runTimer();

                    $this->scaleNoItemsCount = 0;
                }
            }

            if (! ($this->metrics instanceof Metrics)) {
                return;
            }

            $this->metrics->timer()->counter(new Label('event', 'tick'))->incr();
            $this->metrics->timerItems()->counter(new Label('count', (string) $items))->incr();
        });
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
        unset($this->futures[spl_object_hash($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'resolve'))->incr();
    }

    private function handleChannelReadEvent(Events\Event $event): void
    {
        $this->channels[spl_object_hash($event->object)]->onNext($event->value);
        $this->events->addChannel($event->object); /** @phpstan-ignore-line */

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $this->metrics->channelMessages()->counter(new Label('event', 'read'))->incr();
    }

    private function handleCloseEvent(Events\Event $event): void
    {
        $this->channels[spl_object_hash($event->object)]->onCompleted();
        unset($this->channels[spl_object_hash($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $channels = $this->metrics->channels();
        $channels->gauge(new Label('state', 'active'))->dcr();
        $channels->gauge(new Label('state', 'close'))->incr();
    }

    private function handleCancelEvent(Events\Event $event): void
    {
        $this->futures[spl_object_hash($event->object)]->reject(new CanceledFuture());
        unset($this->futures[spl_object_hash($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'cancel'))->incr();
    }

    private function handleKillEvent(Events\Event $event): void
    {
        $this->futures[spl_object_hash($event->object)]->reject(new KilledRuntime());
        unset($this->futures[spl_object_hash($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'kill'))->incr();
    }

    private function handleErrorEvent(Events\Event $event): void
    {
        if (! ($event->object instanceof Future)) {
            return;
        }

        $this->futures[spl_object_hash($event->object)]->reject($event->value);
        unset($this->futures[spl_object_hash($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'error'))->incr();
    }
}
