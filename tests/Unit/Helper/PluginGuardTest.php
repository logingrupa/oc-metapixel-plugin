<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

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
}
