<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\Settings;

/**
 * Boot-time and event-time guard. Empty pixel_id → log + disable; never throws.
 *
 * Throwing at boot would cascade through OctoberCMS' plugin chain and break
 * unrelated plugins (Campaigns, PromoMechanism, etc). We disable softly here
 * and surface one Log::warning per day via the cache.
 */
final class PluginGuard
{
    public const WARNING_CACHE_KEY = 'logingrupa.metapixel.disabled_warning';

    private const WARNING_INTERVAL_SECONDS = 86400;

    private static ?bool $bIsDisabled = null;

    /**
     * Returns true when pixel_id is empty (events suppressed); false otherwise.
     * Memoised per request; the Log::warning fires at most once per day.
     */
    public static function isDisabled(): bool
    {
        if (self::$bIsDisabled !== null) {
            return self::$bIsDisabled;
        }

        $mPixelId = Settings::get('pixel_id', '');
        $sPixelId = is_string($mPixelId) ? $mPixelId : '';
        if ($sPixelId === '') {
            self::warnOncePerDay();

            return self::$bIsDisabled = true;
        }

        return self::$bIsDisabled = false;
    }

    /**
     * Cache::add writes only when the key is absent, so the warning lands
     * once per interval across every request and worker on the host.
     */
    private static function warnOncePerDay(): void
    {
        if (! Cache::add(self::WARNING_CACHE_KEY, 1, self::WARNING_INTERVAL_SECONDS)) {
            return;
        }

        Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)');
    }

    /**
     * Clears the memoised disabled flag. Tests call this in setUp().
     */
    public static function reset(): void
    {
        self::$bIsDisabled = null;
    }
}
