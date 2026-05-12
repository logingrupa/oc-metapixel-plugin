<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown by `MetaClient::send()` (plan 03-03) when Graph API returns HTTP
 * 408 / 429 / 500 / 502 / 503 / 504, or when
 * `GuzzleHttp\Exception\ConnectException` is raised (network failure). The
 * matching plan 03-05 `SendCapiEvent::handle()` catch re-throws this so
 * Laravel's queue worker honours `$tries = 3` + `$backoff = [1, 4, 16]`.
 * After exhaustion, the job is auto-failed → `failed()` hook persists a
 * FailedEvent row via `FailedEvent::createFromPayloadAndException`.
 *
 * The ONLY Phase-3 concrete exception whose `isRetryable()` returns true.
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.meta_api_transient`.
 */
final class MetaApiTransientException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
