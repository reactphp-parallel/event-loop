<?php

declare(strict_types=1);

use parallel\Channel;
use ReactParallel\EventLoop\EventLoopBridge;

use function parallel\run;
use function PHPStan\Testing\assertType;

$bridge = new EventLoopBridge();

/**
 * Observe
 */

/** @var Channel<bool> $channelBool */
$channelBool = new Channel();

/** @var Channel<stdClass> $channelStd */
$channelStd = new Channel();

assertType('iterable<bool>', $bridge->observe($channelBool));
assertType('iterable<stdClass>', $bridge->observe($channelStd));

/**
 * Await
 */
assertType('static-Closure(): void', (static fn () => $bridge->await(run(static function (): void {
    sleep(1);
}))));

assertType('static-Closure(): void', (static fn () => $bridge->await(run(static function (int $time): void {
    sleep($time);
}, [1]))));

assertType('bool', $bridge->await(run(static fn (): bool => true)));

assertType('int<1, max>|true', $bridge->await(run(static fn (): bool|int => time() % 2 !== 0 ? true : time())));

assertType('int<1, max>|true', $bridge->await(run(static fn (int $mod): bool|int => time() % $mod !== 0 ? true : time(), [2])));

assertType('bool|int<1, max>', $bridge->await(run(static fn (int $mod, bool $yolo): bool|int => time() % $mod !== 0 ? $yolo : time(), [2, (time() % 13 !== 0)])));

assertType('bool|non-empty-string', $bridge->await(run(static fn (int $mod, bool $yolo, string $oloy): bool|string => time() % $mod !== 0 ? $yolo : $oloy, [2, (time() % 13 !== 0), bin2hex(random_bytes(13))])));
