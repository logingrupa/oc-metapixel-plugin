<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\Settings;

/**
 * Boot-time and event-time guard. Empty pixel_id → log + disable; never throws.
 *
 * Throwing at boot would cascade through OctoberCMS' plugin chain and break
 * unrelated plugins (Campaigns, PromoMechanism, etc). We disable softly here
 * and surface a single Log::warning per request via the memo.
 */
final class PluginGuard
{
    private static ?bool $bIsDisabled = null;

    /**
     * Returns true when pixel_id is empty (events suppressed); false otherwise.
     * Memoised — the empty-check + Log::warning fires at most once per request.
     */
    public static function isDisabled(): bool
    {
        if (self::$bIsDisabled !== null) {
            return self::$bIsDisabled;
        }

        $mPixelId = Settings::get('pixel_id', '');
        $sPixelId = is_string($mPixelId) ? $mPixelId : '';
        if ($sPixelId === '') {
            Log::warning('metapixel: pixel_id is empty — plugin running in disabled mode (events suppressed)');

            return self::$bIsDisabled = true;
        }

        return self::$bIsDisabled = false;
    }

    /**
     * Clears the memoised disabled flag. Tests call this in setUp().
     */
    public static function reset(): void
    {
        self::$bIsDisabled = null;
    }
}
