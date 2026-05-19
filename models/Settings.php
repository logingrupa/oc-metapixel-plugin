<?php

namespace Logingrupa\Metapixel\Models;

use Flash;
use Lovata\Toolbox\Models\CommonSettings;
use October\Rain\Support\Facades\Site;

/**
 * Plugin settings model. Per-site credentials route through the Multisite
 * trait inherited from CommonSettings; $propagatable = [] locks pixel_id +
 * capi_access_token out of the cross-site whitelist (D-02 / D-20).
 *
 * @method static mixed get(string $sCode, mixed $mDefault = null)
 * @method static void set(array<string, mixed> $arValues)
 * @method static list<string> getThemeCustomEventNames()
 */
class Settings extends CommonSettings
{
    /** @var string */
    public $settingsCode = 'logingrupa_metapixel_settings';

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /**
     * Explicit empty whitelist (D-02 / D-20 marketplace audit anchor). MUST
     * stay at the descendant level so a future trait or parent change cannot
     * silently widen the propagatable set.
     *
     * @var list<string>
     */
    protected $propagatable = [];

    /**
     * Multisite-aware credential lookup. Returns per-site row when set;
     * silently falls back to the default-row value for any empty per-site
     * pixel_id / capi_access_token (D-01).
     *
     * @return array{pixel_id: string, capi_access_token: string}
     */
    public static function lookupForSite(?int $iSiteId): array
    {
        [$sDefaultPixel, $sDefaultToken] = self::readCredentialsInGlobalContext();

        if ($iSiteId === null) {
            return [
                'pixel_id' => $sDefaultPixel,
                'capi_access_token' => $sDefaultToken,
            ];
        }

        [$sSitePixel, $sSiteToken] = self::readCredentialsForSiteContext($iSiteId);

        return [
            'pixel_id' => $sSitePixel !== '' ? $sSitePixel : $sDefaultPixel,
            'capi_access_token' => $sSiteToken !== '' ? $sSiteToken : $sDefaultToken,
        ];
    }

    /**
     * Read default-row credentials inside a global site context. clearInternalCache
     * busts SettingModel::$instances so the closure sees a fresh resolved
     * instance for the global scope. clearCache also forgets the per-key
     * Cache facade entry — without it, the QueryBuilder remember(1440) hit
     * keeps returning whichever row was last read under the same cache key
     * (Pitfall 1 across Site::withContext switches).
     *
     * @return array{0: string, 1: string}
     */
    private static function readCredentialsInGlobalContext(): array
    {
        return Site::withGlobalContext(static function (): array {
            self::clearInternalCache();
            (new self)->clearCache();
            $mPixel = self::get('pixel_id', '');
            $mToken = self::get('capi_access_token', '');

            return [
                is_string($mPixel) ? $mPixel : '',
                is_string($mToken) ? $mToken : '',
            ];
        });
    }

    /**
     * Read per-site row credentials inside Site::withContext. clearInternalCache
     * + clearCache MUST run INSIDE the closure (Pitfall 1) — without both,
     * the QueryBuilder remember() cache + the SettingModel::$instances static
     * cache combine to return stale credentials across context switches.
     *
     * @return array{0: string, 1: string}
     */
    private static function readCredentialsForSiteContext(int $iSiteId): array
    {
        return Site::withContext($iSiteId, static function (): array {
            self::clearInternalCache();
            (new self)->clearCache();
            $mPixel = self::get('pixel_id', '');
            $mToken = self::get('capi_access_token', '');

            return [
                is_string($mPixel) ? $mPixel : '',
                is_string($mToken) ? $mToken : '',
            ];
        });
    }

    /**
     * Sanitize operator-supplied theme_custom_event_names — split by newline,
     * trim, drop entries that fail /^[A-Za-z0-9_]{1,50}$/, flash a warning
     * listing dropped values. Idempotent on already-clean input.
     */
    public function beforeSave(): void
    {
        $arLines = $this->splitEventNameInput($this->getAttribute('theme_custom_event_names'));
        if ($arLines === null) {
            return;
        }

        [$arClean, $arDropped] = $this->partitionEventNames($arLines);
        $this->setAttribute('theme_custom_event_names', implode("\n", $arClean));

        if ($arDropped !== []) {
            Flash::warning('metapixel: dropped invalid event names: '.implode(', ', $arDropped));
        }
    }

    /**
     * Normalize the raw stored value (string textarea OR array passthrough) to
     * a list of candidate lines. Returns null when the value is neither shape
     * (signals beforeSave to no-op early).
     *
     * @return list<mixed>|null
     */
    private function splitEventNameInput(mixed $mValue): ?array
    {
        if (is_array($mValue)) {
            return array_values($mValue);
        }
        if (! is_string($mValue)) {
            return null;
        }
        $mLines = preg_split('/\R/', $mValue);

        return $mLines === false ? [] : $mLines;
    }

    /**
     * Split candidates into (kept, dropped) lists by the alpha-num+underscore
     * 1..50-char regex. Skips empty/non-string entries silently.
     *
     * @param  list<mixed>  $arLines
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionEventNames(array $arLines): array
    {
        $arClean = [];
        $arDropped = [];
        foreach ($arLines as $mLine) {
            $sTrimmed = is_string($mLine) ? trim($mLine) : '';
            if ($sTrimmed === '') {
                continue;
            }
            $bMatches = preg_match('/^[A-Za-z0-9_]{1,50}$/', $sTrimmed) === 1;
            $bMatches ? $arClean[] = $sTrimmed : $arDropped[] = $sTrimmed;
        }

        return [$arClean, $arDropped];
    }

    /**
     * Sanitized list of operator-supplied custom event names. Always returns
     * list<string> with no malformed entries (beforeSave persists a
     * newline-joined string; this getter explodes + trims + regex-filters).
     * Tolerates legacy array shape (pre-Gap-3 fix) for one-shot data migration.
     *
     * @return list<string>
     */
    public static function getThemeCustomEventNames(): array
    {
        $mList = self::get('theme_custom_event_names', '');

        if (is_array($mList)) {
            $arCandidates = $mList;
        } elseif (is_string($mList)) {
            $arParts = preg_split('/\R/', $mList);
            $arCandidates = $arParts === false ? [] : $arParts;
        } else {
            return [];
        }

        $arResult = [];
        foreach ($arCandidates as $mItem) {
            $sTrim = is_string($mItem) ? trim($mItem) : '';
            if ($sTrim !== '' && preg_match('/^[A-Za-z0-9_]{1,50}$/', $sTrim) === 1) {
                $arResult[] = $sTrim;
            }
        }

        return $arResult;
    }
}
