<?php

use Illuminate\Http\Request;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Plugin;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Foundation\Http\Middleware\EncryptCookies;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plugin boot exempts _fbp / _fbc from cookie encryption: the browser pixel
 * writes them as plain values, and the encrypting middleware nulls any cookie
 * it cannot decrypt, which silently emptied fbp/fbc on every Cookie facade
 * reader.
 */
final class UnencryptedMetaCookiesTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new Plugin($this->app))->boot();
    }

    public function test_plain_fbp_and_fbc_survive_the_encrypting_middleware(): void
    {
        $arSeen = [];
        $this->runEncryptCookies(
            ['_fbp' => 'fb.1.1700000000000.abcdef0123456789', '_fbc' => 'fb.1.1700000000000.IwAR1click', 'plain_other' => 'raw'],
            static function (Request $obRequest) use (&$arSeen): void {
                $arSeen = [
                    'fbp' => $obRequest->cookies->get('_fbp'),
                    'fbc' => $obRequest->cookies->get('_fbc'),
                    'other' => $obRequest->cookies->get('plain_other'),
                ];
            }
        );

        $this->assertSame('fb.1.1700000000000.abcdef0123456789', $arSeen['fbp']);
        $this->assertSame('fb.1.1700000000000.IwAR1click', $arSeen['fbc']);
        $this->assertNull($arSeen['other'], 'an undecryptable non-Meta cookie is still nulled, so the exemption is what keeps fbp/fbc');
    }

    /**
     * @param  array<string, string>  $arCookies
     */
    private function runEncryptCookies(array $arCookies, Closure $fnInner): void
    {
        $obRequest = Request::create('https://shop.example.com/', 'GET', [], $arCookies);
        $this->app->make(EncryptCookies::class)->handle($obRequest, static function (Request $obInnerRequest) use ($fnInner): Response {
            $fnInner($obInnerRequest);

            return new Response('ok');
        });
    }
}
