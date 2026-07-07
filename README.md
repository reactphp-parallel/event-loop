# Event Loop bridge to ext-parallel Events

![Continuous Integration](https://github.com/Reactphp-parallel/event-loop/workflows/Continuous%20Integration/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/React-parallel/event-loop/v/stable.png)](https://packagist.org/packages/React-parallel/event-loop)
[![Total Downloads](https://poser.pugx.org/React-parallel/event-loop/downloads.png)](https://packagist.org/packages/React-parallel/event-loop)
[![Type Coverage](https://shepherd.dev/github/Reactphp-parallel/event-loop/coverage.svg)](https://shepherd.dev/github/Reactphp-parallel/event-loop)
[![License](https://poser.pugx.org/React-parallel/event-loop/license.png)](https://packagist.org/packages/React-parallel/event-loop)

### Installation ###

To install via [Composer](http://getcomposer.org/), use the command below, it will automatically detect the latest version and bind it with `~`.

```
composer require react-parallel/event-loop
```

# Usage

## Set up

Just like the ReactPHP event loop, you should only have one bridge. You can have multiple, and unlike the ReactPHP
event loop, that will work, but it adds additional overhead when you have more than a few. Having a hand full for
different major contexts. Share this bridge around so that other packages can use them, and only have one instance
checking for events.

## Channels

Channels often have a stream of messages going over them, as such the bridge will convert them into an observable.

```php
use parallel\Channel;
use React\EventLoop\Loop;
use ReactParallel\EventLoop\EventLoopBridge;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\sleep;

$eventLoopBridge = new EventLoopBridge();

Loop::futureTick(async(static function () use ($eventLoopBridge) {
    /** @var Channel<string> */
    $channel = new Channel(Channel::Infinite);

    Loop::futureTick(async(function () use ($channel): void {
        $channel->send('Hello World!');
        // Don't close the channel right after writing to it,
        // as it will be closed on both ends and the other
        // thread won't receive your message
        await(sleep(1));
        $channel->close();
    }));

    foreach ($eventLoopBridge->observe($channel) as $message) {
        echo $message, PHP_EOL;
    }
}));
```

## Futures

Where promises are push, futures are pull, as such the event loop will poll and resolve the promise once a result is
available.

```php
use React\EventLoop\Loop;
use ReactParallel\EventLoop\EventLoopBridge;
use function parallel\run;
use function React\Async\async;

$eventLoopBridge = new EventLoopBridge();

Loop::futureTick(async(static function () use ($eventLoopBridge) {
    $future = run(function (): string {
        return 'Hello World!';
    });

    echo $eventLoopBridge->await($future), PHP_EOL;
}));
```

## Metrics

This package supports metrics through [`wyrihaximus/metrics`](https://github.com/wyrihaximus/php-metrics):

```php
use React\EventLoop\Factory;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\EventLoop\Metrics;
use WyriHaximus\Metrics\Configuration;
use WyriHaximus\Metrics\InMemory\Registry;

$loop = Factory::create();
$eventLoopBridge = (new EventLoopBridge($loop))->withMetrics(Metrics::create(new Registry(Configuration::create())));
```

## Contributing ##

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License ##

Copyright 2026 [Cees-Jan Kiewiet](http://wyrihaximus.net/)

Permission is hereby granted, free of charge, to any person
obtaining a copy of this software and associated documentation
files (the "Software"), to deal in the Software without
restriction, including without limitation the rights to use,
copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the
Software is furnished to do so, subject to the following
conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
OTHER DEALINGS IN THE SOFTWARE.
