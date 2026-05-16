<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$obConfig = new Configuration;

// Production-code scan paths. Filtered through file_exists so the empty
// scaffold does not fail on missing subdirectories.
foreach (['/Plugin.php', '/classes', '/models', '/components', '/middleware', '/controllers'] as $sPath) {
    if (file_exists(__DIR__.$sPath)) {
        $obConfig->addPathToScan(__DIR__.$sPath, isDev: false);
    }
}

if (file_exists(__DIR__.'/tests')) {
    $obConfig->addPathToScan(__DIR__.'/tests', isDev: true);
}

foreach (['/lang', '/updates', '/.planning', '/.github'] as $sPath) {
    if (file_exists(__DIR__.$sPath)) {
        $obConfig->addPathToExclude(__DIR__.$sPath);
    }
}

// Lovata cart plugins live in composer "suggest" (production) and "require-dev"
// (test suite). The analyser would flag them as dev-deps used in prod if any
// src file outside the adapter directory imports them.
$obConfig->ignoreErrorsOnPackage('lovata/shopaholic-plugin', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
$obConfig->ignoreErrorsOnPackage('lovata/ordersshopaholic-plugin', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
$obConfig->ignoreErrorsOnPackage('lovata/buddies-plugin', [ErrorType::DEV_DEPENDENCY_IN_PROD]);

// The adapter directory IS allowed to import Lovata cart classes.
// Phase 3 lands classes/adapter/shopaholic/; this pre-wires the allowlist.
$obConfig->ignoreErrorsOnPackageAndPath(
    'lovata/shopaholic-plugin',
    __DIR__.'/classes/adapter/shopaholic',
    [ErrorType::DEV_DEPENDENCY_IN_PROD],
);
$obConfig->ignoreErrorsOnPackageAndPath(
    'lovata/ordersshopaholic-plugin',
    __DIR__.'/classes/adapter/shopaholic',
    [ErrorType::DEV_DEPENDENCY_IN_PROD],
);

// Dev tooling — referenced via composer scripts, not imported.
foreach ([
    'shipmonk/composer-dependency-analyser',
    'spaze/phpstan-disallowed-calls',
    'larastan/larastan',
    'phpmd/phpmd',
    'laravel/pint',
    'rector/rector',
    'mockery/mockery',
    'pestphp/pest',
    'pestphp/pest-plugin-drift',
    'phpunit/phpunit',
] as $sDevPackage) {
    $obConfig->ignoreErrorsOnPackage($sDevPackage, [ErrorType::UNUSED_DEPENDENCY]);
}

return $obConfig;
