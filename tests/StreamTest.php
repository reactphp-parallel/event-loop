<?php

declare(strict_types=1);

namespace ReactParallel\Tests;

use PHPUnit\Framework\Attributes\Test;
use ReactParallel\EventLoop\Stream;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function React\Async\async;
use function React\Async\await;

final class StreamTest extends AsyncTestCase
{
    #[Test]
    public function donePreventsSubsequentResolution(): void
    {
        /** @var Stream<string> $stream */
        $stream = new Stream();

        self::assertSame([], await(async(static function () use ($stream): array {
            $stream->done();
            // If done() is not idempotent, the second resolve would cause issues
            $stream->done();

            $items = [];
            foreach ($stream->iterable() as $item) {
                $items[] = $item;
            }

            return $items;
        })()));
    }

    #[Test]
    public function iterableStopsWithoutYieldingWhenDoneBeforeValues(): void
    {
        /** @var Stream<string> $stream */
        $stream = new Stream();

        self::assertSame([], await(async(static function () use ($stream): array {
            $stream->done();

            $items = [];
            foreach ($stream->iterable() as $item) {
                $items[] = $item;
            }

            return $items;
        })()));
    }
}
