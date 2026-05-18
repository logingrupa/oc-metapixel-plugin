<?php

namespace Logingrupa\Metapixel\Models;

use Flash;
use Lovata\Toolbox\Models\CommonSettings;

/**
 * Plugin settings (single-row in Phase 2). Multisite per-field whitelist on
 * pixel_id + capi_access_token lands in Phase 4 (MULT-01..02).
 *
 * lookupForSite is the credential-lookup contract callers (SendCapiEvent::handle)
 * use. Phase 2 stub returns the default row regardless of $iSiteId; Phase 4
 * MULT-03 re-implements to honor the Multisite per-site row routing.
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

    /** @var list<string> */
    protected $propagatable = [];

    /**
     * Multisite-aware credential lookup. Phase 2 stub ignores $iSiteId.
     *
     * @return array{pixel_id: string, capi_access_token: string}
     */
    public static function lookupForSite(?int $iSiteId): array
    {
        $mPixelId = self::get('pixel_id', '');
        $mCapiToken = self::get('capi_access_token', '');

        return [
            'pixel_id' => is_string($mPixelId) ? $mPixelId : '',
            'capi_access_token' => is_string($mCapiToken) ? $mCapiToken : '',
        ];
    }

    /**
     * Sanitize operator-supplied theme_custom_event_names — split by newline,
     * trim, drop entries that fail /^[A-Za-z0-9_]{1,50}$/, flash a warning
     * listing dropped values. Idempotent on already-clean input.
     */
    public function beforeSave(): void
    {
        // Storage path B verified against modules/system/models/SettingModel.php
        // + vendor/october/rain/src/Database/ExpandoModel.php — beforeSave runs
        // before expandoBeforeSaveDone (priority -1) packs attributes into the
        // `value` JSON column; reads/writes go through standard Eloquent
        // attribute access here.
        $arLines = $this->splitEventNameInput($this->getAttribute('theme_custom_event_names'));
        if ($arLines === null) {
            return;
        }

        [$arClean, $arDropped] = $this->partitionEventNames($arLines);
        $this->setAttribute('theme_custom_event_names', $arClean);

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
     * list<string> with no malformed entries (beforeSave guarantees cleanliness
     * at save time; this method is also defensive at read time).
     *
     * @return list<string>
     */
    public static function getThemeCustomEventNames(): array
    {
        $mList = self::get('theme_custom_event_names', []);
        if (! is_array($mList)) {
            return [];
        }
        $arResult = [];
        foreach ($mList as $mItem) {
            if (is_string($mItem) && preg_match('/^[A-Za-z0-9_]{1,50}$/', $mItem) === 1) {
                $arResult[] = $mItem;
            }
        }

        return $arResult;
    }
}
