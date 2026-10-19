<?php

use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;

/**
 * A site without its own settings row reads every non-credential key from the
 * default row Settings::lookupForSite() uses. Credentials are never copied into
 * the row-less site's instance; they resolve only through lookupForSite.
 */
final class SettingsSiteFallbackTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        $this->app->instance(
            HostIndexResolver::class,
            new HostIndexResolver(__DIR__.'/../../fixtures/data/test_psl.dat')
        );
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites(
            $this->app['db']->connection()->getSchemaBuilder(),
            $this->app['db']->connection(),
        );
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $arValue */
    private function insertRow(?int $iSiteId, array $arValue): void
    {
        DB::table('system_settings')->insert([
            'item' => 'logingrupa_metapixel_settings',
            'site_id' => $iSiteId,
            'value' => json_encode($arValue),
        ]);
    }

    private function insertSiteOneRow(): void
    {
        $this->insertRow(1, [
            'pixel_id' => '1234567890',
            'capi_access_token' => 'EAAB1',
            'default_currency_code' => 'EUR',
            'test_event_code' => 'TEST1',
            'paid_status_code' => ['complete'],
            'trusted_hosts' => 'example.com',
        ]);
    }

    private function readOnSite(int $iSiteId, string $sKey, mixed $mDefault = null): mixed
    {
        return Site::withContext($iSiteId, static function () use ($sKey, $mDefault): mixed {
            Settings::clearInternalCache();

            return Settings::get($sKey, $mDefault);
        });
    }

    public function test_row_less_site_reads_the_default_row_settings(): void
    {
        $this->insertSiteOneRow();

        $this->assertSame('EUR', $this->readOnSite(2, 'default_currency_code'));
        $this->assertSame('TEST1', $this->readOnSite(2, 'test_event_code'));
        $this->assertSame(['complete'], $this->readOnSite(2, 'paid_status_code'));
    }

    public function test_row_less_site_instance_carries_no_credentials(): void
    {
        $this->insertSiteOneRow();

        [$mPixel, $mToken] = Site::withContext(2, static function (): array {
            Settings::clearInternalCache();
            $obSettings = Settings::instance();

            return [$obSettings->getAttribute('pixel_id'), $obSettings->getAttribute('capi_access_token')];
        });

        $this->assertNull($mPixel);
        $this->assertNull($mToken);
    }

    public function test_saving_a_row_less_site_stores_empty_credentials_and_keeps_the_default_ones(): void
    {
        $this->insertSiteOneRow();

        Site::withContext(2, static function (): void {
            Settings::clearInternalCache();
            Settings::set(['test_event_code' => 'T2']);
        });
        Settings::clearInternalCache();

        $mValue = DB::table('system_settings')->where('site_id', 2)->value('value');
        $this->assertIsString($mValue);
        $arValue = json_decode($mValue, true);
        $this->assertIsArray($arValue);
        $this->assertEmpty($arValue['pixel_id'] ?? null);
        $this->assertEmpty($arValue['capi_access_token'] ?? null);
        $this->assertSame('EUR', $arValue['default_currency_code'] ?? null);
        $this->assertSame('T2', $arValue['test_event_code'] ?? null);
        $this->assertSame('example.com', $arValue['trusted_hosts'] ?? null);
        $this->assertSame(
            ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1'],
            Settings::lookupForSite(2)
        );
    }

    public function test_own_site_row_wins_without_a_per_key_merge(): void
    {
        $this->insertSiteOneRow();
        $this->insertRow(2, ['default_currency_code' => 'NOK']);

        $this->assertSame('NOK', $this->readOnSite(2, 'default_currency_code'));
        $this->assertNull($this->readOnSite(2, 'test_event_code'));
    }

    public function test_null_site_row_with_credentials_is_the_default(): void
    {
        $this->insertSiteOneRow();
        $this->insertRow(null, ['pixel_id' => '9876543210', 'default_currency_code' => 'USD']);

        $this->assertSame('USD', $this->readOnSite(2, 'default_currency_code'));
    }

    public function test_no_rows_returns_the_caller_default(): void
    {
        $this->assertSame('XYZ', $this->readOnSite(2, 'default_currency_code', 'XYZ'));
    }
}
