<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Plugin.php',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSkip([
        __DIR__ . '/lang',
        __DIR__ . '/updates',
        __DIR__ . '/tests',
        __DIR__ . '/partials',
        __DIR__ . '/.github',
    ]);
