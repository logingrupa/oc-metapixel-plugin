<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown by `MetaClient::send()` (plan 03-03) for permanent failures — Graph
 * API HTTP 4xx (except 408 and 429), malformed payload rejections, revoked
 * CAPI token. The matching plan 03-05 `SendCapiEvent::handle()` catch
 * persists a FailedEvent row via
 * `FailedEvent::createFromPayloadAndException` and does NOT rethrow — the
 * job is marked succeeded so the queue worker does not park.
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.meta_api_permanent`.
 */
final class MetaApiPermanentException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
