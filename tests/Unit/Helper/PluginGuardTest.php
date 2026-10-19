<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;
use PHPUnit\Framework\Attributes\DataProvider;

final class PluginGuardTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        PluginGuard::reset();
        Cache::forget(PluginGuard::WARNING_CACHE_KEY);
    }

    protected function tearDown(): void
    {
        PluginGuard::reset();
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        parent::tearDown();
    }

    public function test_is_disabled_returns_true_when_pixel_id_is_empty(): void
    {
        Settings::set(['pixel_id' => '']);
        Log::shouldReceive('warning')->once();

        $this->assertTrue(PluginGuard::isDisabled());
    }

    public function test_is_disabled_returns_false_when_pixel_id_is_set(): void
    {
        Settings::set(['pixel_id' => '1234567890']);

        $this->assertFalse(PluginGuard::isDisabled());
    }

    public function test_reset_clears_the_memo(): void
    {
        Settings::set(['pixel_id' => '1234567890']);
        PluginGuard::isDisabled();

        Settings::set(['pixel_id' => '']);
        $this->assertFalse(PluginGuard::isDisabled(), 'memoised value wins until reset');

        PluginGuard::reset();
        Log::shouldReceive('warning')->once();
        $this->assertTrue(PluginGuard::isDisabled());
    }

    public function test_warning_fires_once_per_day_across_requests(): void
    {
        Settings::set(['pixel_id' => '']);
        Log::shouldReceive('warning')->once();

        PluginGuard::isDisabled();
        PluginGuard::reset();
        PluginGuard::isDisabled();
        PluginGuard::reset();
        $this->assertTrue(PluginGuard::isDisabled());
    }

    public function test_warning_fires_again_after_the_throttle_expires(): void
    {
        Settings::set(['pixel_id' => '']);
        Log::shouldReceive('warning')->twice();

        PluginGuard::isDisabled();
        Cache::forget(PluginGuard::WARNING_CACHE_KEY);
        PluginGuard::reset();
        $this->assertTrue(PluginGuard::isDisabled());
    }

    public function test_each_site_gets_its_own_answer_in_one_request(): void
    {
        $this->insertRow(1, ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1']);
        $this->insertRow(2, ['pixel_id' => 'owner@example.com', 'capi_access_token' => 'EAAB1']);
        Log::shouldReceive('warning')->once();

        $this->assertFalse(PluginGuard::isDisabledForSite(1));
        $this->assertTrue(PluginGuard::isDisabledForSite(2));
        $this->assertFalse(PluginGuard::isDisabledForSite(1));
    }

    public function test_site_rows_without_credentials_inherit_the_default_row(): void
    {
        $this->insertRow(1, ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1']);
        $this->insertRow(2, ['account_identity_enabled' => true, 'account_phone_dial_code' => '371']);
        $this->insertRow(3, ['account_identity_enabled' => true, 'account_phone_dial_code' => '371']);

        $this->assertFalse(PluginGuard::isDisabledForSite(2));
        $this->assertFalse(PluginGuard::isDisabledForSite(3));
    }

    public function test_is_disabled_follows_the_applied_site(): void
    {
        $this->seedSites();
        $this->insertRow(1, ['pixel_id' => '', 'capi_access_token' => '']);
        $this->insertRow(2, ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1']);
        Log::shouldReceive('warning')->once();

        $this->assertTrue(PluginGuard::isDisabled(), 'the primary site row has no pixel');

        Site::applyActiveSiteId(2);

        $this->assertFalse(PluginGuard::isDisabled(), 'the applied site row has a valid pixel');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function credentialProvider(): array
    {
        return [
            'email pixel and password token' => ['owner@example.com', 'hunter2hunter2', true],
            'valid pixel and password token' => ['1234567890', 'hunter2', true],
            'valid pixel without token' => ['1234567890', '', false],
            'valid pixel and token' => ['1234567890', 'EAAB1', false],
        ];
    }

    #[DataProvider('credentialProvider')]
    public function test_malformed_credentials_disable_the_plugin(string $sPixelId, string $sToken, bool $bExpected): void
    {
        $this->insertRow(null, ['pixel_id' => $sPixelId, 'capi_access_token' => $sToken]);
        Log::shouldReceive('warning')->zeroOrMoreTimes()->withArgs(static function (string $sMessage) use ($sPixelId, $sToken): bool {
            return ! str_contains($sMessage, $sPixelId) && ($sToken === '' || ! str_contains($sMessage, $sToken));
        });

        $this->assertSame($bExpected, PluginGuard::isDisabled());
    }

    public function test_backend_context_disables_the_context_guard_only(): void
    {
        $this->insertRow(null, ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1']);
        $this->app->instance('execution.context', 'backend');

        $this->assertTrue(PluginGuard::isDisabled());
        $this->assertFalse(PluginGuard::isDisabledForSite(1));
    }

    public function test_warning_names_the_default_row_or_the_site(): void
    {
        $this->insertRow(null, ['pixel_id' => 'owner@example.com']);
        $this->insertRow(2, ['pixel_id' => 'abc123']);
        Log::shouldReceive('warning')->once()->withArgs(static fn (string $sMessage): bool => str_starts_with($sMessage, 'metapixel: default row disabled'));
        Log::shouldReceive('warning')->once()->withArgs(static fn (string $sMessage): bool => str_starts_with($sMessage, 'metapixel: site 2 disabled'));

        $this->assertTrue(PluginGuard::isDisabledForSite(null));
        Cache::forget(PluginGuard::WARNING_CACHE_KEY);
        $this->assertTrue(PluginGuard::isDisabledForSite(2));
    }

    public function test_warning_throttle_failure_never_throws(): void
    {
        $this->insertRow(2, ['pixel_id' => 'owner@example.com', 'capi_access_token' => 'EAAB1']);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache store down'));

        $this->assertTrue(PluginGuard::isDisabledForSite(2));
    }

    public function test_settings_read_failure_disables_without_memoising(): void
    {
        Schema::rename('system_settings', 'system_settings_away');
        Log::shouldReceive('warning')->once();

        try {
            $this->assertTrue(PluginGuard::isDisabledForSite(1));
        } finally {
            Schema::rename('system_settings_away', 'system_settings');
        }
        $this->insertRow(1, ['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB1']);

        $this->assertFalse(PluginGuard::isDisabledForSite(1));
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

    private function seedSites(): void
    {
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites(
            $this->app['db']->connection()->getSchemaBuilder(),
            $this->app['db']->connection(),
        );
    }
}
