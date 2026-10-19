<?php

namespace Logingrupa\Metapixel\Tests\Unit\Models;

use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use October\Rain\Database\ModelException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->app['translator']->addNamespace('logingrupa.metapixel', dirname(__DIR__, 3).'/lang');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_before_save_keeps_valid_entries_one_per_line(): void
    {
        Settings::set(['theme_custom_event_names' => "Foo\nBar_baz\nQuux42\n"]);

        $arResult = Settings::getThemeCustomEventNames();

        $this->assertSame(['Foo', 'Bar_baz', 'Quux42'], $arResult);
    }

    public function test_before_save_drops_entries_with_dashes_or_special_chars(): void
    {
        $obFlashFake = Mockery::mock();
        $obFlashFake->shouldReceive('warning')->andReturnNull();
        $this->app->instance('flash', $obFlashFake);

        Settings::set(['theme_custom_event_names' => "Good\nBad-Name\nBad name\nBad.\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['Good'], $arResult);
    }

    public function test_before_save_drops_entries_over_50_chars(): void
    {
        $obFlashFake = Mockery::mock();
        $obFlashFake->shouldReceive('warning')->andReturnNull();
        $this->app->instance('flash', $obFlashFake);

        $sOversize = str_repeat('A', 51);
        Settings::set(['theme_custom_event_names' => "Valid\n".$sOversize."\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['Valid'], $arResult);
    }

    public function test_before_save_drops_empty_lines_silently_without_flash(): void
    {
        $obFlashFake = Mockery::mock();
        $obFlashFake->shouldNotReceive('warning');
        $this->app->instance('flash', $obFlashFake);

        Settings::set(['theme_custom_event_names' => "\n\nGoodName\n\n"]);

        $arResult = Settings::getThemeCustomEventNames();
        $this->assertSame(['GoodName'], $arResult);
    }

    public function test_before_save_flashes_warning_listing_dropped_entries(): void
    {
        $obFlashFake = Mockery::mock();
        $obFlashFake->shouldReceive('warning')
            ->once()
            ->with(Mockery::on(static function ($mMessage): bool {
                return is_string($mMessage)
                    && str_contains($mMessage, 'X-Y')
                    && str_contains($mMessage, 'A B');
            }));
        $this->app->instance('flash', $obFlashFake);

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
        // Gap 4 regression anchor — the six previously-missing field label keys
        // exist in lang/en/lang.php with non-empty human-readable English strings.
        // Plan 04-05 / LANG-01 migrated the lang shape from settings.fields.* to
        // the top-level field.* group (RESEARCH Pattern 11); this assertion now
        // reads against the new group.
        // Hermetic file-load (the test base runs autoRegister=false so the plugin's
        // lang namespace is not bound to the Translator; assert directly against the
        // file contents — the Settings backend page reads through the same loader).
        $arLang = require dirname(__DIR__, 3).'/lang/en/lang.php';
        $arFields = $arLang['field'] ?? [];

        $arKeys = [
            'paid_status_code_label',
            'paid_status_code_comment',
            'default_currency_code_label',
            'default_currency_code_comment',
            'theme_custom_event_names_label',
            'theme_custom_event_names_comment',
        ];

        foreach ($arKeys as $sKey) {
            $this->assertArrayHasKey($sKey, $arFields, "Lang key 'field.{$sKey}' missing from lang/en/lang.php.");
            $sValue = $arFields[$sKey];
            $this->assertIsString($sValue, "Lang key 'field.{$sKey}' is not a string.");
            $this->assertNotSame($sKey, $sValue, "Lang key 'field.{$sKey}' value is a placeholder copy of the key.");
            $this->assertGreaterThan(0, strlen($sValue), "Lang key 'field.{$sKey}' resolved to an empty string.");
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function provideRejectedCredentials(): array
    {
        return [
            'email pixel id' => ['pixel_id', 'owner@example.com', 'pixel_id_email'],
            'letters in pixel id' => ['pixel_id', 'abc123', 'pixel_id_digits'],
            'password token' => ['capi_access_token', 'hunter2hunter2', 'capi_access_token_prefix'],
        ];
    }

    #[DataProvider('provideRejectedCredentials')]
    public function test_before_save_refuses_malformed_credentials_without_echoing_them(
        string $sField,
        string $sValue,
        string $sLangKey,
    ): void {
        Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'EAAB12']);
        $sStoredBefore = $this->readStoredRow();

        try {
            Settings::set([$sField => $sValue]);
            $this->fail('Settings::set accepted a malformed '.$sField.'.');
        } catch (ModelException $obException) {
            $sMessage = (string) $obException->getModel()->errors()->first($sField);
            $arLang = require dirname(__DIR__, 3).'/lang/en/lang.php';
            $this->assertSame($arLang['validation'][$sLangKey], $sMessage);
            $this->assertStringNotContainsString($sValue, $sMessage);
            $this->assertStringNotContainsString($sValue, $obException->getMessage());
        }

        $this->assertSame($sStoredBefore, $this->readStoredRow());
    }

    public function test_before_save_accepts_empty_credentials(): void
    {
        Settings::set(['pixel_id' => '', 'capi_access_token' => '']);

        $arStored = json_decode($this->readStoredRow(), true);
        $this->assertSame('', $arStored['pixel_id']);
        $this->assertSame('', $arStored['capi_access_token']);
    }

    public function test_before_save_trims_credentials(): void
    {
        Settings::set(['pixel_id' => ' 1234567890 ', 'capi_access_token' => ' EAAB12 ']);

        $arStored = json_decode($this->readStoredRow(), true);
        $this->assertSame('1234567890', $arStored['pixel_id']);
        $this->assertSame('EAAB12', $arStored['capi_access_token']);
    }

    private function readStoredRow(): string
    {
        $mValue = DB::table('system_settings')->where('item', 'logingrupa_metapixel_settings')->value('value');

        return is_string($mValue) ? $mValue : '';
    }
}
