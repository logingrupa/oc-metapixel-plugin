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

    public function test_phone_keeps_digits_only_and_drops_leading_zeros(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['ph' => '+371 (26) 111-222']);
        $this->assertSame(hash('sha256', '37126111222'), $arResult['ph']);

        $arResult = (new UserDataHasher)->hashRaw(['ph' => '0037126111222']);
        $this->assertSame(hash('sha256', '37126111222'), $arResult['ph'], 'international 00 prefix is dropped');

        $arResult = (new UserDataHasher)->hashRaw(['ph' => '+ -']);
        $this->assertNull($arResult['ph'], 'no digits left means no phone');
    }

    public function test_first_name_is_lowercased_without_punctuation_and_keeps_utf8_letters(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['fn' => "  Anna-Marija  O'Brien "]);
        $this->assertSame(hash('sha256', 'annamarija obrien'), $arResult['fn']);

        $arResult = (new UserDataHasher)->hashRaw(['fn' => 'Bērziņš']);
        $this->assertSame(hash('sha256', 'bērziņš'), $arResult['fn']);
    }

    public function test_last_name_uses_the_name_rule(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['ln' => 'Van der Berg.']);
        $this->assertSame(hash('sha256', 'van der berg'), $arResult['ln']);
    }

    public function test_city_is_lowercase_without_spaces_or_punctuation(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['ct' => 'New York-City ']);
        $this->assertSame(hash('sha256', 'newyorkcity'), $arResult['ct']);
    }

    public function test_state_is_lowercase_compact(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['st' => ' CA ']);
        $this->assertSame(hash('sha256', 'ca'), $arResult['st']);
    }

    public function test_zip_is_lowercase_without_spaces_or_dashes(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['zp' => 'LV-1010']);
        $this->assertSame(hash('sha256', 'lv1010'), $arResult['zp']);

        $arResult = (new UserDataHasher)->hashRaw(['zp' => 'SW1A 1AA']);
        $this->assertSame(hash('sha256', 'sw1a1aa'), $arResult['zp']);
    }

    public function test_country_accepts_only_two_letter_codes(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['country' => ' LV ']);
        $this->assertSame(hash('sha256', 'lv'), $arResult['country']);

        $arResult = (new UserDataHasher)->hashRaw(['country' => 'Latvia']);
        $this->assertNull($arResult['country'], 'a country name is not an ISO 3166-1 alpha-2 code');
    }

    public function test_external_id_is_trimmed_and_case_preserved(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['external_id' => ' User-42 ']);
        $this->assertSame(hash('sha256', 'User-42'), $arResult['external_id']);
    }

    public function test_non_string_input_is_ignored(): void
    {
        $arResult = (new UserDataHasher)->hashRaw(['em' => 42, 'fbp' => ['x'], 'ph' => 37120000000]);

        $this->assertNull($arResult['em']);
        $this->assertNull($arResult['fbp']);
        $this->assertNull($arResult['ph']);
    }

    public function test_hash_identity_returns_only_present_identity_fields(): void
    {
        $arResult = (new UserDataHasher)->hashIdentity([
            'em' => 'Anna@Example.com',
            'ph' => '',
            'fbp' => 'fb.1.x.42',
            'external_id' => '7',
        ]);

        $this->assertSame([
            'em' => hash('sha256', 'anna@example.com'),
            'external_id' => hash('sha256', '7'),
        ], $arResult);
    }
}
