<?php

declare(strict_types=1);

namespace ReactParallel\Tests\EventLoop;

use parallel\Channel;
use parallel\Future;
use parallel\Runtime;
use React\EventLoop\Loop;
use ReactParallel\EventLoop\CanceledFuture;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\EventLoop\KilledRuntime;
use ReactParallel\EventLoop\Metrics;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\Metrics\Configuration;
use WyriHaximus\Metrics\InMemory\Registry;

use function assert;
use function bin2hex;
use function dirname;
use function parallel\run;
use function random_bytes;
use function range;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;
use function React\Promise\resolve;
use function sleep;
use function time;
use function usleep;

final class EventLoopBridgeTest extends AsyncTestCase
{
    /** @test */
    public function read(): void
    {
        $d = bin2hex(random_bytes(13));

        $channels = [Channel::make($d . '_a', Channel::Infinite), Channel::make($d . '_b', Channel::Infinite)];

        $eventLoopBridge = (new EventLoopBridge())->withMetrics(Metrics::create(new Registry(Configuration::create())));

        $future = run(static function () use ($channels): string {
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
            $promises[] = async(static function (EventLoopBridge $eventLoopBridge, Channel $channel): array {
                $items = [];
                foreach ($eventLoopBridge->observe($channel) as $item) {
                    $items[] = $item;
                }

                return $items;
            })($eventLoopBridge, $channel);
        }

        $promises[] = resolve($eventLoopBridge->await($future));

        $rd = await(all($promises));

        $range = [];
        foreach (range(0, 13) as $i) {
            foreach (range(0, 13) as $j) {
                $range[] = $i;
            }
        }

        self::assertSame([$range, $range, 'Elmo'], $rd);
    }

    /** @test */
    public function close(): void
    {
        $d = bin2hex(random_bytes(13));

        $channel = Channel::make($d . '_a', Channel::Infinite);
        Loop::addTimer(1, static function () use ($channel): void {
            $channel->close();
        });

        $onNext          = false;
        $eventLoopBridge = (new EventLoopBridge())->withMetrics(Metrics::create(new Registry(Configuration::create())));
        foreach ($eventLoopBridge->observe($channel) as $item) {
            $onNext = true;
        }

        self::assertFalse($onNext, 'onNext should never be called');
    }

    /** @test */
    public function cancel(): void
    {
        self::expectException(CanceledFuture::class);

        $future = run(static fn () => sleep(3));
        assert($future instanceof Future);

        $eventLoopBridge = (new EventLoopBridge())->withMetrics(Metrics::create(new Registry(Configuration::create())));

        Loop::addTimer(1, static function () use ($future): void {
            $future->cancel();
        });
        $eventLoopBridge->await($future);
    }

    /** @test */
    public function kill(): void
    {
        self::expectException(KilledRuntime::class);

        $runtime = new Runtime();
        $future  = $runtime->run(static function (): int {
            sleep(3);

            return time();
        });

        assert($future instanceof Future);

        $eventLoopBridge = (new EventLoopBridge())->withMetrics(Metrics::create(new Registry(Configuration::create())));

        Loop::addTimer(1, static function () use ($runtime): void {
            $runtime->kill();
        });
        $eventLoopBridge->await($future);
    }

    /** @test */
    public function futureError(): void
    {
        self::expectException(CookieMonsterException::class);
        self::expectExceptionMessage('Cookie Monster');

        $future = run(static function (): void {
            require_once dirname(__DIR__) . '/vendor/autoload.php';

            sleep(1);

            throw new CookieMonsterException('Cookie Monster');
        });
        assert($future instanceof Future);

        $eventLoopBridge = (new EventLoopBridge())->withMetrics(Metrics::create(new Registry(Configuration::create())));

        $eventLoopBridge->await($future);
    }
}
