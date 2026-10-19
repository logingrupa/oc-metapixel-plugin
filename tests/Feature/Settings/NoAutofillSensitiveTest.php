<?php

use Backend\Classes\Controller;
use Backend\Classes\FormField;
use Logingrupa\Metapixel\Classes\FormWidget\NoAutofillSensitive;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Plugin;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The CAPI token input keeps the core sensitive widget markup and asks
 * browser password managers not to fill it.
 */
final class NoAutofillSensitiveTest extends MetapixelTestCase
{
    public function test_plugin_registers_the_widget_alias(): void
    {
        $obPlugin = new Plugin($this->app);

        $this->assertSame([NoAutofillSensitive::class => 'metapixelsensitive'], $obPlugin->registerFormWidgets());
    }

    public function test_settings_token_field_uses_the_widget(): void
    {
        $arFields = Yaml::parseFile(dirname(__DIR__, 3).'/models/settings/fields.yaml');

        $this->assertSame('metapixelsensitive', $arFields['tabs']['fields']['capi_access_token']['type']);
        $this->assertSame('text', $arFields['tabs']['fields']['pixel_id']['type']);
    }

    public function test_token_input_renders_new_password_autocomplete(): void
    {
        $sHtml = $this->makeWidget()->render();

        $this->assertStringContainsString('data-control="sensitive"', $sHtml);
        $this->assertStringContainsString('autocomplete="new-password"', $sHtml);
        $this->assertStringNotContainsString('autocomplete="off"', $sHtml);
        $this->assertStringContainsString('name="capi_access_token"', $sHtml);
    }

    public function test_widget_loads_the_core_sensitive_assets(): void
    {
        $arAssetPaths = $this->makeWidget()->getAssetPaths();

        $this->assertSame(['/modules/backend/formwidgets/sensitive/assets/js/sensitive.js'], $arAssetPaths['js']);
        $this->assertSame(['/modules/backend/formwidgets/sensitive/assets/css/sensitive.css'], $arAssetPaths['css']);
    }

    private function makeWidget(): NoAutofillSensitive
    {
        $obController = (new ReflectionClass(Controller::class))->newInstanceWithoutConstructor();

        return new NoAutofillSensitive(
            $obController,
            new FormField('capi_access_token', 'CAPI token'),
            ['model' => new Settings],
        );
    }
}
