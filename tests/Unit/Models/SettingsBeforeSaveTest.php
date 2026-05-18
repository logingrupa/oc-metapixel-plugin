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
}
