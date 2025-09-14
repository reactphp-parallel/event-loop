<?php

declare(strict_types=1);

namespace ReactParallel\Tests\EventLoop;

use parallel\Channel;
use parallel\Runtime;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use ReactParallel\EventLoop\CanceledFuture;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\EventLoop\KilledRuntime;
use ReactParallel\EventLoop\Metrics;
use Throwable;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\Metrics\Configuration;
use WyriHaximus\Metrics\InMemory\Registry;
use WyriHaximus\Metrics\Printer\Prometheus;
use WyriHaximus\Metrics\Registry as RegistryContract;

use function bin2hex;
use function dirname;
use function parallel\run;
use function random_bytes;
use function range;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;
use function React\Promise\resolve;
use function React\Promise\Timer\sleep;
use function sleep as blockingSleep;
use function usleep;

final class EventLoopBridgeTest extends AsyncTestCase
{
    #[Test]
    public function read(): void
    {
        $d = bin2hex(random_bytes(13));

        $channels = [Channel::make($d . '_a', Channel::Infinite), Channel::make($d . '_b', Channel::Infinite)];

        [$eventLoopBridge, $metricsRegistry] = $this->createBridge();

        $future = run(static function () use ($channels): string {
            foreach (range(0, 13) as $i) {
                usleep(100);
                foreach (range(0, 13) as $j) {
                    foreach ($channels as $channel) {
                        $channel->send($i);
                    }
                }
            }

            blockingSleep(1);
            foreach ($channels as $channel) {
                $channel->close();
            }

            blockingSleep(1);

            return 'Elmo';
        });

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
        $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_timer_total{event="start"} 1', 'react_parallel_event_loop_timer_total{event="tick"} ', 'react_parallel_event_loop_channel_messages_total{event="read"} 392', 'react_parallel_event_loop_channels{state="active"} 0', 'react_parallel_event_loop_channels{state="close"} 2', 'react_parallel_event_loop_futures{state="active"} 1');
    }

    #[Test]
    public function close(): void
    {
        $d = bin2hex(random_bytes(13));

        $channel = Channel::make($d . '_a', Channel::Infinite);
        Loop::addTimer(0.1, static function () use ($channel): void {
            $channel->close();
        });

        $onNext                              = false;
        [$eventLoopBridge, $metricsRegistry] = $this->createBridge();
        foreach ($eventLoopBridge->observe($channel) as $item) {
            $onNext = true;
        }

        self::assertFalse($onNext, 'onNext should never be called');
        $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_timer_total{event="start"} 1', 'react_parallel_event_loop_channels{state="active"} 1');
        $this->assertPrintMatchesRegularExpression($metricsRegistry, '/react_parallel_event_loop_timer_total\{event\="tick"\} [0-9]/m', '/react_parallel_event_loop_timer_items_total\{count\="0"\} [0-9]/m');
    }

    #[Test]
    public function cancel(): void
    {
        self::expectException(CanceledFuture::class);
        [$eventLoopBridge, $metricsRegistry] = $this->createBridge();

        try {
            $future = run(static fn (): int => blockingSleep(3));

            Loop::addTimer(1, static function () use ($future): void {
                $future->cancel();
            });
            $eventLoopBridge->await($future);
        } catch (Throwable $error) {
            throw $error;
        } finally {
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 1');
            await(sleep(0.000001));
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 0', 'react_parallel_event_loop_futures{state="cancel"} 1');
        }
    }

    #[Test]
    public function kill(): void
    {
        self::expectException(KilledRuntime::class);
        [$eventLoopBridge, $metricsRegistry] = $this->createBridge();

        try {
            $runtime = new Runtime();
            $future  = $runtime->run(static function (): string {
                blockingSleep(3);

                return 'hammer';
            });

            Loop::addTimer(1, static function () use ($runtime): void {
                $runtime->kill();
            });
            $eventLoopBridge->await($future);
        } catch (Throwable $error) {
            throw $error;
        } finally {
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 1');
            await(sleep(0.000001));
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 0', 'react_parallel_event_loop_futures{state="kill"} 1');
        }
    }

    #[Test]
    public function futureError(): void
    {
        self::expectException(CookieMonsterException::class);
        self::expectExceptionMessage('Cookie Monster');
        [$eventLoopBridge, $metricsRegistry] = $this->createBridge();

        try {
            $future = run(static function (): never {
                require_once dirname(__DIR__) . '/vendor/autoload.php';

                blockingSleep(1);

                throw new CookieMonsterException('Cookie Monster');
            });

            /** @phpstan-ignore deadCode.unreachable */
            $eventLoopBridge->await($future);
        } catch (Throwable $error) {
            throw $error;
        } finally {
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 1');
            await(sleep(0.000001));
            $this->assertPrint($metricsRegistry, 'react_parallel_event_loop_futures{state="active"} 0', 'react_parallel_event_loop_futures{state="error"} 1');
        }
    }

    /** @return array{EventLoopBridge, RegistryContract} */
    private function createBridge(): array
    {
        $metricsRegistry = new Registry(Configuration::create());
        $rawBridge       = new EventLoopBridge();
        $eventLoopBridge = $rawBridge->withMetrics(Metrics::create($metricsRegistry));
        self::assertNotSame($eventLoopBridge, $rawBridge);

        return [$eventLoopBridge, $metricsRegistry];
    }

    private function assertPrint(RegistryContract $metricsRegistry, string ...$expectedMethods): void
    {
        $print = $metricsRegistry->print(new Prometheus());

        foreach ($expectedMethods as $expectedMethod) {
            self::assertStringContainsString($expectedMethod, $print);
        }
    }

    private function assertPrintMatchesRegularExpression(RegistryContract $metricsRegistry, string ...$expectedMethods): void
    {
        $print = $metricsRegistry->print(new Prometheus());

        foreach ($expectedMethods as $expectedMethod) {
            self::assertMatchesRegularExpression($expectedMethod, $print);
        }
    }
}
