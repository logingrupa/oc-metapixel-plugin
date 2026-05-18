<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;

/*
|--------------------------------------------------------------------------
| Test Case Bindings
|--------------------------------------------------------------------------
|
| MetapixelTestCase: every test under tests/Unit and tests/Feature that does
| not live under an Adapter/<cart> subdirectory.
|
| ShopaholicAdapterTestCase: every test under tests/Unit/Adapter/Shopaholic
| and tests/Feature/Adapter/Shopaholic. Only loaded in CI Run A (full-lovata
| install). Run B (minimal install) excludes adapter tests via
| --exclude-group=adapter (see Adapter Group block below).
|
*/

if (function_exists('uses') && class_exists(MetapixelTestCase::class, false)) {
    uses(MetapixelTestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature');
}

if (function_exists('uses') && class_exists(ShopaholicAdapterTestCase::class, false)) {
    uses(ShopaholicAdapterTestCase::class)->in(
        __DIR__.'/Unit/Adapter/Shopaholic',
        __DIR__.'/Feature/Adapter/Shopaholic',
    );
}

/*
|--------------------------------------------------------------------------
| Adapter Group
|--------------------------------------------------------------------------
|
| Adapter tests are tagged with #[Group('adapter')] on each class (native
| PHPUnit 12 attribute). CI matrix selection:
|   - Full-lovata cell:    `pest`
|   - Minimal-install cell: `pest --exclude-group=adapter`
|
| Pest's pest()->group()->in() only tags Pest-style closures (test() / it()),
| not class-based PHPUnit tests that extend TestCase. Group attribute on the
| test class is the framework-native way for both runners.
|
| Replaces the prior phpunit.xml "Metapixel Adapter Tests" testsuite, which
| caused PHPUnit 12 / Pest 4 to emit "Cannot add file ... already added to
| test suite" warnings on overlapping testsuite directories.
|
*/
