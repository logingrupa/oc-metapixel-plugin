<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use Illuminate\Http\Request;
use Kharanenka\Helper\CCache;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Throwable;

/**
 * Build hashed `user_data` for Meta CAPI events from a persisted Order.
 *
 * Per Meta CAPI spec:
 *   - PII fields (em, ph, fn, ln, external_id) are sha256(mb_strtolower(trim($value))).
 *   - Request-derived fields (client_ip_address, client_user_agent, fbp, fbc)
 *     are PLAINTEXT (cookie ids are opaque server-side).
 *   - Phone normalisation strips non-digits and prepends `phone_country_code`
 *     Setting (default `371` for Latvia; multi-site operators override).
 *   - Guest external_id = sha256(secret_key) per CONTEXT Area 3 Q3 + PAY-08.
 *
 * Memoization: results cached forever per request via CCache tag
 * `meta-pixel-user-hash` keyed by `meta-pixel-user-hash:order:{$iOrderId}`.
 * Cache backend is `array` in tests (per-request scope) and file/redis in
 * production; CCache wrappers the driver split transparently.
 *
 * Class file ≤ 200 LOC; phpstan level 10 strict; Hungarian notation throughout.
 *
 * @see classes/meta/PayloadBuilder.php — constructor-injects this class
 * @see CONTEXT Area 3 Q2 (cache hierarchy) + Specifics line 157 (phone)
 */
class UserDataHasher
{
    private const string CACHE_TAG = 'meta-pixel-user-hash';

    private const string CACHE_KEY_PREFIX_ORDER = 'meta-pixel-user-hash:order:';

    private const string DEFAULT_PHONE_COUNTRY_CODE = '371';

    /**
     * Build hashed user_data for a paid Order.
     *
     * @return array<string, string|null>
     */
    public function forOrder(Order $obOrder): array
    {
        $sCacheKey = self::CACHE_KEY_PREFIX_ORDER.(int) $obOrder->id;
        $mCached = CCache::get([self::CACHE_TAG], $sCacheKey);
        if (is_array($mCached) && $mCached !== []) {
            /** @var array<string, string|null> $mCached */
            return $mCached;
        }

        $arData = $this->compute($obOrder);
        CCache::forever([self::CACHE_TAG], $sCacheKey, $arData);

        return $arData;
    }

    /**
     * Compute the hashed-PII + plaintext-request-metadata array. Keys that
     * resolve to null/empty are still emitted as `null` (Meta accepts them).
     *
     * @return array<string, string|null>
     */
    private function compute(Order $obOrder): array
    {
        $obRequest = $this->readRequest();
        $sEmail = $this->stringOrNull($obOrder->getAttribute('email'));
        $sPhone = $this->stringOrNull($obOrder->getAttribute('phone'));
        $sName = $this->stringOrNull($obOrder->getAttribute('name'));
        $sLastName = $this->stringOrNull($obOrder->getAttribute('last_name'));
        $sSecretKey = (string) $obOrder->getAttribute('secret_key');
        $sNormalisedPhone = $this->normalisePhone($sPhone);

        return [
            'em' => $sEmail !== null ? $this->hashLower($sEmail) : null,
            'ph' => $sNormalisedPhone !== null ? hash('sha256', $sNormalisedPhone) : null,
            'fn' => $sName !== null ? $this->hashLower($sName) : null,
            'ln' => $sLastName !== null ? $this->hashLower($sLastName) : null,
            'external_id' => $this->hashLower($sSecretKey),
            'client_ip_address' => $obRequest?->ip(),
            'client_user_agent' => $obRequest?->userAgent(),
            'fbp' => $this->readCookie($obRequest, '_fbp'),
            'fbc' => $this->readCookie($obRequest, '_fbc'),
        ];
    }

    /**
     * Strip non-digits, prepend phone_country_code Setting if missing.
     * Returns null if input is empty or contains no digits.
     */
    private function normalisePhone(?string $sPhone): ?string
    {
        if ($sPhone === null || $sPhone === '') {
            return null;
        }

        $sDigits = preg_replace('/\D+/', '', $sPhone) ?? '';
        if ($sDigits === '') {
            return null;
        }

        $sCountryCode = $this->readPhoneCountryCode();
        if (str_starts_with($sDigits, $sCountryCode)) {
            return $sDigits;
        }

        return $sCountryCode.$sDigits;
    }

    private function readPhoneCountryCode(): string
    {
        $mValue = Settings::get('phone_country_code', self::DEFAULT_PHONE_COUNTRY_CODE);
        $sValue = is_scalar($mValue) ? (string) $mValue : self::DEFAULT_PHONE_COUNTRY_CODE;

        return $sValue !== '' ? $sValue : self::DEFAULT_PHONE_COUNTRY_CODE;
    }

    private function hashLower(string $sValue): string
    {
        return hash('sha256', mb_strtolower(trim($sValue)));
    }

    /**
     * Resolve the current Request from the container if available. Queue
     * workers + CLI scripts have no Request — return null.
     */
    private function readRequest(): ?Request
    {
        try {
            $obRequest = app(Request::class);

            return $obRequest instanceof Request ? $obRequest : null;
        } catch (Throwable) {
            // silent: no request available (queue worker / CLI context).
            return null;
        }
    }

    private function readCookie(?Request $obRequest, string $sCookieName): ?string
    {
        if ($obRequest === null) {
            return null;
        }

        $mValue = $obRequest->cookie($sCookieName);

        return is_scalar($mValue) ? (string) $mValue : null;
    }

    private function stringOrNull(mixed $mValue): ?string
    {
        if ($mValue === null) {
            return null;
        }
        if (! is_scalar($mValue)) {
            return null;
        }

        $sValue = (string) $mValue;

        return $sValue !== '' ? $sValue : null;
    }
}
