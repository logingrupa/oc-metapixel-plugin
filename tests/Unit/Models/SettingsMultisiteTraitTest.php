<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Wave 0 RED — fails until plan 04-01 ships.
 *
 * MULT-01 / D-20: Settings declares an explicit empty $propagatable at the
 * descendant level (marketplace audit anchor). MULT-02 / D-02: pixel_id +
 * capi_access_token MUST stay out of the propagatable whitelist (P-10).
 */
final class SettingsMultisiteTraitTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        Settings::clearInternalCache();
    }

    public function test_settings_declares_explicit_empty_propagatable_at_descendant_level(): void
    {
        $obReflect = new ReflectionClass(Settings::class);

        $this->assertTrue(
            $obReflect->hasProperty('propagatable'),
            'Settings MUST declare $propagatable in its own class body.'
        );

        $obProp = $obReflect->getProperty('propagatable');
        $this->assertSame(
            Settings::class,
            $obProp->getDeclaringClass()->getName(),
            'D-20: $propagatable MUST be declared at the descendant level, not only inherited.'
        );
    }

    public function test_propagatable_is_empty_list(): void
    {
        $obReflect = new ReflectionClass(Settings::class);
        $obProp = $obReflect->getProperty('propagatable');
        $obProp->setAccessible(true);

        $arValue = $obProp->getValue(new Settings);

        $this->assertSame([], $arValue, 'D-02 lock — empty whitelist blocks cross-site credential propagation.');
    }

    public function test_pixel_id_is_not_in_propagatable(): void
    {
        $obReflect = new ReflectionClass(Settings::class);
        $obProp = $obReflect->getProperty('propagatable');
        $obProp->setAccessible(true);

        /** @var array<int, string> $arPropagatable */
        $arPropagatable = $obProp->getValue(new Settings);

        $this->assertFalse(
            in_array('pixel_id', $arPropagatable, true),
            'MULT-02 / P-10: pixel_id MUST NEVER propagate across sites.'
        );
    }

    public function test_capi_access_token_is_not_in_propagatable(): void
    {
        $obReflect = new ReflectionClass(Settings::class);
        $obProp = $obReflect->getProperty('propagatable');
        $obProp->setAccessible(true);

        /** @var array<int, string> $arPropagatable */
        $arPropagatable = $obProp->getValue(new Settings);

        $this->assertFalse(
            in_array('capi_access_token', $arPropagatable, true),
            'MULT-02 / P-10: capi_access_token MUST NEVER propagate across sites.'
        );
    }
}
