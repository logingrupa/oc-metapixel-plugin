<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;

/**
 * Wave 0 RED — fails until plan 04-01 ships.
 *
 * MULT-03 / D-01: lookupForSite(?int) returns per-site row when set, with
 * silent default-row fallback for empty/null per-site values. Phase 2 stub
 * is replaced; this file covers the live Phase 4 lookup contract.
 */
final class SettingsLookupForSiteTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        // Seed 2-site fixture so Site::withContext(1, fn) + Site::withContext(2, fn) resolve.
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites($this->app['db']->connection()->getSchemaBuilder(), $this->app['db']->connection());
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        parent::tearDown();
    }

    public function test_lookup_for_site_null_returns_default_row(): void
    {
        Settings::set([
            'pixel_id' => 'DEFAULT_PIXEL',
            'capi_access_token' => 'DEFAULT_TOKEN',
        ]);

        $arResult = Settings::lookupForSite(null);

        $this->assertSame(
            ['pixel_id' => 'DEFAULT_PIXEL', 'capi_access_token' => 'DEFAULT_TOKEN'],
            $arResult
        );
    }

    public function test_lookup_for_site_with_id_returns_per_site_row(): void
    {
        // Default-row credentials (global context).
        Settings::set([
            'pixel_id' => 'DEFAULT_PIXEL',
            'capi_access_token' => 'DEFAULT_TOKEN',
        ]);

        // Per-site row for site 1.
        Site::withContext(1, static function (): void {
            Settings::clearInternalCache();
            Settings::set([
                'pixel_id' => 'SITE1_PIXEL',
                'capi_access_token' => 'SITE1_TOKEN',
            ]);
        });
        Settings::clearInternalCache();

        $arResult = Settings::lookupForSite(1);

        $this->assertSame(
            ['pixel_id' => 'SITE1_PIXEL', 'capi_access_token' => 'SITE1_TOKEN'],
            $arResult
        );
    }

    public function test_lookup_for_site_empty_per_site_pixel_falls_back_to_default(): void
    {
        Settings::set([
            'pixel_id' => 'DEFAULT_PIXEL',
            'capi_access_token' => 'DEFAULT_TOKEN',
        ]);

        Site::withContext(1, static function (): void {
            Settings::clearInternalCache();
            Settings::set([
                'pixel_id' => '',
                'capi_access_token' => 'SITE1_TOKEN',
            ]);
        });
        Settings::clearInternalCache();

        $arResult = Settings::lookupForSite(1);

        $this->assertSame('DEFAULT_PIXEL', $arResult['pixel_id'], 'D-01: empty per-site pixel_id falls back to default.');
        $this->assertSame('SITE1_TOKEN', $arResult['capi_access_token']);
    }

    public function test_lookup_for_site_null_per_site_token_falls_back_to_default(): void
    {
        Settings::set([
            'pixel_id' => 'DEFAULT_PIXEL',
            'capi_access_token' => 'DEFAULT_TOKEN',
        ]);

        Site::withContext(1, static function (): void {
            Settings::clearInternalCache();
            Settings::set([
                'pixel_id' => 'SITE1_PIXEL',
                'capi_access_token' => null,
            ]);
        });
        Settings::clearInternalCache();

        $arResult = Settings::lookupForSite(1);

        $this->assertSame('SITE1_PIXEL', $arResult['pixel_id']);
        $this->assertSame('DEFAULT_TOKEN', $arResult['capi_access_token'], 'D-01: null per-site token falls back to default.');
    }

    public function test_lookup_for_site_return_shape_is_array_with_two_keys(): void
    {
        Settings::set([
            'pixel_id' => 'P',
            'capi_access_token' => 'T',
        ]);

        $arResult = Settings::lookupForSite(null);

        $this->assertSame(['pixel_id', 'capi_access_token'], array_keys($arResult));
        $this->assertIsString($arResult['pixel_id']);
        $this->assertIsString($arResult['capi_access_token']);
    }
}
