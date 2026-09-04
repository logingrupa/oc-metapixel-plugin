<?php

namespace Logingrupa\Metapixel\Classes\Event;

use Illuminate\Database\Eloquent\Model;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Built-in metapixel.user_data.resolve listener. Reads the logged-in customer
 * account through Lovata Toolbox UserHelper, so any user plugin Toolbox
 * supports works without a host listener. Fills empty keys only: a host
 * listener registered earlier keeps its values. Off unless the operator
 * enables account_identity_enabled.
 */
final class AccountIdentityHandler
{
    /** National numbers this long get the configured dial code prefixed. */
    private const NATIONAL_PHONE_LENGTH = 8;

    /**
     * @param  \Illuminate\Events\Dispatcher  $obEvent  untyped: October passes its own dispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData): void {
            $this->fillFromAccount($arUserData);
        });
    }

    /**
     * @param  array<mixed>  $arUserData  identity keys as the dispatcher passes them
     */
    public function fillFromAccount(array &$arUserData): void
    {
        if (! Settings::get('account_identity_enabled', false)) {
            return;
        }
        $obHelper = UserHelper::instance();
        if (! $obHelper instanceof UserHelper) {
            return;
        }
        $obUser = $obHelper->getUser();
        if (! $obUser instanceof Model) {
            return;
        }
        foreach ($this->identityFromUser($obUser) as $sKey => $sValue) {
            if (empty($arUserData[$sKey])) {
                $arUserData[$sKey] = $sValue;
            }
        }
    }

    /**
     * Raw identity keys read from a user model; empty values are absent.
     *
     * @return array<string, string>
     */
    public function identityFromUser(Model $obUser): array
    {
        $sDialCode = $this->stringAttribute(Settings::get('account_phone_dial_code', ''));
        $sFirstName = $this->stringAttribute($obUser->getAttribute('first_name'));
        if ($sFirstName === '') {
            $sFirstName = $this->stringAttribute($obUser->getAttribute('name'));
        }

        $arIdentity = [
            'em' => $this->stringAttribute($obUser->getAttribute('email')),
            'ph' => $this->internationalPhone($this->stringAttribute($obUser->getAttribute('phone')), $sDialCode),
            'fn' => $sFirstName,
            'ln' => $this->stringAttribute($obUser->getAttribute('last_name')),
            'external_id' => $this->stringAttribute($obUser->getKey()),
        ];

        return array_filter($arIdentity, static fn (string $sValue): bool => $sValue !== '');
    }

    /**
     * First comma-separated entry, digits only, no leading zeros. Exactly
     * eight digits is a national number: it gets the dial code, or is dropped
     * when no dial code is configured. Other lengths pass through as stored.
     */
    public function internationalPhone(string $sPhone, string $sDialCode): string
    {
        $sFirst = (string) strstr($sPhone.',', ',', true);
        $sDigits = ltrim((string) preg_replace('/\D+/', '', $sFirst), '0');
        if (strlen($sDigits) !== self::NATIONAL_PHONE_LENGTH) {
            return $sDigits;
        }
        $sDialDigits = (string) preg_replace('/\D+/', '', $sDialCode);

        return $sDialDigits === '' ? '' : $sDialDigits.$sDigits;
    }

    private function stringAttribute(mixed $mValue): string
    {
        return is_scalar($mValue) ? trim((string) $mValue) : '';
    }
}
