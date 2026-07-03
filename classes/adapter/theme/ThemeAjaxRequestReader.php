<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use October\Rain\Support\Facades\Site;

/**
 * Reads the Metapixel AJAX event payload from the request. Holds the
 * request-payload parsing responsibility split out of ThemeAjaxHandler:
 * normalises both supported transport shapes, narrows the hybrid-AJAX
 * loadSubject context to a string-keyed array, and captures server-derived
 * CAPI user_data + site context. Stateless; classes/adapter/theme/
 * is outside the phpstan Request/SiteManager disallow scope (D-16).
 */
final class ThemeAjaxRequestReader
{
    /**
     * Server-derived Meta CAPI user_data + site context for the theme-action
     * path. Mirrors PixelHead::collectRequestUserData — without at least one
     * customer-info parameter Meta rejects the event (HTTP 400 subcode
     * 2804050). site_id is baked in-request so queue-side ThemeActionAdapter
     * getSiteId never falls back to the worker's CLI site context.
     *
     * @return array<string, mixed>
     */
    public function collectServerUserData(): array
    {
        $sClientIp = (string) Request::ip();
        $sClientUa = (string) Request::userAgent();
        $mFbp = Cookie::get('_fbp');
        $mFbc = Cookie::get('_fbc');
        $mSiteId = Site::getSiteIdFromContext();

        return [
            'client_ip_address' => $sClientIp !== '' ? $sClientIp : null,
            'client_user_agent' => $sClientUa !== '' ? $sClientUa : null,
            'fbp' => is_string($mFbp) && $mFbp !== '' ? $mFbp : null,
            'fbc' => is_string($mFbc) && $mFbc !== '' ? $mFbc : null,
            'site_id' => is_int($mSiteId) && $mSiteId > 0 ? $mSiteId : null,
        ];
    }

    /**
     * Read the AJAX event payload from either supported transport shape.
     * Larajax nests fields under data[]; October's native $.request posts
     * options.data as top-level form fields. Nested values win; known
     * top-level fields fill the gaps.
     *
     * @return array<string, mixed>|null null when the nested payload is not an array
     */
    public function readEventData(): ?array
    {
        $arData = $this->normalizeStringKeys(Request::input('data', []));
        if ($arData === null) {
            return null;
        }

        foreach (['name', 'subject_type', 'subject_id', 'offer_id', 'action_key'] as $sField) {
            if (array_key_exists($sField, $arData)) {
                continue;
            }
            $mTopLevelValue = Request::input($sField);
            if ($mTopLevelValue !== null) {
                $arData[$sField] = $mTopLevelValue;
            }
        }

        return $arData;
    }

    /**
     * Coerce a parsed payload field to an int (0 when absent or non-numeric).
     *
     * @param  array<string, mixed>  $arData
     */
    public function readIntField(array $arData, string $sField): int
    {
        $mValue = $arData[$sField] ?? 0;

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    /**
     * Narrow $arData['context'] to a string-keyed array (phpstan level 10
     * requires explicit string-key narrowing on the SupportsHybridAjax
     * loadSubject contract) + overlay top-level offer_id when present.
     *
     * @param  array<string, mixed>  $arData
     * @return array<string, mixed>
     */
    public function buildHybridContext(array $arData): array
    {
        $arContext = [];
        $mContext = $arData['context'] ?? null;
        if (is_array($mContext)) {
            foreach ($mContext as $mKey => $mValue) {
                if (is_string($mKey)) {
                    $arContext[$mKey] = $mValue;
                }
            }
        }
        foreach (['offer_id'] as $sExtra) {
            if (isset($arData[$sExtra])) {
                $arContext[$sExtra] = $arData[$sExtra];
            }
        }

        return $arContext;
    }

    /**
     * Narrow Request::input to a string-keyed array, or null when unusable.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeStringKeys(mixed $mInput): ?array
    {
        if (! is_array($mInput)) {
            return null;
        }
        $arResult = [];
        foreach ($mInput as $mKey => $mValue) {
            if (! is_string($mKey)) {
                return null;
            }
            $arResult[$mKey] = $mValue;
        }

        return $arResult;
    }
}
