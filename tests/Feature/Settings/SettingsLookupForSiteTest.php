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
        // The migrations table + Manifest::put('database.check', true) come from
        // MetapixelTestCase::ensureMigrationsTableForHasDatabaseProbe so the
        // Multisite-aware lookupForSite reads the per-site row via DB on each call.
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites(
            $this->app['db']->connection()->getSchemaBuilder(),
            $this->app['db']->connection(),
        );
        // The system.helper / system.manifest instances are rebuilt per-test via
        // createApplication(); re-pin the hasDatabase probe here too so the
        // 'global context' read inside lookupForSite gets a fresh DB hit even
        // when the framework rebinds the helper mid-test.
        Manifest::put('database.check', true);
        $obSystem = $this->app->make('system.helper');
        $obReflect = new ReflectionObject($obSystem);
        if ($obReflect->hasProperty('hasDatabaseCache')) {
            $obProp = $obReflect->getProperty('hasDatabaseCache');
            $obProp->setAccessible(true);
            $obProp->setValue($obSystem, true);
        }
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        parent::tearDown();
    }

    /**
     * Seed the default row inside Site::withGlobalContext so Multisite's
     * beforeSave does NOT stamp site_id. The resulting row has site_id=null
     * and is the one Settings::lookupForSite(null) reads.
     */
    private function seedDefaultRow(string $sPixel, ?string $sToken): void
    {
        Site::withGlobalContext(static function () use ($sPixel, $sToken): void {
            Settings::clearInternalCache();
            Settings::set([
                'pixel_id' => $sPixel,
                'capi_access_token' => $sToken,
            ]);
        });
        Settings::clearInternalCache();
    }

    /**
     * Seed a per-site row inside Site::withContext($iSiteId, ...). beforeSave
     * stamps site_id=$iSiteId. lookupForSite($iSiteId) reads this row, with
     * silent fallback to the default-row for empty/null values.
     */
    private function seedPerSiteRow(int $iSiteId, ?string $sPixel, ?string $sToken): void
    {
        Site::withContext($iSiteId, static function () use ($sPixel, $sToken): void {
            Settings::clearInternalCache();
            Settings::set([
                'pixel_id' => $sPixel,
                'capi_access_token' => $sToken,
            ]);
        });
        Settings::clearInternalCache();
    }

    public function test_lookup_for_site_null_returns_default_row(): void
    {
        $this->seedDefaultRow('DEFAULT_PIXEL', 'DEFAULT_TOKEN');

        $arResult = Settings::lookupForSite(null);

        $this->assertSame(
            ['pixel_id' => 'DEFAULT_PIXEL', 'capi_access_token' => 'DEFAULT_TOKEN'],
            $arResult
        );
    }

    public function test_lookup_for_site_with_id_returns_per_site_row(): void
    {
        $this->seedDefaultRow('DEFAULT_PIXEL', 'DEFAULT_TOKEN');
        $this->seedPerSiteRow(1, 'SITE1_PIXEL', 'SITE1_TOKEN');

        $arResult = Settings::lookupForSite(1);

        $this->assertSame(
            ['pixel_id' => 'SITE1_PIXEL', 'capi_access_token' => 'SITE1_TOKEN'],
            $arResult
        );
    }

    public function test_lookup_for_site_empty_per_site_pixel_falls_back_to_default(): void
    {
        $this->seedDefaultRow('DEFAULT_PIXEL', 'DEFAULT_TOKEN');
        $this->seedPerSiteRow(1, '', 'SITE1_TOKEN');

        $arResult = Settings::lookupForSite(1);

        $this->assertSame('DEFAULT_PIXEL', $arResult['pixel_id'], 'D-01: empty per-site pixel_id falls back to default.');
        $this->assertSame('SITE1_TOKEN', $arResult['capi_access_token']);
    }

    public function test_lookup_for_site_null_per_site_token_falls_back_to_default(): void
    {
        $this->seedDefaultRow('DEFAULT_PIXEL', 'DEFAULT_TOKEN');
        $this->seedPerSiteRow(1, 'SITE1_PIXEL', null);

        $arResult = Settings::lookupForSite(1);

        $this->assertSame('SITE1_PIXEL', $arResult['pixel_id']);
        $this->assertSame('DEFAULT_TOKEN', $arResult['capi_access_token'], 'D-01: null per-site token falls back to default.');
    }

    public function test_lookup_for_site_return_shape_is_array_with_two_keys(): void
    {
        $this->seedDefaultRow('P', 'T');

        $arResult = Settings::lookupForSite(null);

        $this->assertSame(['pixel_id', 'capi_access_token'], array_keys($arResult));
        $this->assertIsString($arResult['pixel_id']);
        $this->assertIsString($arResult['capi_access_token']);
    }
}
