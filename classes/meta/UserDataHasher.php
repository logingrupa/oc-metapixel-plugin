<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Meta;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Kharanenka\Helper\CCache;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Build hashed `user_data` for Meta CAPI events from a persisted Order.
 *
 * Per Meta CAPI spec:
 *   - PII fields (em, ph, fn, ln) are sha256(mb_strtolower(trim($value))).
 *   - external_id is sha256(trim($value)) — Meta's CAPI documentation
 *     explicitly does NOT include lowercase/trim in the normalisation step
 *     for external_id ("any unique identifier from the advertiser; hashing
 *     is optional but recommended"). WR-02 lock: lowercasing breaks the
 *     user-resolution / EMQ score when the same identifier appears in
 *     different cases across two events. Today's secret_key is lowercase
 *     ASCII (Lovata OrderProcessor::generateSecretKey via Str::random) so
 *     the production output is unchanged, but the contract is now correct
 *     for any future column source (UUIDv4 hex, base64 token, mixed-case ID).
 *   - Request-derived fields (client_ip_address, client_user_agent, fbp, fbc)
 *     are PLAINTEXT (cookie ids are opaque server-side).
 *   - Phone normalisation strips non-digits and prepends `phone_country_code`
 *     Setting (default `371` for Latvia; multi-site operators override).
 *   - Guest external_id = sha256(trim(secret_key)) per CONTEXT Area 3 Q3 + PAY-08
 *     (WR-02 correction: NOT lowercased per Meta's external_id spec).
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
        $mOrderId = $obOrder->getAttribute('id');
        $iOrderId = is_int($mOrderId) || (is_string($mOrderId) && is_numeric($mOrderId)) ? (int) $mOrderId : 0;
        $sCacheKey = self::CACHE_KEY_PREFIX_ORDER.$iOrderId;
        $mCached = CCache::get([self::CACHE_TAG], $sCacheKey);
        if (is_array($mCached) && $mCached !== []) {
            return $this->narrowCachedArray($mCached);
        }

        $arData = $this->compute($obOrder);
        // CCache::forever accepts the value by-reference (`&$arValue`) — phpstan
        // level 10 widens $arData to `mixed` post-call as the helper might mutate.
        // Pass a throwaway buffer so the return-type contract is preserved.
        $arCacheBuffer = $arData;
        CCache::forever([self::CACHE_TAG], $sCacheKey, $arCacheBuffer);

        return $arData;
    }

    /**
     * Narrow a cache-hit `array<mixed>` to `array<string, string|null>` —
     * required because phpstan level 10 cannot infer string-keyed shape
     * from `CCache::get` (mixed return). Mirrors MetaClient::decodeResponseBody
     * narrowing pattern (MC-05 deviation, plan 03-03).
     *
     * @param  array<mixed>  $mCached
     * @return array<string, string|null>
     */
    private function narrowCachedArray(array $mCached): array
    {
        $arResult = [];
        foreach ($mCached as $mKey => $mValue) {
            if (! is_string($mKey)) {
                continue;
            }
            $arResult[$mKey] = is_string($mValue) ? $mValue : null;
        }

        return $arResult;
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
        $sEmail = $this->readOrderField($obOrder, 'email');
        $sPhone = $this->readOrderField($obOrder, 'phone');
        $sName = $this->readOrderField($obOrder, 'name');
        $sLastName = $this->readOrderField($obOrder, 'last_name');
        $mSecretKey = $obOrder->getAttribute('secret_key');
        $sSecretKey = is_scalar($mSecretKey) ? (string) $mSecretKey : '';
        $sNormalisedPhone = $this->normalisePhone($sPhone);

        return [
            'em' => $sEmail !== null ? $this->hashLower($sEmail) : null,
            'ph' => $sNormalisedPhone !== null ? hash('sha256', $sNormalisedPhone) : null,
            'fn' => $sName !== null ? $this->hashLower($sName) : null,
            'ln' => $sLastName !== null ? $this->hashLower($sLastName) : null,
            // WR-02: external_id is NOT lowercased per Meta CAPI spec —
            // hashing-only (trim is fine; lowercase corrupts user-resolution
            // for mixed-case identifiers).
            'external_id' => $sSecretKey !== '' ? hash('sha256', trim($sSecretKey)) : null,
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
     *
     * WR-06 lock: narrow catch from \Throwable to
     * BindingResolutionException — the only way `app(Request::class)` fails
     * is container resolution. Catching Throwable would silently swallow
     * unrelated bugs.
     */
    private function readRequest(): ?Request
    {
        try {
            return app(Request::class);
        } catch (BindingResolutionException) {
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

    /**
     * Read a customer-identity field from the Order.
     *
     * Lovata.OrdersShopaholic v1.33 stores `email`, `phone`, `name`, `last_name`
     * inside the `property` jsonable column (NOT as direct columns) for guest
     * orders. Logged-in customers may have the same data on the linked Buddies
     * user record. Order falls back to direct attribute access in case a future
     * schema promotes these to real columns.
     */
    private function readOrderField(Order $obOrder, string $sField): ?string
    {
        $sDirect = $this->stringOrNull($obOrder->getAttribute($sField));
        if ($sDirect !== null) {
            return $sDirect;
        }

        $mProperty = $obOrder->getAttribute('property');
        if (! is_array($mProperty)) {
            return null;
        }

        return $this->stringOrNull($mProperty[$sField] ?? null);
    }
}
