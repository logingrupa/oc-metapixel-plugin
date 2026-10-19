<?php

use Logingrupa\Metapixel\Classes\Helper\SettingsCredentialErrors;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Settings form errors for the credential fields: null and '' pass as empty,
 * any other non-string fails, and messages come from the plugin lang file.
 */
final class SettingsCredentialErrorsTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['translator']->addNamespace('logingrupa.metapixel', dirname(__DIR__, 3).'/lang');
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: array<string, string>}> */
    public static function provideCredentials(): array
    {
        return [
            'null values' => [null, null, []],
            'empty strings' => ['', '', []],
            'valid strings' => ['1234567890', 'EAAB12', []],
            'email pixel and password token' => ['owner@example.com', 'hunter2hunter2', ['pixel_id' => 'pixel_id_email', 'capi_access_token' => 'capi_access_token_prefix']],
            'int pixel' => [2291486191076331, '', ['pixel_id' => 'pixel_id_digits']],
            'int token' => ['', 12345, ['capi_access_token' => 'capi_access_token_prefix']],
            'array pixel and bool token' => [['1234567890'], true, ['pixel_id' => 'pixel_id_digits', 'capi_access_token' => 'capi_access_token_prefix']],
            'false pixel' => [false, null, ['pixel_id' => 'pixel_id_digits']],
        ];
    }

    /** @param  array<string, string>  $arExpectedKeys */
    #[DataProvider('provideCredentials')]
    public function test_for_values(mixed $mPixel, mixed $mToken, array $arExpectedKeys): void
    {
        $arLang = require dirname(__DIR__, 3).'/lang/en/lang.php';
        $arExpected = array_map(static fn (string $sKey): array => [$arLang['validation'][$sKey]], $arExpectedKeys);

        $this->assertSame($arExpected, SettingsCredentialErrors::forValues($mPixel, $mToken));
    }
}
