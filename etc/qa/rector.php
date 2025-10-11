<?php

declare(strict_types=1);

use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use WyriHaximus\TestUtilities\RectorConfig;

return RectorConfig::configure(dirname(__DIR__, 2))->withSkip([
    AddArrowFunctionReturnTypeRector::class,
]);
