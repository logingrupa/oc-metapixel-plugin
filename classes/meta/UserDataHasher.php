<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

/**
 * Hashes adapter-supplied raw user_data per Meta Conversions API spec. Stateless —
 * forSubject computes the result on every call. Hashable fields (em/ph/fn/ln/ct/st/
 * zp/country/external_id) are trimmed, lowercased, sha256-ed. Passthrough fields
 * (fbp/fbc/client_ip_address/client_user_agent) are returned as-is. Null/empty
 * input returns null — never the sha256 of an empty string (would collide across
 * unrelated senders).
 */
final class UserDataHasher
{
    /** Fields Meta expects pre-hashed (sha256 lowercase). Lowercased before hash. */
    private const HASHABLE_FIELDS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];

    /** Fields Meta expects raw (not hashed). Pass-through. */
    private const PASSTHROUGH_FIELDS = ['fbp', 'fbc', 'client_ip_address', 'client_user_agent'];

    /**
     * @return array<string, ?string>
     */
    public function forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array
    {
        $arRaw = $obAdapter->getUserData($obSubject);
        $arResult = [];

        foreach (self::HASHABLE_FIELDS as $sField) {
            $arResult[$sField] = $this->hashField($arRaw[$sField] ?? null);
        }
        foreach (self::PASSTHROUGH_FIELDS as $sField) {
            $arResult[$sField] = $arRaw[$sField] ?? null;
        }

        return $arResult;
    }

    private function hashField(?string $sValue): ?string
    {
        if ($sValue === null || $sValue === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($sValue)));
    }
}
