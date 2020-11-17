<?php

declare(strict_types=1);

namespace ReactParallel\EventLoop;

use WyriHaximus\Metrics\Label\Name;
use WyriHaximus\Metrics\Registry;

final class Metrics
{
    private Registry\Gauges $channels;
    private Registry\Gauges $futures;
    private Registry\Counters $timer;
    private Registry\Counters $timerItems;
    private Registry\Counters $channelMessages;

    public function __construct(
        Registry\Gauges $channels,
        Registry\Gauges $futures,
        Registry\Counters $timer,
        Registry\Counters $timerItems,
        Registry\Counters $channelMessages
    ) {
        $this->channels        = $channels;
        $this->futures         = $futures;
        $this->timer           = $timer;
        $this->timerItems      = $timerItems;
        $this->channelMessages = $channelMessages;
    }

    public static function create(Registry $registry): self
    {
        return new self(
            $registry->gauge(
                'react_parallel_event_loop_channels',
                'Currently active channels in the event loop bridge',
                new Name('state')
            ),
            $registry->gauge(
                'react_parallel_event_loop_futures',
                'Currently active futures in the event loop bridge',
                new Name('state')
            ),
            $registry->counter(
                'react_parallel_event_loop_timer',
                'Currently active channels in the event loop bridge',
                new Name('event')
            ),
            $registry->counter(
                'react_parallel_event_loop_timer_items',
                'Items handler per tick',
                new Name('count')
            ),
            $registry->counter(
                'react_parallel_event_loop_channel_messages',
                'Currently active channels in the event loop bridge',
                new Name('event')
            ),
        );
    }

    public function channels(): Registry\Gauges
    {
        return $this->channels;
    }

    public function futures(): Registry\Gauges
    {
        return $this->futures;
    }

    public function timer(): Registry\Counters
    {
        return $this->timer;
    }

    public function timerItems(): Registry\Counters
    {
        return $this->timerItems;
    }

    public function channelMessages(): Registry\Counters
    {
        return $this->channelMessages;
    }
}
