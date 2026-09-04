<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Event\AccountIdentityHandler;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Built-in account identity listener: maps a user model to the raw identity
 * keys, prefixes the dial code to national phone numbers, stays silent while
 * the toggle is off and never overwrites a key another listener filled.
 */
final class AccountIdentityHandlerTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
        Settings::set(['account_identity_enabled' => false, 'account_phone_dial_code' => '371']);
    }

    protected function tearDown(): void
    {
        Event::forget(UserDataResolveHook::HOOK_RESOLVE);
        parent::tearDown();
    }

    public function test_full_user_maps_to_identity_keys_with_dial_code_prefixed(): void
    {
        $arIdentity = $this->makeHandler()->identityFromUser($this->makeUser([
            'id' => 42,
            'email' => 'anna@example.com',
            'first_name' => 'Anna',
            'last_name' => 'Berzina',
            'phone' => '26 111-222',
        ]));

        $this->assertSame([
            'em' => 'anna@example.com',
            'ph' => '37126111222',
            'fn' => 'Anna',
            'ln' => 'Berzina',
            'external_id' => '42',
        ], $arIdentity);
    }

    public function test_name_falls_back_to_the_name_attribute_when_first_name_is_empty(): void
    {
        $arIdentity = $this->makeHandler()->identityFromUser($this->makeUser([
            'id' => 7,
            'email' => 'anna@example.com',
            'name' => 'Anna',
            'last_name' => 'Berzina',
        ]));

        $this->assertSame('Anna', $arIdentity['fn']);
        $this->assertSame('Berzina', $arIdentity['ln']);
    }

    public function test_first_comma_separated_phone_wins_and_is_reduced_to_digits(): void
    {
        $arIdentity = $this->makeHandler()->identityFromUser($this->makeUser([
            'id' => 7,
            'phone' => '+371 26111222,+371 20000000',
        ]));

        $this->assertSame('37126111222', $arIdentity['ph']);
    }

    public function test_national_phone_without_dial_code_is_dropped(): void
    {
        Settings::set(['account_phone_dial_code' => '']);

        $arIdentity = $this->makeHandler()->identityFromUser($this->makeUser([
            'id' => 7,
            'phone' => '26111222',
        ]));

        $this->assertArrayNotHasKey('ph', $arIdentity);
    }

    public function test_empty_email_yields_no_em_key(): void
    {
        $arIdentity = $this->makeHandler()->identityFromUser($this->makeUser([
            'id' => 7,
            'email' => '',
            'first_name' => 'Anna',
        ]));

        $this->assertArrayNotHasKey('em', $arIdentity);
        $this->assertSame(['fn' => 'Anna', 'external_id' => '7'], $arIdentity);
    }

    public function test_toggle_off_leaves_the_array_untouched(): void
    {
        $arUserData = ['em' => null, 'ph' => null, 'fn' => 'preset'];

        $this->makeHandler()->fillFromAccount($arUserData);

        $this->assertSame(['em' => null, 'ph' => null, 'fn' => 'preset'], $arUserData);
    }

    public function test_subscribed_listener_keeps_keys_an_earlier_listener_filled(): void
    {
        Settings::set(['account_identity_enabled' => true]);
        Event::subscribe(AccountIdentityHandler::class);
        $arUserData = ['em' => 'host@example.com', 'ph' => null, 'fn' => null];

        Event::fire(UserDataResolveHook::HOOK_RESOLVE, [&$arUserData, ['event_name' => 'PageView', 'subject_type' => 'theme.action']]);

        $this->assertSame('host@example.com', $arUserData['em'], 'first listener wins');
    }

    private function makeHandler(): AccountIdentityHandler
    {
        return new AccountIdentityHandler;
    }

    /**
     * @param  array<string, mixed>  $arAttributes
     */
    private function makeUser(array $arAttributes): Model
    {
        $obUser = new class extends Model
        {
            protected $guarded = [];
        };

        return $obUser->forceFill($arAttributes);
    }
}
