<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-01 — composer.json shape regression test.
 *
 * Locks the declared composer.json contract: name, PHP version, PSR-4 autoload,
 * autoload-dev, lovata cart plugins in both suggest and require-dev, license.
 */
final class ComposerJsonShapeTest extends MetapixelTestCase
{
    private static string $sPath = '';

    /** @var array<string, mixed> */
    private static array $arShape = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sPath === '') {
            self::$sPath = dirname(__DIR__, 3).'/composer.json';
        }

        if (self::$arShape === []) {
            $sRaw = file_get_contents(self::$sPath);
            self::$arShape = json_decode((string) $sRaw, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    public function test_composer_name_is_logingrupa_oc_metapixel_plugin(): void
    {
        $this->assertSame('logingrupa/oc-metapixel-plugin', self::$arShape['name'] ?? null);
    }

    public function test_php_version_constraint_targets_83_and_84(): void
    {
        $sPhpRequire = self::$arShape['require']['php'] ?? '';
        $this->assertSame('^8.3 || ^8.4', $sPhpRequire);
    }

    public function test_autoload_psr4_uses_logingrupa_metapixel_namespace(): void
    {
        $arPsr4 = self::$arShape['autoload']['psr-4'] ?? [];
        $this->assertArrayHasKey('Logingrupa\\Metapixel\\', $arPsr4);
        $this->assertSame('', $arPsr4['Logingrupa\\Metapixel\\']);
    }

    public function test_autoload_dev_psr4_points_tests_namespace_at_tests_dir(): void
    {
        $arPsr4Dev = self::$arShape['autoload-dev']['psr-4'] ?? [];
        $this->assertArrayHasKey('Logingrupa\\Metapixel\\Tests\\', $arPsr4Dev);
        $this->assertSame('tests/', $arPsr4Dev['Logingrupa\\Metapixel\\Tests\\']);
    }

    public function test_suggest_contains_all_three_lovata_cart_plugin_keys(): void
    {
        $arSuggest = self::$arShape['suggest'] ?? [];
        $this->assertArrayHasKey('lovata/shopaholic-plugin', $arSuggest);
        $this->assertArrayHasKey('lovata/ordersshopaholic-plugin', $arSuggest);
    }

    public function test_require_dev_contains_all_three_lovata_cart_plugins(): void
    {
        $arDev = self::$arShape['require-dev'] ?? [];
        $this->assertArrayHasKey('lovata/shopaholic-plugin', $arDev, 'lovata/shopaholic-plugin must be in require-dev');
        $this->assertArrayHasKey('lovata/ordersshopaholic-plugin', $arDev, 'lovata/ordersshopaholic-plugin must be in require-dev');
        $this->assertArrayHasKey('lovata/buddies-plugin', $arDev, 'lovata/buddies-plugin must be in require-dev');
    }

    public function test_license_is_proprietary(): void
    {
        $this->assertSame('proprietary', self::$arShape['license'] ?? null);
    }
}
