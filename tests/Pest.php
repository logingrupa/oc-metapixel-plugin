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
| install). Run B (minimal install) excludes the Adapter subdirectory via
| --testsuite selection.
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
