<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Meta\CredentialFormat;
use Logingrupa\Metapixel\Models\Settings;
use October\Rain\Support\Facades\Site;
use Throwable;

/**
 * Per-site guard. Empty or malformed credentials disable the plugin for that
 * site; never throws. Throwing at boot would cascade through OctoberCMS' plugin
 * chain, so one Log::warning per day surfaces the disabled state instead.
 */
final class PluginGuard
{
    public const WARNING_CACHE_KEY = 'logingrupa.metapixel.disabled_warning';

    private const WARNING_INTERVAL_SECONDS = 86400;

    /** @var array<int, bool> */
    private static array $arDisabledBySite = [];

    /**
     * True in October backend context (a frontend 404 under the backend uri
     * resolves the edit site), else the answer for the site in context.
     */
    public static function isDisabled(): bool
    {
        if (App::runningInBackend()) {
            return true;
        }

        $mSiteId = Site::getSiteIdFromContext();

        return self::isDisabledForSite(is_int($mSiteId) && $mSiteId > 0 ? $mSiteId : null);
    }

    /**
     * True unless the site resolves a digits-only pixel_id and a token that is
     * empty or starts with EAA. Memoised per site id; a read failure is not.
     */
    public static function isDisabledForSite(?int $iSiteId): bool
    {
        $iKey = $iSiteId ?? 0;
        if (array_key_exists($iKey, self::$arDisabledBySite)) {
            return self::$arDisabledBySite[$iKey];
        }

        try {
            $arCredentials = Settings::lookupForSite($iSiteId);
        } catch (Throwable $obException) {
            // Boundary fail-safe: a settings read failure must not break page render or boot.
            Log::warning('metapixel: settings lookup failed, plugin disabled for this call', [
                'exception' => get_class($obException),
            ]);

            return true;
        }

        $sToken = $arCredentials['capi_access_token'];
        $bIsDisabled = ! CredentialFormat::isPixelId($arCredentials['pixel_id'])
            || ($sToken !== '' && ! CredentialFormat::isAccessToken($sToken));
        if ($bIsDisabled) {
            self::warnOncePerDay($iSiteId);
        }

        return self::$arDisabledBySite[$iKey] = $bIsDisabled;
    }

    /**
     * Cache::add writes only when the key is absent, so the warning lands
     * once per interval across every request and worker on the host.
     */
    private static function warnOncePerDay(?int $iSiteId): void
    {
        try {
            if (! Cache::add(self::WARNING_CACHE_KEY, 1, self::WARNING_INTERVAL_SECONDS)) {
                return;
            }

            $sSubject = $iSiteId === null ? 'default row' : 'site '.$iSiteId;
            Log::warning('metapixel: '.$sSubject.' disabled - pixel_id must be digits only and the access token empty or starting with EAA (events suppressed)');
        } catch (Throwable) {
            // Best-effort warning: a cache or log failure must not break page render.
        }
    }

    /**
     * Clears the memoised answers. Tests call this in setUp().
     */
    public static function reset(): void
    {
        self::$arDisabledBySite = [];
    }
}
