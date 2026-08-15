<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Browser-pixel counterpart of metapixel.event.before_dispatch. That hook
 * fires in the CAPI queue job and never sees the custom_data a request-time
 * fbq() block renders, so a listener mutating CAPI values (e.g. a margin
 * rewrite) would desync the browser twin. Call sites pass their custom_data
 * through apply() right before FbqScriptBuilder::build.
 *
 * Listeners receive [string $sEventName, array &$arCustomData]. event_id is
 * deliberately not exposed (dedup contract anchor). Contentless blocks skip
 * the hook: there is no value to mutate.
 */
final class PixelRenderHook
{
    public const HOOK_BEFORE_RENDER = 'metapixel.pixel.before_render';

    /**
     * Fire the hook and return the possibly-mutated custom_data.
     *
     * @param  array<string, mixed>  $arCustomData
     * @return array<string, mixed>
     */
    public static function apply(string $sEventName, array $arCustomData): array
    {
        if ($arCustomData === []) {
            return $arCustomData;
        }

        try {
            Event::fire(self::HOOK_BEFORE_RENDER, [$sEventName, &$arCustomData]);
        } catch (Throwable $obException) {
            // Tiger-Style boundary: a throwing listener abstains — the pixel
            // block renders unmutated rather than not at all.
            Log::warning('metapixel: pixel.before_render listener threw — rendering unmutated custom_data', [
                'meta_pixel.event_name' => $sEventName,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }

        return $arCustomData;
    }
}
