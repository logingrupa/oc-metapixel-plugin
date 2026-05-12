<?php

/*
|--------------------------------------------------------------------------
| Test Case Binding
|--------------------------------------------------------------------------
|
| Bind MetapixelTestCase to every test under tests/Unit and tests/Feature so
| each Pest test boots October's harness (bootstrap/app.php) and the in-memory
| SQLite migration stack via PerformsMigrations.
|
*/

uses(Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase::class)->in('Unit', 'Feature');
