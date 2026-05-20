<?php

namespace Logingrupa\Metapixel\Models;

use Flash;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Lovata\Toolbox\Models\CommonSettings;
use October\Rain\Database\ModelException;

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
     * Read the default-row credentials via a direct DB query. Prefers a row
     * with site_id IS NULL (explicitly seeded inside Site::withGlobalContext
     * by an operator who wants a shared fallback); when no such row exists
     * (single-site installs that always save under the active site), falls
     * back to the first row matching the settings code so credentials still
     * route correctly. Bypasses the SettingModel cache layer (static
     * $instances + Cache::remember) so context-aware reads inside
     * lookupForSite are not confused by getCacheKey() sharing across
     * Site::withGlobalContext / Site::withContext switches.
     *
     * @return array{0: string, 1: string}
     */
    private static function readCredentialsInGlobalContext(): array
    {
        $arNullSite = self::readCredentialsFromRow(static fn ($obQuery) => $obQuery->whereNull('site_id'));
        if ($arNullSite[0] !== '' || $arNullSite[1] !== '') {
            return $arNullSite;
        }

        // No explicit default row — fall back to the first row for this
        // settings code so single-site installs (which save under the active
        // site, not in withGlobalContext) still resolve credentials.
        return self::readCredentialsFromRow(static fn ($obQuery) => $obQuery);
    }

    /**
     * Read per-site row credentials by direct DB query. Same rationale as
     * readCredentialsInGlobalContext — bypass the SettingModel cache. The
     * cache leak across context switches makes credential routing unsafe
     * for marketplace operators running multiple sites (P-10).
     *
     * @return array{0: string, 1: string}
     */
    private static function readCredentialsForSiteContext(int $iSiteId): array
    {
        return self::readCredentialsFromRow(static fn ($obQuery) => $obQuery->where('site_id', $iSiteId));
    }

    /**
     * Shared decoder for the system_settings JSON expando column. $fnFilter
     * narrows the row scope (whereNull('site_id') for default, where('site_id', $iSiteId)
     * for per-site).
     *
     * @param  callable(Builder): Builder  $fnFilter
     * @return array{0: string, 1: string}
     */
    private static function readCredentialsFromRow(callable $fnFilter): array
    {
        $obQuery = DB::table('system_settings')
            ->where('item', (new self)->settingsCode);
        $obQuery = $fnFilter($obQuery);
        $obRow = $obQuery->first();
        if ($obRow === null || ! is_string($obRow->value ?? null)) {
            return ['', ''];
        }
        $mDecoded = json_decode($obRow->value, true);
        if (! is_array($mDecoded)) {
            return ['', ''];
        }
        $mPixel = $mDecoded['pixel_id'] ?? '';
        $mToken = $mDecoded['capi_access_token'] ?? '';

        return [
            is_string($mPixel) ? $mPixel : '',
            is_string($mToken) ? $mToken : '',
        ];
    }

    /**
     * Sanitize operator-supplied trusted_hosts + theme_custom_event_names.
     * trusted_hosts halts the save on any unknown-TLD / charset-violating
     * line (D-14 strict); theme_custom_event_names drops invalids with a
     * Flash::warning (pre-existing pattern).
     */
    public function beforeSave(): void
    {
        $this->beforeSaveTrustedHosts();
        $this->beforeSaveThemeCustomEventNames();
    }

    /**
     * D-14 strict halt — partition trusted_hosts into clean / rejected lines;
     * a non-empty rejected set throws ModelException after flashing the list
     * of bad hosts to the operator. Idempotent on already-clean input.
     */
    private function beforeSaveTrustedHosts(): void
    {
        $arLines = $this->splitHostInput($this->getAttribute('trusted_hosts'));
        if ($arLines === null) {
            return;
        }

        [$arClean, $arRejected] = $this->partitionHosts($arLines);

        if ($arRejected !== []) {
            $sMessage = 'metapixel: rejected trusted_hosts (unknown TLD or invalid charset): '
                .implode(', ', $arRejected);
            Flash::error($sMessage);

            // Tiger-Style fail-fast: halt the save at the boundary so the
            // operator gets immediate feedback. Untrusted hosts saved would
            // silently break cookies at request time (P-15 anchor).
            throw new ModelException($this);
        }

        $this->setAttribute('trusted_hosts', implode("\n", $arClean));
    }

    private function beforeSaveThemeCustomEventNames(): void
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
     * Normalize the raw trusted_hosts textarea value (string OR array shape)
     * to a list of candidate lines. Returns null when the value is neither
     * shape (signals beforeSave to no-op early).
     *
     * @return list<mixed>|null
     */
    private function splitHostInput(mixed $mValue): ?array
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
     * Partition trusted_hosts candidates via the basic charset gate (D-14)
     * + the PSL-wrapped HostIndexResolver. Unknown-TLD or charset-violating
     * lines are pushed to the rejected list; clean lines are normalised to
     * lowercase + trimmed.
     *
     * @param  list<mixed>  $arLines
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionHosts(array $arLines): array
    {
        $obResolver = App::make(HostIndexResolver::class);
        $arClean = [];
        $arRejected = [];
        foreach ($arLines as $mLine) {
            $sHost = is_string($mLine) ? strtolower(trim($mLine)) : '';
            if ($sHost === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9.-]+$/', $sHost) !== 1) {
                $arRejected[] = $sHost;

                continue;
            }
            if ($obResolver->resolve($sHost) === null) {
                $arRejected[] = $sHost;

                continue;
            }
            $arClean[] = $sHost;
        }

        return [$arClean, $arRejected];
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
