<?php

use Illuminate\Console\Scheduling\Schedule;
use Logingrupa\Metapixel\Models\Settings;
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

    public function test_register_settings_returns_descriptor_for_settings_model(): void
    {
        $obPlugin = new Plugin($this->app);
        $arDescriptor = $obPlugin->registerSettings();

        $this->assertArrayHasKey('settings', $arDescriptor);
        $this->assertSame(Settings::class, $arDescriptor['settings']['class']);
        $this->assertSame('logingrupa.metapixel::lang.settings.label', $arDescriptor['settings']['label']);
        $this->assertSame('logingrupa.metapixel::lang.settings.category', $arDescriptor['settings']['category']);
        $this->assertSame('icon-bullseye', $arDescriptor['settings']['icon']);
        $this->assertSame(500, $arDescriptor['settings']['order']);
    }

    public function test_register_schedule_wires_purge_command_daily(): void
    {
        $obPlugin = new Plugin($this->app);
        $obSchedule = $this->app->make(Schedule::class);

        $obPlugin->registerSchedule($obSchedule);

        $arEvents = $obSchedule->events();
        $arCommands = array_map(fn ($obEvent) => $obEvent->command ?? '', $arEvents);
        $arMatching = array_filter(
            $arCommands,
            fn (string $sCommand): bool => str_contains($sCommand, 'metapixel:purge-event-log'),
        );

        $this->assertNotEmpty($arMatching, 'registerSchedule must wire metapixel:purge-event-log');

        $obMatchingEvent = null;
        foreach ($arEvents as $obEvent) {
            if (str_contains((string) ($obEvent->command ?? ''), 'metapixel:purge-event-log')) {
                $obMatchingEvent = $obEvent;
                break;
            }
        }
        $this->assertNotNull($obMatchingEvent);
        $this->assertSame('0 0 * * *', $obMatchingEvent->expression, 'daily() cron expression must be 0 0 * * *');
    }
}
