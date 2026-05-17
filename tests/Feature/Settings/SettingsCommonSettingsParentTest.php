<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\Toolbox\Models\CommonSettings;

final class SettingsCommonSettingsParentTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_settings_extends_common_settings(): void
    {
        $this->assertTrue(
            is_a(Settings::class, CommonSettings::class, true),
            'Settings MUST extend Lovata\\Toolbox\\Models\\CommonSettings.'
        );
    }

    public function test_propagatable_is_empty_array_lock(): void
    {
        $obReflect = new ReflectionClass(Settings::class);
        $obProp = $obReflect->getProperty('propagatable');
        $obProp->setAccessible(true);

        $obInstance = new Settings;
        $mValue = $obProp->getValue($obInstance);

        $this->assertSame([], $mValue, 'Phase 2 lock — Phase 4 MULT-01..02 introduces the per-field whitelist.');
    }
}
