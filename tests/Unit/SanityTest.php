<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Sanity test — proves the October harness fires.
 *
 * Extends MetapixelTestCase directly (a plain PHPUnit test class) so PHPUnit's
 * lifecycle runs setUp() → createApplication() → tearDown restoring handlers
 * + globals automatically.
 *
 * Pest's `uses(...)->in('Unit')` binding is fragile here because pest computes
 * `$rootPath = dirname($autoloadPath, 2)` which lands at the repo root (the
 * shared vendor lives there), while Pest.php lives in the plugin's tests/ dir.
 * Until Phase 2 wires a repo-level test harness, this single sanity test
 * bypasses pest's `it()` DSL and uses PHPUnit's `extends` model directly.
 *
 * Phase 2 Plan 02-01 update: MetapixelTestCase now defaults `$autoMigrate=false`
 * (running the full Lovata.Shopaholic + OrdersShopaholic migration chain on
 * SQLite-in-memory is prohibitively slow + unreliable). createApplication()
 * now provisions `system_settings` hermetically AND forces the sqlite
 * connection programmatically because Laravel's `.env` loader otherwise
 * overrides PHPUnit's `<env force="true">` directives and routes queries to
 * production MySQL.
 */
final class SanityTest extends MetapixelTestCase
{
    public function test_boots_the_october_harness(): void
    {
        $this->assertNotNull($this->app, 'October harness must populate $this->app via createApplication().');
        $this->assertTrue(Schema::hasTable('system_settings'), 'Hermetic system_settings table must exist after createApplication().');
    }
}
