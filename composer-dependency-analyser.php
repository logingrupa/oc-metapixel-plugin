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

// Lovata cart imports allowed ONLY inside the Shopaholic adapter + matching
// event-watcher directories. Any import outside them raises DEV_DEPENDENCY_IN_PROD
// (Lovata cart packages are require-dev only). Phase 3 plan 03-02 widens the
// previous adapter-only whitelist to also cover classes/event/adapter/shopaholic
// where the OrderStatusWatcher (and future CartPositionWatcher) live.
$arLovataPaths = [
    __DIR__.'/classes/adapter/shopaholic',
    __DIR__.'/classes/event/adapter/shopaholic',
];
foreach ([
    'lovata/shopaholic-plugin',
    'lovata/ordersshopaholic-plugin',
    'lovata/buddies-plugin',
] as $sLovataPackage) {
    foreach ($arLovataPaths as $sLovataPath) {
        $obConfig->ignoreErrorsOnPackageAndPath(
            $sLovataPackage,
            $sLovataPath,
            [ErrorType::DEV_DEPENDENCY_IN_PROD],
        );
    }

    // Plugin.php imports Lovata\OrdersShopaholic\Models\{CartPosition,Order} in the AdapterRegistry
    // registration block. Path-scope the file directly so only Plugin.php at the plugin root is
    // allowlisted — sibling files (updates/*, config/*) stay under the analyser's lens.
    $obConfig->ignoreErrorsOnPackageAndPath(
        $sLovataPackage,
        __DIR__.'/Plugin.php',
        [ErrorType::DEV_DEPENDENCY_IN_PROD],
    );
}

// Dev tooling — referenced via composer scripts, not imported. Guard each ignore
// on actual presence in composer.json: CI installs the plugin vendor without
// rector (its scope-prefixed vendor stubs fatal the analyser's reflection pass),
// and an ignore registered for an absent package is reported as an unmatched
// ignore, failing the run.
$arComposerJson = json_decode((string) file_get_contents(__DIR__.'/composer.json'), true);
$arDeclaredDevPackages = array_keys($arComposerJson['require-dev'] ?? []);
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
    if (! in_array($sDevPackage, $arDeclaredDevPackages, true)) {
        continue;
    }

    $obConfig->ignoreErrorsOnPackage($sDevPackage, [ErrorType::UNUSED_DEPENDENCY]);
}

return $obConfig;
