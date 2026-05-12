<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown event-time by `MetaClient::send()` (plan 03-03) when
 * `Settings::get('pixel_id', '')` returns empty/non-scalar. Boot-time missing
 * pixel_id triggers `PluginGuard` disabled flag instead (SKEL-05 contract);
 * this exception covers the race where pixel_id is cleared after boot.
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.missing_pixel_config`.
 */
final class MissingPixelConfigException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
