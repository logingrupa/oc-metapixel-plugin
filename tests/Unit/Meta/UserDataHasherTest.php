<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class UserDataHasherTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_email_is_sha256_lowercased_trimmed(): void
    {
        $obAdapter = (new FakeAdapter)->withUserData(['em' => '  FOO@BAR.COM  ']);
        $arResult = (new UserDataHasher)->forSubject($obAdapter, new stdClass);

        $this->assertSame(hash('sha256', 'foo@bar.com'), $arResult['em']);
    }

    public function test_null_and_empty_inputs_return_null_not_hash_of_empty(): void
    {
        $obAdapter = (new FakeAdapter)->withUserData([
            'em' => null,
            'ph' => '',
        ]);
        $arResult = (new UserDataHasher)->forSubject($obAdapter, new stdClass);

        $this->assertNull($arResult['em'], 'null email MUST stay null — never hash empty string');
        $this->assertNull($arResult['ph'], 'empty phone MUST stay null — never hash empty string');
    }

    public function test_passthrough_fields_are_not_hashed(): void
    {
        $obAdapter = (new FakeAdapter)->withUserData([
            'fbp' => 'fb.1.x.42',
            'fbc' => 'fb.1.x.fbclidvalue',
            'client_ip_address' => '203.0.113.10',
            'client_user_agent' => 'Mozilla/5.0',
        ]);
        $arResult = (new UserDataHasher)->forSubject($obAdapter, new stdClass);

        $this->assertSame('fb.1.x.42', $arResult['fbp']);
        $this->assertSame('fb.1.x.fbclidvalue', $arResult['fbc']);
        $this->assertSame('203.0.113.10', $arResult['client_ip_address']);
        $this->assertSame('Mozilla/5.0', $arResult['client_user_agent']);
    }

    public function test_returns_all_thirteen_documented_keys(): void
    {
        $obAdapter = new FakeAdapter;
        $arResult = (new UserDataHasher)->forSubject($obAdapter, new stdClass);

        $arExpected = [
            'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id',
            'fbp', 'fbc', 'client_ip_address', 'client_user_agent',
        ];
        $arActual = array_keys($arResult);
        sort($arExpected);
        sort($arActual);

        $this->assertSame($arExpected, $arActual);
        $this->assertCount(13, $arResult);
    }
}
