<?php declare(strict_types=1);

namespace ReactParallel\Tests\EventLoop;

use parallel\Channel;
use parallel\Events;
use parallel\Future;
use parallel\Runtime;
use React\EventLoop\Factory;
use ReactParallel\EventLoop\CanceledFuture;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\EventLoop\KilledRuntime;
use ReactParallel\Streams\RecvObservable;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use function Clue\React\Block\await;
use function Clue\React\Block\awaitAll;
use function parallel\run;
use function React\Promise\all;
use function sleep;

/**
 * @internal
 */
final class EventLoopBridgeTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function read(): void
    {
        $d = bin2hex(random_bytes(13));

        $loop = Factory::create();
        $channels = [Channel::make($d . '_a', Channel::Infinite), Channel::make($d . '_b', Channel::Infinite)];

        $eventLoopBridge = new EventLoopBridge($loop);

        $future = run(function () use ($channels): string {
            foreach (range(0, 13) as $i) {
                usleep(100);
                foreach (range(0, 13) as $j) {
                    foreach ($channels as $channel) {
                        $channel->send($i);
                    }
                }
            }
            sleep(1);
            foreach ($channels as $channel) {
                $channel->close();
            }
            sleep(1);

            return 'Elmo';
        });
        assert($future instanceof Future);

        $promises = [];
        foreach ($channels as $channel) {
            $promises[] = $eventLoopBridge->observe($channel)->toArray()->toPromise();
        }
        $promises[] = $eventLoopBridge->await($future);

        $rd = awaitAll($promises, $loop);

        $range = [];
        foreach (range(0, 13) as $i) {
            foreach (range(0, 13) as $j) {
                $range[] = $i;
            }
        }
        self::assertSame([$range, $range, 'Elmo'], $rd);
    }

    /**
     * @test
     */
    public function close(): void
    {
        $d = bin2hex(random_bytes(13));

        $loop = Factory::create();
        $channel = Channel::make($d . '_a', Channel::Infinite);

        $eventLoopBridge = new EventLoopBridge($loop);
        $eventLoopBridge->observe($channel)->subscribe($this->expectCallableNever(), $this->expectCallableNever(), $this->expectCallableOnce());

        $loop->addTimer(1, function () use ($channel): void { $channel->close();});
        $loop->run();
    }

    /**
     * @test
     */
    public function cancel(): void
    {
        self::expectException(CanceledFuture::class);

        $loop = Factory::create();
        $future = run(fn () => sleep(3));
        assert($future instanceof Future);

        $eventLoopBridge = new EventLoopBridge($loop);

        $loop->addTimer(1, function () use ($future): void { $future->cancel();});
        $this->await($eventLoopBridge->await($future)->then($this->expectCallableNever()), $loop, null);
    }

    /**
     * @test
     */
    public function kill(): void
    {
        self::expectException(KilledRuntime::class);

        $loop = Factory::create();
        $runtime = new Runtime();
        $future = $runtime->run(fn () => sleep(3));
        assert($future instanceof Future);

        $eventLoopBridge = new EventLoopBridge($loop);

        $loop->addTimer(1, function () use ($runtime): void { $runtime->kill();});
        $this->await($eventLoopBridge->await($future)->then($this->expectCallableNever()), $loop, null);
    }

    /**
     * @test
     */
    public function channelError(): void
    {
        self::expectException(Channel\Error\IllegalValue::class);
        self::expectExceptionMessage('value of type Exception is illegal');

        $loop = Factory::create();
        $channel = new Channel(Channel::Infinite);

        $loop->addTimer(1, function () use ($channel): void { $channel->send(new \Exception('nope'));});

        $eventLoopBridge = new EventLoopBridge($loop);

        $this->await($eventLoopBridge->observe($channel)->toPromise()->then($this->expectCallableNever()), $loop, null);
    }

    /**
     * @test
     */
    public function futureError(): void
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage('Cookie Monster');

        $loop = Factory::create();
        $future = run(function (): void {
            sleep(1);
            throw new \Exception('Cookie Monster');
        });
        assert($future instanceof Future);

        $eventLoopBridge = new EventLoopBridge($loop);

        $this->await($eventLoopBridge->await($future)->then($this->expectCallableNever()), $loop, null);
    }
}
