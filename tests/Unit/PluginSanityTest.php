<?php

use Logingrupa\Metapixel\Plugin;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * because Pest's $rootPath resolution under `vendor/bin/pest --configuration
 * phpunit.xml` does not always pick up the Pest.php binding. The explicit
 * extends keeps this smoke test working under any Pest invocation shape.
 */

final class PluginSanityTest extends MetapixelTestCase
{
    public function test_plugin_class_loads_via_psr4_autoload(): void
    {
        $this->assertTrue(class_exists(Plugin::class));
    }

    public function test_plugin_details_returns_lang_keys_under_renamed_namespace(): void
    {
        $obPlugin = new Plugin($this->app);
        $arDetails = $obPlugin->pluginDetails();

        $this->assertSame('logingrupa.metapixel::lang.plugin.name', $arDetails['name']);
        $this->assertSame('logingrupa.metapixel::lang.plugin.description', $arDetails['description']);
        $this->assertSame('Logingrupa', $arDetails['author']);
    }

    public function test_register_and_boot_are_callable_without_error(): void
    {
        $obPlugin = new Plugin($this->app);

        $obPlugin->register();
        $obPlugin->boot();

        $this->assertTrue(true);
    }
}
