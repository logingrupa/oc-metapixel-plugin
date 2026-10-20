<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Models\Settings;
use October\Rain\Support\Facades\Site;

/**
 * One cached snapshot of every metapixel system_settings row, shared by web
 * requests and queue workers. Settings::clearCache() forgets it on every save
 * and reset; a direct SQL edit shows after TTL_SECONDS or a cache:forget of CACHE_KEY.
 */
final class SettingsRowCache
{
    public const CACHE_KEY = 'logingrupa.metapixel.settings_rows';

    public const TTL_SECONDS = 3600;

    /**
     * Raw rows ordered by id, the value column still JSON.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, static fn (): array => self::queryRows());
    }

    /**
     * Decoded values keyed by site_id (int per site, '' for the site_id IS NULL
     * row; the first row by id wins per key).
     *
     * @return array<int|string, array<string, mixed>>
     */
    public static function valuesBySite(): array
    {
        $arValueList = [];
        foreach (self::rows() as $arRow) {
            $mSiteId = $arRow['site_id'] ?? null;
            $mKey = is_numeric($mSiteId) ? (int) $mSiteId : '';
            if (! array_key_exists($mKey, $arValueList)) {
                $arValueList[$mKey] = self::decodeValue($arRow['value'] ?? null);
            }
        }

        return $arValueList;
    }

    /**
     * The row SettingModel's MultisiteScope query would fetch: the first row in
     * global context, else the first row of the context site (a null site
     * matches the site_id IS NULL row).
     *
     * @return array<string, mixed>|null
     */
    public static function rowForContext(): ?array
    {
        $arRowList = self::rows();
        if (Site::hasGlobalContext()) {
            return $arRowList[0] ?? null;
        }

        $mContextSiteId = Site::getSiteIdFromContext();
        foreach ($arRowList as $arRow) {
            $mSiteId = $arRow['site_id'] ?? null;
            if ((is_numeric($mSiteId) ? (int) $mSiteId : null) === $mContextSiteId) {
                return $arRow;
            }
        }

        return null;
    }

    /**
     * Drop the cached snapshot so the next read queries system_settings.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The metapixel system_settings rows ordered by id.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryRows(): array
    {
        $arRowList = [];
        foreach (DB::table('system_settings')->where('item', Settings::SETTINGS_CODE)->orderBy('id')->get() as $obRow) {
            /** @var array<string, mixed> $arRow */
            $arRow = (array) $obRow;
            $arRowList[] = $arRow;
        }

        return $arRowList;
    }

    /**
     * Decoder for the system_settings JSON expando column.
     *
     * @return array<string, mixed>
     */
    private static function decodeValue(mixed $mStored): array
    {
        $mDecoded = is_string($mStored) ? json_decode($mStored, true) : null;
        if (! is_array($mDecoded)) {
            return [];
        }

        $arValue = [];
        foreach ($mDecoded as $mKey => $mValue) {
            $arValue[(string) $mKey] = $mValue;
        }

        return $arValue;
    }
}
