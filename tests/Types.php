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
assertType('bool', $bridge->await(run(static function (): bool {
    return true;
})));

assertType('bool|string', $bridge->await(run(static function (): bool|string {
    return time() % 2 !== 0 ? true : 'hammer';
})));

assertType('null', $bridge->await(run(static function (): void {
})));
