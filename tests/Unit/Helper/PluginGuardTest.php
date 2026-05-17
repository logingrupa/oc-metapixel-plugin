<?php

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
}
