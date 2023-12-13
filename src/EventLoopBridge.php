<?php

declare(strict_types=1);

namespace ReactParallel\EventLoop;

use parallel\Channel;
use parallel\Events;
use parallel\Events\Event;
use parallel\Future;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use Rx\Subject\Subject;
use WyriHaximus\Metrics\Label;

use function count;
use function React\Async\await;
use function spl_object_hash;
use function spl_object_id;
use function WyriHaximus\React\awaitObservable;

use const WyriHaximus\Constants\Numeric\ZERO;

final class EventLoopBridge
{
    private const DEFAULT_SCALE_RANGE = [
        0.01,
        0.0075,
        0.0050,
        0.0025,
        0.001,
    ];

    private const DEFAULT_SCALE_POSITION = 2;

    private Metrics|null $metrics = null;

    /**
     * @template T
     * @var Events<Events\Event<T>>
     */
    private Events $events;

    private TimerInterface|null $timer = null;

    /**
     * @template T
     * @var array<int, Subject<T>>
     */
    private array $channels = [];

    /**
     * @template T
     * @var array<int, Deferred<T>>
     */
    private array $futures = [];

    /** @var array<float> */
    private array $scaleRange      = self::DEFAULT_SCALE_RANGE;
    private int $scalePosition     = self::DEFAULT_SCALE_POSITION;
    private int $scaleNoItemsCount = ZERO;

    public function __construct()
    {
        $this->events = new Events();
        $this->events->setTimeout(ZERO);
    }

    public function withMetrics(Metrics $metrics): self
    {
        $self          = clone $this;
        $self->metrics = $metrics;

        return $self;
    }

    /**
     * @param Channel<T> $channel
     *
     * @return iterable<T>
     *
     * @template T
     */
    public function observe(Channel $channel): iterable
    {
        /** @var Subject<T> $subject */
        $subject                                 = new Subject();
        $this->channels[spl_object_id($channel)] = $subject;
        $this->events->addChannel($channel);

        if ($this->metrics instanceof Metrics) {
            $this->metrics->channels()->gauge(new Label('state', 'active'))->incr();
        }

        $this->startTimer();

        return awaitObservable($subject);
    }

    /**
     * @param Future<T> $futurea
     *
     * @return T
     *
     * @template T
     */
    public function await(Future $future): mixed
    {
        /** @var Deferred<T> $deferred */
        $deferred                              = new Deferred();
        $this->futures[spl_object_id($future)] = $deferred;
        $this->events->addFuture(spl_object_hash($future), $future);

        if ($this->metrics instanceof Metrics) {
            $this->metrics->futures()->gauge(new Label('state', 'active'))->incr();
        }

        $this->startTimer();

        return await($deferred->promise());
    }

    private function startTimer(): void
    {
        if ($this->timer !== null) {
            return;
        }

        if ($this->metrics instanceof Metrics) {
            $this->metrics->timer()->counter(new Label('event', 'start'))->incr();
        }

        $this->runTimer();
    }

    private function stopTimer(): void
    {
        if ($this->timer === null || count($this->channels) !== ZERO || count($this->futures) !== ZERO) {
            return;
        }

        Loop::cancelTimer($this->timer);
        $this->timer = null;

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $this->metrics->timer()->counter(new Label('event', 'stop'))->incr();
    }

    private function runTimer(): void
    {
        $this->timer = Loop::addPeriodicTimer($this->scaleRange[$this->scalePosition], function (): void {
            $items = ZERO;

            try {
                while ($event = $this->events->poll()) {
                    $items++;
                    /** @phpstan-ignore-next-line */
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
            } catch (Events\Error\Timeout) {
                // Catch and ignore this exception as it will trigger when events::poll() will have nothing for us
                // @ignoreException
            }

            $this->stopTimer();

            /** @phpstan-ignore-next-line */
            if ($items > ZERO && isset($this->scaleRange[$this->scalePosition + 1])) {
                if ($this->timer instanceof TimerInterface) {
                    Loop::cancelTimer($this->timer);
                    $this->timer = null;
                }

                $this->scalePosition++;
                $this->runTimer();

                $this->scaleNoItemsCount = ZERO;
            }

            if ($items === ZERO) {
                $this->scaleNoItemsCount++;

                /** @phpstan-ignore-next-line */
                if ($this->scaleNoItemsCount > 10 && isset($this->scaleRange[$this->scalePosition - 1])) {
                    if ($this->timer instanceof TimerInterface) {
                        Loop::cancelTimer($this->timer);
                        $this->timer = null;
                    }

                    $this->scalePosition--;
                    $this->runTimer();

                    $this->scaleNoItemsCount = ZERO;
                }
            }

            if (! ($this->metrics instanceof Metrics)) {
                return;
            }

            $this->metrics->timer()->counter(new Label('event', 'tick'))->incr();
            $this->metrics->timerItems()->counter(new Label('count', (string) $items))->incr();
        });
    }

    /**
     * @param Event<T> $event
     *
     * @template T
     */
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

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleFutureReadEvent(Events\Event $event): void
    {
        $this->futures[spl_object_id($event->object)]->resolve($event->value);
        unset($this->futures[spl_object_id($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'resolve'))->incr();
    }

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleChannelReadEvent(Events\Event $event): void
    {
        $this->channels[spl_object_id($event->object)]->onNext($event->value);
        $this->events->addChannel($event->object); /** @phpstan-ignore-line */

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $this->metrics->channelMessages()->counter(new Label('event', 'read'))->incr();
    }

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleCloseEvent(Events\Event $event): void
    {
        $this->channels[spl_object_id($event->object)]->onCompleted();
        unset($this->channels[spl_object_id($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $channels = $this->metrics->channels();
        $channels->gauge(new Label('state', 'active'))->dcr();
        $channels->gauge(new Label('state', 'close'))->incr();
    }

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleCancelEvent(Events\Event $event): void
    {
        $this->futures[spl_object_id($event->object)]->reject(new CanceledFuture());
        unset($this->futures[spl_object_id($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'cancel'))->incr();
    }

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleKillEvent(Events\Event $event): void
    {
        $this->futures[spl_object_id($event->object)]->reject(new KilledRuntime());
        unset($this->futures[spl_object_id($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'kill'))->incr();
    }

    /**
     * @param Events\Event<T> $event
     *
     * @template T
     */
    private function handleErrorEvent(Events\Event $event): void
    {
        if (! ($event->object instanceof Future)) {
            return;
        }

        $this->futures[spl_object_id($event->object)]->reject($event->value);
        unset($this->futures[spl_object_id($event->object)]);

        if (! ($this->metrics instanceof Metrics)) {
            return;
        }

        $futures = $this->metrics->futures();
        $futures->gauge(new Label('state', 'active'))->dcr();
        $futures->gauge(new Label('state', 'error'))->incr();
    }
}
