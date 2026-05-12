<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Plugin;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Feature test covering SKEL-05's three invariants:
 *
 *   1. Booting with empty `pixel_id` logs Log::warning(
 *      'Metapixel: pixel_id not configured — plugin disabled')
 *      and does NOT throw.
 *   2. PluginGuard::instance()->isDisabled() === true when Settings
 *      pixel_id is empty; getPixelId() === null.
 *   3. PluginGuard::instance()->isDisabled() === false when pixel_id
 *      is populated; getPixelId() returns the stored value;
 *      App::make('metapixel.disabled') reflects the same boolean
 *      (container-singleton bridge contract for Phase 3+ handlers).
 *
 * Extends MetapixelTestCase directly per the proven `extends` model in
 * SanityTest and SettingsRegistrationTest (Pest's `uses()->in()` binding
 * is currently flaky — see tests/Pest.php comment).
 */
final class BootsWithoutPixelIdTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // system_settings is provisioned in MetapixelTestCase::createApplication.
        // Reset per-test memo so each test starts from a clean slate.
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();
    }

    public function test_boot_with_empty_pixel_id_logs_warning_and_does_not_throw(): void
    {
        Settings::set('pixel_id', '');
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();

        Log::spy();

        try {
            (new Plugin($this->app))->boot();
        } catch (\Throwable $obException) {
            $this->fail('Plugin::boot() must not throw when pixel_id is empty: '.$obException->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->atLeast()
            ->once()
            ->withArgs(fn ($sMsg) => is_string($sMsg) && str_contains($sMsg, 'pixel_id not configured'));

        $this->assertTrue(PluginGuard::instance()->isDisabled(), 'PluginGuard must report disabled when pixel_id is empty.');
    }

    public function test_isDisabled_returns_true_when_pixel_id_empty(): void
    {
        Settings::set('pixel_id', '');
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();

        $obGuard = PluginGuard::instance();

        $this->assertTrue($obGuard->isDisabled(), 'isDisabled() must return true when pixel_id is empty.');
        $this->assertNull($obGuard->getPixelId(), 'getPixelId() must return null when pixel_id is empty.');
    }

    public function test_isDisabled_returns_false_when_pixel_id_populated(): void
    {
        Settings::set('pixel_id', '2291486191076331');
        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();

        $obGuard = PluginGuard::instance();

        $this->assertFalse($obGuard->isDisabled(), 'isDisabled() must return false when pixel_id is populated.');
        $this->assertSame('2291486191076331', $obGuard->getPixelId(), 'getPixelId() must return the stored Settings value.');
        $this->assertFalse(
            (bool) App::make('metapixel.disabled'),
            'Container-singleton bridge metapixel.disabled must resolve to false when guard is enabled.'
        );
    }
}
