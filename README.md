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

```php
use React\EventLoop\Factory;
use ReactParallel\EventLoop\EventLoopBridge;

$loop = Factory::create();
$eventLoopBridge = new EventLoopBridge($loop);

$loop->run();
```

## Channels

Channels often have a stream of messages going over them, as such the bridge will convert them into an observable.

```php
use parallel\Channel;
use React\EventLoop\Factory;
use ReactParallel\EventLoop\EventLoopBridge;

$loop = Factory::create();
$eventLoopBridge = new EventLoopBridge($loop);

$channel = new Channel(Channel::Infinite);
$eventLoopBridge->observe($channel)->subscribe(function (string $message) {
    echo $message, PHP_EOL;
});

$loop->futureTick(function () use ($channel): void {
    $channel->send('Hello World!');
    $channel->close();
});

$loop->run();
```

## Futures

Where promises are push, futures are pull, as such the event loop will poll and resolve the promise once a result is
available.

```php
use parallel\Channel;
use React\EventLoop\Factory;
use ReactParallel\EventLoop\EventLoopBridge;
use function parallel\run;

$loop = Factory::create();
$eventLoopBridge = new EventLoopBridge($loop);

$future = run(function (): string {
    return 'Hello World!';
});

$channel = new Channel(Channel::Infinite);
$eventLoopBridge->await($future)->then(function (string $message) {
    echo $message, PHP_EOL;
});

$loop->run();
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

Copyright 2024 [Cees-Jan Kiewiet](http://wyrihaximus.net/)

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
