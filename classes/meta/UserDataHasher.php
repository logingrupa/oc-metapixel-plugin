<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

/**
 * Normalises and sha256-hashes raw user_data per the Meta Conversions API
 * customer-information rules, one normaliser per field. Passthrough fields
 * (fbp/fbc/client_ip_address/client_user_agent) are returned as-is. Null or
 * empty input returns null, never the hash of an empty string.
 */
final class UserDataHasher
{
    /** Fields Meta expects sha256-hashed, in the order the payload lists them. */
    public const IDENTITY_FIELDS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];

    /** Fields Meta expects raw (not hashed). */
    private const PASSTHROUGH_FIELDS = ['fbp', 'fbc', 'client_ip_address', 'client_user_agent'];

    /**
     * @return array<string, ?string>
     */
    public function forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array
    {
        return $this->hashRaw($obAdapter->getUserData($obSubject));
    }

    /**
     * All thirteen user_data keys: hashed identity plus raw passthrough.
     *
     * @param  array<string, mixed>  $arRaw
     * @return array<string, ?string>
     */
    public function hashRaw(array $arRaw): array
    {
        $arResult = [];
        foreach (self::IDENTITY_FIELDS as $sField) {
            $arResult[$sField] = $this->hashField($sField, $arRaw[$sField] ?? null);
        }
        foreach (self::PASSTHROUGH_FIELDS as $sField) {
            $mValue = $arRaw[$sField] ?? null;
            $arResult[$sField] = is_string($mValue) ? $mValue : null;
        }

        return $arResult;
    }

    /**
     * Identity fields only, empty ones dropped. Shared by the request
     * identity hook and the browser Advanced Matching init object.
     *
     * @param  array<string, mixed>  $arRaw
     * @return array<string, string>
     */
    public function hashIdentity(array $arRaw): array
    {
        $arResult = [];
        foreach (self::IDENTITY_FIELDS as $sField) {
            $sHash = $this->hashField($sField, $arRaw[$sField] ?? null);
            if ($sHash !== null) {
                $arResult[$sField] = $sHash;
            }
        }

        return $arResult;
    }

    private function hashField(string $sField, mixed $mValue): ?string
    {
        if (! is_string($mValue)) {
            return null;
        }
        $sNormalized = match ($sField) {
            'em' => $this->normalizeEmail($mValue),
            'ph' => $this->normalizePhone($mValue),
            'fn', 'ln' => $this->normalizeName($mValue),
            'ct', 'st', 'zp' => $this->normalizeCompact($mValue),
            'country' => $this->normalizeCountry($mValue),
            'external_id' => trim($mValue),
            default => '',
        };
        if ($sNormalized === '') {
            return null;
        }

        return hash('sha256', $sNormalized);
    }

    /** Lowercase, trimmed. */
    private function normalizeEmail(string $sValue): string
    {
        return mb_strtolower(trim($sValue));
    }

    /** Digits only (drops "+", spaces, dashes), no leading zeros. The country code must already be present. */
    private function normalizePhone(string $sValue): string
    {
        return ltrim((string) preg_replace('/\D+/', '', $sValue), '0');
    }

    /** Lowercase, no punctuation or symbols, single spaces; UTF-8 letters stay. */
    private function normalizeName(string $sValue): string
    {
        $sLetters = (string) preg_replace('/[\p{P}\p{S}]+/u', '', mb_strtolower(trim($sValue)));

        return trim((string) preg_replace('/\s+/u', ' ', $sLetters));
    }

    /** City, state, zip: lowercase, no spaces, no punctuation. */
    private function normalizeCompact(string $sValue): string
    {
        return (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower($sValue));
    }

    /** ISO 3166-1 alpha-2, lowercase; anything else is not a country code. */
    private function normalizeCountry(string $sValue): string
    {
        $sCode = strtolower(trim($sValue));

        return preg_match('/^[a-z]{2}$/', $sCode) === 1 ? $sCode : '';
    }
}
