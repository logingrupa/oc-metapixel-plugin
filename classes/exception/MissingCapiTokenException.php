<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown event-time by `MetaClient::send()` (plan 03-03) when
 * `Settings::get('capi_access_token', '')` returns empty/non-scalar. CAPI
 * dispatch is impossible without a token, so this is a permanent failure
 * (no retry).
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.missing_capi_token`.
 */
final class MissingCapiTokenException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
