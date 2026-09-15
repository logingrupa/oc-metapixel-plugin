<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
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
    private const SEARCH_STRING_MAX_LENGTH = 100;

    private const CONTENT_IDS_MAX = 20;

    private const CONTENT_ID_PATTERN = '/^SKU-\d+(-\d+)?$/';

    private const NUM_ITEMS_MAX = 1000;

    /**
     * Narrow the client-supplied custom_data of a theme-action event to the
     * fields a Search may carry: search_string, content_ids, content_type,
     * num_items. Every other client key stays dropped; identity, value and
     * currency remain server-derived on this path.
     *
     * @param  array<string, mixed>  $arData
     * @return array<string, mixed>
     */
    public function readClientCustomData(array $arData): array
    {
        $arResult = [];
        $sSearchString = $this->readSearchString($arData['search_string'] ?? null);
        if ($sSearchString !== null) {
            $arResult['search_string'] = $sSearchString;
        }
        $arContentIds = $this->readContentIds($arData['content_ids'] ?? null);
        if ($arContentIds !== []) {
            $arResult['content_ids'] = $arContentIds;
        }
        // Meta requires content_type beside content_ids and PayloadBuilder adds
        // it server-side; mirror that so the browser twin matches.
        if ($arContentIds !== [] || ($arData['content_type'] ?? null) === 'product') {
            $arResult['content_type'] = 'product';
        }
        $iNumItems = $this->readNumItems($arData['num_items'] ?? null);
        if ($iNumItems !== null) {
            $arResult['num_items'] = $iNumItems;
        }

        return $arResult;
    }

    /** Trimmed, non-empty, truncated to the length cap; null when unusable. */
    private function readSearchString(mixed $mValue): ?string
    {
        if (! is_string($mValue)) {
            return null;
        }
        $sTrimmed = trim($mValue);
        if ($sTrimmed === '') {
            return null;
        }

        return mb_substr($sTrimmed, 0, self::SEARCH_STRING_MAX_LENGTH);
    }

    /**
     * Keep only catalog-feed shaped ids (SKU-{product}[-{offer}]), capped in count.
     *
     * @return list<string>
     */
    private function readContentIds(mixed $mValue): array
    {
        if (! is_array($mValue)) {
            return [];
        }
        $arResult = [];
        foreach ($mValue as $mId) {
            if (is_string($mId) && preg_match(self::CONTENT_ID_PATTERN, $mId) === 1) {
                $arResult[] = $mId;
            }
        }

        return array_slice($arResult, 0, self::CONTENT_IDS_MAX);
    }

    /** Non-negative int within the cap; null when absent or out of range. */
    private function readNumItems(mixed $mValue): ?int
    {
        if (! is_numeric($mValue)) {
            return null;
        }
        $iValue = (int) $mValue;

        return $iValue >= 0 && $iValue <= self::NUM_ITEMS_MAX ? $iValue : null;
    }

    /**
     * Server-derived Meta CAPI user_data + site context for the theme-action
     * and generic hybrid paths. Mirrors PixelHead::collectRequestUserData —
     * without at least one customer-info parameter Meta rejects the event
     * (HTTP 400 subcode 2804050). site_id is baked in-request so queue-side
     * ThemeActionAdapter getSiteId never falls back to the worker's CLI site
     * context.
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
     * Merge server-captured passthrough user_data, the hashed identity from
     * metapixel.user_data.resolve and the event_source_url into a built CAPI
     * payload. An anonymous subject (the documented guest-order adapter
     * pattern) yields all-null user_data from the hasher; without the
     * request-context bridge Meta rejects the event with HTTP 400 subcode
     * 2804050 and it permanently dead-letters. site_id is excluded: hybrid
     * subjects are adapter-loaded and getSiteId reads from the subject, never
     * from request context. Adapter-supplied non-null values win, mirroring
     * CapturesRequestUserData::injectRequestUserData.
     *
     * @param  array<string, mixed>  $arPayload  output of PayloadBuilder::buildEventPayload
     * @return array<string, mixed>
     */
    public function injectServerUserData(string $sEventName, string $sSubjectType, array $arPayload): array
    {
        $arServerData = $this->collectServerUserData();
        unset($arServerData['site_id']);
        /** @var UserDataResolveHook $obResolveHook */
        $obResolveHook = App::make(UserDataResolveHook::class);

        return $obResolveHook->mergeIntoPayload($sEventName, $sSubjectType, $arPayload, $arServerData);
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

        foreach (['name', 'subject_type', 'subject_id', 'offer_id', 'action_key', 'search_string', 'content_ids', 'content_type', 'num_items'] as $sField) {
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
