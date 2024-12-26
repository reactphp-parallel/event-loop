<?php

declare(strict_types=1);

namespace ReactParallel\EventLoop;

use React\Promise\Deferred;
use SplQueue;

use function React\Async\await;

final class Stream
{
    private SplQueue $queue;
    private Deferred $wait;

    public function __construct()
    {
        $this->queue = new SplQueue();
        $this->queue->setIteratorMode(SplQueue::IT_MODE_DELETE);
        $this->wait = new Deferred();
    }

    public function value(mixed $value): void
    {
        $this->queue->enqueue($value);
        $this->wait->resolve(new Value());
    }

    public function done(): void
    {
        $this->wait->resolve(new Done());
    }

    /** @return iterable<mixed> */
    public function iterable(): iterable
    {
        do {
            $run  = false;
            $type = await($this->wait->promise());

            foreach ($this->queue as $value) {
                yield $value;
            }

            if ($type instanceof Done) {
                continue;
            }

            $this->wait = new Deferred();
            $run        = true;
        } while ($run);
    }
}
