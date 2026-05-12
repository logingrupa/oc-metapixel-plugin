<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths(array_filter([
        __DIR__.'/Plugin.php',
        __DIR__.'/classes',
        __DIR__.'/models',
        __DIR__.'/components',
        __DIR__.'/middleware',
        __DIR__.'/controllers',
        __DIR__.'/console',
    ], static fn (string $sPath): bool => file_exists($sPath)))
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSkip([
        __DIR__.'/lang',
        __DIR__.'/updates',
        __DIR__.'/tests',
        __DIR__.'/partials',
        __DIR__.'/.github',
    ]);
