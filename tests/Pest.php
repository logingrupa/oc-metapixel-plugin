<?php

use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/*
|--------------------------------------------------------------------------
| Test Case Binding
|--------------------------------------------------------------------------
|
| Bind MetapixelTestCase to every test under tests/Unit and tests/Feature so
| each Pest test boots October's harness (bootstrap/app.php) and the in-memory
| SQLite migration stack via PerformsMigrations.
|
| Note (Phase 1): Pest computes `$rootPath = dirname($autoloadPath, 2)` which
| lands at the repo root, so this Pest.php is currently a no-op under the
| `../../../vendor/bin/pest --configuration phpunit.xml` invocation. The
| sanity test in tests/Unit/SanityTest.php extends MetapixelTestCase directly
| via PHPUnit's classic `extends` model to work around this. Phase 2 (SKEL-01)
| revisits the binding once a repo-level test harness lands.
|
*/

if (function_exists('uses') && class_exists(MetapixelTestCase::class, false)) {
    uses(MetapixelTestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature');
}
