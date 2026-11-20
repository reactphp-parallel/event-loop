<?php

declare(strict_types=1);

namespace ReactParallel\EventLoop;

use React\Promise\Deferred;
use SplQueue;

use function React\Async\await;

/**
 * @template T
 * @template-implements StreamInterface<T>
 */
final class Stream implements StreamInterface
{
    /** @var SplQueue<T> */
    private readonly SplQueue $queue;

    /** @var Deferred<Value|Done> */
    private Deferred $wait;

    private bool $isDone = false;

    public function __construct()
    {
        $this->queue = new SplQueue();
        $this->queue->setIteratorMode(SplQueue::IT_MODE_DELETE | SplQueue::IT_MODE_DELETE);
        $this->wait = new Deferred();
    }

    /** @param T $value */
    public function value(mixed $value): void
    {
        $this->queue->enqueue($value);
        $this->wait->resolve(new Value());
    }

    public function done(): void
    {
        if ($this->isDone) {
            return;
        }

        $this->isDone = true;
        $this->resolveDone();
    }

    private function resolveDone(): void
    {
        // Suppress FiberError: the Fiber may already be in the finally block
        // when done() is called, making resume() fail.
        $promise = $this->wait->promise();
        $this->wait->resolve(new Done());
        $promise->catch(static function (): void {
            // Ignore FiberError and any other rejection
        });
    }

    /** @return iterable<T> */
    public function iterable(): iterable
    {
        for (;;) {
            $type = await($this->wait->promise());

            foreach ($this->queue as $value) {
                yield $value;
            }

            if ($type instanceof Done) {
                break;
            }

            $this->wait = new Deferred();
        }
    }
}
