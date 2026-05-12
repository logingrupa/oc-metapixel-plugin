<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Sanity test — proves the October harness fires.
 *
 * Extends MetapixelTestCase directly (a plain PHPUnit test class) so PHPUnit's
 * lifecycle runs setUp() → createApplication() → kernel bootstrap → migrate +
 * loadCurrentPlugin, and tearDown() restores handlers + globals automatically.
 *
 * Pest's `uses(...)->in('Unit')` binding is fragile here because pest computes
 * `$rootPath = dirname($autoloadPath, 2)` which lands at the repo root (the
 * shared vendor lives there), while Pest.php lives in the plugin's tests/ dir.
 * Until Phase 2 (SKEL-01) wires a repo-level test harness, this single sanity
 * test bypasses pest's `it()` DSL and uses PHPUnit's `extends` model directly,
 * which works correctly under both phpunit and pest invocation paths.
 */
final class SanityTest extends MetapixelTestCase
{
    public function test_boots_the_october_harness(): void
    {
        $this->assertNotNull($this->app, 'October harness must populate $this->app via createApplication().');
        $this->assertTrue(Schema::hasTable('system_settings'), 'System migrations must have populated the system_settings table.');
    }
}
