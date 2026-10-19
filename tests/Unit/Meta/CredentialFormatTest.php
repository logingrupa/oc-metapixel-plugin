<?php

use Logingrupa\Metapixel\Classes\Meta\CredentialFormat;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The one credential format rule: pixel ids are digits only, CAPI access
 * tokens start with EAA. Everything else is treated as missing.
 */
final class CredentialFormatTest extends MetapixelTestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function providePixelIds(): array
    {
        return [
            'digits' => ['1234567890', true],
            'empty' => ['', false],
            'email' => ['owner@example.com', false],
            'inner space' => ['12 34', false],
            'trailing newline' => ["123\n", false],
        ];
    }

    #[DataProvider('providePixelIds')]
    public function test_is_pixel_id(string $sValue, bool $bExpected): void
    {
        $this->assertSame($bExpected, CredentialFormat::isPixelId($sValue));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function provideAccessTokens(): array
    {
        return [
            'eaa prefix' => ['EAAB12', true],
            'empty' => ['', false],
            'lower case prefix' => ['eaab12', false],
            'password' => ['hunter2hunter2', false],
        ];
    }

    #[DataProvider('provideAccessTokens')]
    public function test_is_access_token(string $sValue, bool $bExpected): void
    {
        $this->assertSame($bExpected, CredentialFormat::isAccessToken($sValue));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function providePixelIdErrorKeys(): array
    {
        return [
            'empty' => ['', null],
            'digits' => ['1234567890', null],
            'email' => ['owner@example.com', 'pixel_id_email'],
            'letters' => ['abc123', 'pixel_id_digits'],
        ];
    }

    #[DataProvider('providePixelIdErrorKeys')]
    public function test_pixel_id_error_key(string $sValue, ?string $sExpected): void
    {
        $this->assertSame($sExpected, CredentialFormat::pixelIdErrorKey($sValue));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function provideAccessTokenErrorKeys(): array
    {
        return [
            'empty' => ['', null],
            'eaa prefix' => ['EAAB12', null],
            'password' => ['hunter2hunter2', 'capi_access_token_prefix'],
        ];
    }

    #[DataProvider('provideAccessTokenErrorKeys')]
    public function test_access_token_error_key(string $sValue, ?string $sExpected): void
    {
        $this->assertSame($sExpected, CredentialFormat::accessTokenErrorKey($sValue));
    }
}
