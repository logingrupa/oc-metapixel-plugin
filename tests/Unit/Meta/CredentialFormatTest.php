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
}
