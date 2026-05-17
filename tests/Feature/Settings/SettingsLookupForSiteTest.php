<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class SettingsLookupForSiteTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        // SettingModel keeps a static $instances cache that survives between
        // tests. Clear it so each test sees a fresh resolved instance backed
        // by the per-test in-memory SQLite DB.
        Settings::clearInternalCache();
    }

    public function test_lookup_for_site_null_returns_stored_credentials(): void
    {
        Settings::set([
            'pixel_id' => 'X',
            'capi_access_token' => 'Y',
        ]);

        $arResult = Settings::lookupForSite(null);

        $this->assertSame(['pixel_id' => 'X', 'capi_access_token' => 'Y'], $arResult);
    }

    public function test_lookup_for_site_with_id_returns_same_as_null_in_phase_2_stub(): void
    {
        Settings::set([
            'pixel_id' => 'X',
            'capi_access_token' => 'Y',
        ]);

        $arForNull = Settings::lookupForSite(null);
        $arForSite7 = Settings::lookupForSite(7);

        $this->assertSame($arForNull, $arForSite7, 'Phase 2 stub ignores $iSiteId; Phase 4 MULT-03 changes this.');
    }

    public function test_lookup_for_site_returns_empty_strings_when_unset(): void
    {
        $arResult = Settings::lookupForSite(null);

        $this->assertSame(['pixel_id' => '', 'capi_access_token' => ''], $arResult);
    }
}
