<?php

namespace Logingrupa\Metapixel\Tests\Unit\Models;

use Flash;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-05 SAVE-boundary sanitization — Settings::beforeSave drops malformed
 * theme_custom_event_names entries (regex /^[A-Za-z0-9_]{1,50}$/), flashes a
 * warning listing dropped values, keeps valid entries untouched.
 *
 * Tagged adapter group so it runs in the full-Lovata cell only (test depends
 * on Settings extending Lovata.Toolbox CommonSettings).
 */
#[Group('adapter')]
final class SettingsBeforeSaveTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_beforeSave_keeps_valid_entries_one_per_line(): void
    {
        Settings::set(['theme_custom_event_names' => "Foo\nBar_baz\nQuux42\n"]);

        $arResult = Settings::getThemeCustomEventNames();

        $this->assertSame(['Foo', 'Bar_baz', 'Quux42'], $arResult);
    }

    public function test_beforeSave_drops_entries_with_dashes_or_special_chars(): void
    {
        $obFlashFake = Mockery::mock('alias:'.Flash::class);
        $obFlashFake->shouldReceive('warning')->andReturnNull();

        Settings::set(['theme_custom_event_names' => "Good\nBad-Name\nBad name\nBad.\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['Good'], $arResult);
    }

    public function test_beforeSave_drops_entries_over_50_chars(): void
    {
        $obFlashFake = Mockery::mock('alias:'.Flash::class);
        $obFlashFake->shouldReceive('warning')->andReturnNull();

        $sOversize = str_repeat('A', 51);
        Settings::set(['theme_custom_event_names' => "Valid\n".$sOversize."\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['Valid'], $arResult);
    }

    public function test_beforeSave_drops_empty_lines_silently_without_flash(): void
    {
        $obFlashFake = Mockery::mock('alias:'.Flash::class);
        $obFlashFake->shouldNotReceive('warning');

        Settings::set(['theme_custom_event_names' => "\n\nGoodName\n\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['GoodName'], $arResult);
    }

    public function test_beforeSave_flashes_warning_listing_dropped_entries(): void
    {
        $obFlashFake = Mockery::mock('alias:'.Flash::class);
        $obFlashFake->shouldReceive('warning')
            ->once()
            ->with(Mockery::on(static function ($mMessage): bool {
                return is_string($mMessage)
                    && str_contains($mMessage, 'X-Y')
                    && str_contains($mMessage, 'A B');
            }));

        Settings::set(['theme_custom_event_names' => "X-Y\nA B\n"]);

        $this->assertSame([], Settings::getThemeCustomEventNames());
    }

    public function test_theme_custom_event_names_round_trips_through_textarea(): void
    {
        // Gap 3 regression anchor: pre-fix setAttribute(array) → textarea renders
        // 'Array' → re-save wipes the list. Post-fix setAttribute(implode("\n",
        // $arClean)) → textarea renders "Name1\nName2\nName3" → re-save preserves.
        $obSettings = new Settings;
        $obSettings->setAttribute('theme_custom_event_names', "FirstEvent\nSecondEvent\nThirdEvent");

        $obSettings->beforeSave();

        $mStored = $obSettings->getAttribute('theme_custom_event_names');
        $this->assertIsString($mStored);
        $this->assertNotSame('Array', $mStored);
        $this->assertFalse(is_array($mStored));
        $this->assertSame(['FirstEvent', 'SecondEvent', 'ThirdEvent'], preg_split('/\R/', $mStored));
    }

    public function test_settings_fields_lang_keys_resolve_to_human_readable_strings(): void
    {
        // Gap 4 regression anchor: the six previously-missing settings.fields keys
        // exist in lang/en/lang.php with non-empty human-readable English strings.
        // Hermetic file-load (the test base runs autoRegister=false so the plugin's
        // lang namespace is not bound to the Translator; assert directly against the
        // file contents — the Settings backend page reads through the same loader).
        $arLang = require dirname(__DIR__, 3).'/lang/en/lang.php';
        $arFields = $arLang['settings']['fields'] ?? [];

        $arKeys = [
            'paid_status_code_label',
            'paid_status_code_comment',
            'default_currency_code_label',
            'default_currency_code_comment',
            'theme_custom_event_names_label',
            'theme_custom_event_names_comment',
        ];

        foreach ($arKeys as $sKey) {
            $this->assertArrayHasKey($sKey, $arFields, "Lang key 'settings.fields.{$sKey}' missing from lang/en/lang.php.");
            $sValue = $arFields[$sKey];
            $this->assertIsString($sValue, "Lang key 'settings.fields.{$sKey}' is not a string.");
            $this->assertNotSame($sKey, $sValue, "Lang key 'settings.fields.{$sKey}' value is a placeholder copy of the key.");
            $this->assertGreaterThan(0, strlen($sValue), "Lang key 'settings.fields.{$sKey}' resolved to an empty string.");
        }
    }
}
