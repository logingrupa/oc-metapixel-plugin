<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;
use Throwable;

/**
 * Laravel 12 `ShouldQueue` job — the bridge between every Phase 3+ CAPI
 * dispatch site (OrderStatusWatcher Purchase plan 03-06, Phase 4 funnel
 * events) and the `MetaClient` HTTP boundary.
 *
 * Retry contract (CONTEXT Area 1 Q2):
 *   - `$tries = 3` — Laravel makes at most 3 attempts on a transient failure.
 *   - `$backoff = [1, 4, 16]` — exponential backoff in seconds, indexed by
 *     (attempt - 1). Re-throw `MetaApiTransientException` in `handle()` to
 *     trigger Laravel's built-in retry mechanism.
 *
 * Dead-letter contract (CONTEXT Area 1 Q2 + PATTERNS lines 329-336):
 *   - `MetaApiPermanentException` (HTTP 4xx classification) → write FailedEvent
 *     row + return (no rethrow). The job is marked succeeded so the queue
 *     worker never parks on an un-recoverable failure.
 *   - `MissingPixelConfigException` + `MissingCapiTokenException` → also
 *     dead-lettered through the same multi-catch branch. CONTEXT Area 1 Q4
 *     classes these as permanent (`isRetryable() === false`).
 *   - `failed()` hook fires after `$tries` exhaustion on a re-thrown transient
 *     — also writes a FailedEvent row (transient-exhausted case).
 *
 * Idempotency (CONTEXT Area 1 Q3): the `meta_purchase_event_id` DB column on
 * `lovata_orders_shopaholic_orders` (plan 03-01 PAY-04) is the canonical fence
 * at the dispatch site. NO `ShouldBeUniqueUntilProcessing` here — two equal
 * payloads cannot be dispatched because the UUID generation in
 * OrderStatusWatcher (plan 03-06) is fenced by `meta_purchase_event_id IS NULL`.
 *
 * Tiger-Style: exactly one silent catch (writeFailedEvent's DB-write guard
 * documented inline; rethrowing would cause Laravel to retry an already-
 * permanent failure or cascade a DB outage onto the dead-letter path).
 * Every other catch logs + classifies and either rethrows (transient → retry)
 * or writes FailedEvent (permanent → dead-letter).
 *
 * Threat model:
 *   - T-03-21 (DoS via infinite retry on misconfigured Settings): mitigated by
 *     MissingPixelConfigException + MissingCapiTokenException being classed
 *     permanent in the multi-catch.
 *   - T-03-22 (worker park from cascading DB-write failure): mitigated by the
 *     silent catch in writeFailedEvent.
 *   - T-03-23 ($arPayload mutation across retries): mitigated by
 *     `public readonly array $arPayload` — PHP 8.4 readonly prevents mutation.
 *   - T-03-24 (logging the access_token): mitigated — payload contains BODY
 *     only; access_token lives in the query string built by MetaClient.
 */
final class SendCapiEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Max attempts before failed() hook fires (CONTEXT Area 1 Q2). */
    public int $tries = 3;

    /** @var array<int, int> Exponential backoff in seconds, indexed by (attempt - 1). */
    public array $backoff = [1, 4, 16];

    /**
     * @param  string  $sEventName  Meta event name (Purchase, ViewContent, AddToCart, ...).
     * @param  array<string, mixed>  $arPayload  Envelope built by PayloadBuilder
     *                                            (`['data' => [['event_id' => ..., ...]]]`).
     */
    public function __construct(
        public readonly string $sEventName,
        public readonly array $arPayload,
    ) {
    }

    /**
     * Send the CAPI payload through MetaClient. Transient → rethrow for Laravel
     * retry. Permanent / missing-config → write FailedEvent + return.
     *
     * @param  MetaClient  $obClient  Container-resolved via type-hint.
     */
    public function handle(MetaClient $obClient): void
    {
        try {
            $obClient->send($this->arPayload);

            Log::info('Metapixel CAPI dispatched successfully', $this->buildLogContext());
        } catch (MetaApiTransientException $obException) {
            // Re-throw so Laravel retries per $tries + $backoff (CONTEXT Area 1 Q2).
            // After exhaustion, failed() writes the FailedEvent.
            Log::warning('Metapixel CAPI transient failure — will retry', $this->buildLogContext([
                'meta_pixel.http_status' => $obException->arContext['http_status'] ?? null,
                'meta_pixel.attempt' => $this->attempts(),
            ]));
            throw $obException;
        } catch (MetaApiPermanentException | MissingPixelConfigException | MissingCapiTokenException $obException) {
            // Permanent failure: persist + no rethrow. Job marked succeeded so the
            // queue worker doesn't park. CONTEXT Area 1 Q2 + PATTERNS lines 329-336.
            $this->writeFailedEvent($obException);
            Log::error('Metapixel CAPI permanent failure — dead-lettered', $this->buildLogContext([
                'meta_pixel.http_status' => $obException->arContext['http_status'] ?? null,
                'meta_pixel.exception' => get_class($obException),
            ]));
        }
    }

    /**
     * Laravel's $tries-exhausted hook. Called once after the final attempt
     * fails on a rethrown exception. Persists a FailedEvent row regardless of
     * exception type because by definition we've tried our best.
     */
    public function failed(Throwable $obException): void
    {
        if ($obException instanceof MetaPixelException) {
            $this->writeFailedEvent($obException);
        } else {
            // Unexpected non-Meta exception (DB error, container resolution failure,
            // serialisation error, etc). Wrap into a permanent for the audit row so
            // the FailedEvent type contract still holds.
            $this->writeFailedEvent(new MetaApiPermanentException(
                'Unexpected exception during CAPI dispatch: '.$obException->getMessage(),
                ['attempts' => $this->tries, 'original_class' => get_class($obException)],
                $obException,
            ));
        }

        Log::error('Metapixel CAPI dispatch exhausted retries — dead-lettered', $this->buildLogContext([
            'meta_pixel.attempts' => $this->tries,
            'meta_pixel.exception' => get_class($obException),
        ]));
    }

    /**
     * Persist a FailedEvent row from the current payload + the terminating
     * exception. DB-write failure is absorbed by a documented silent catch
     * (Tiger-Style exception — rethrowing during dead-letter would cause
     * Laravel to retry an already-permanent failure or cascade a DB outage).
     */
    private function writeFailedEvent(MetaPixelException $obException): void
    {
        try {
            FailedEvent::createFromPayloadAndException($this->arPayload, $obException);
        } catch (Throwable $obDbException) {
            // silent: dead-letter persistence failure — log critical only, do not
            // rethrow. Rethrowing would cause Laravel to retry an already-permanent
            // failure (T-03-22 mitigation).
            Log::critical('Metapixel FailedEvent persistence FAILED', $this->buildLogContext([
                'meta_pixel.original_exception' => $obException->getMessage(),
                'meta_pixel.db_exception' => $obDbException->getMessage(),
            ]));
        }
    }

    /**
     * Build a `meta_pixel.*`-namespaced log context array (CONTEXT Discretion #9).
     *
     * @param  array<string, mixed>  $arExtra
     * @return array<string, mixed>
     */
    private function buildLogContext(array $arExtra = []): array
    {
        $mxData = $this->arPayload['data'] ?? null;
        $mxFirst = is_array($mxData) ? ($mxData[0] ?? null) : null;
        $mxEventId = is_array($mxFirst) ? ($mxFirst['event_id'] ?? null) : null;

        return array_merge([
            'meta_pixel.event_name' => $this->sEventName,
            'meta_pixel.event_id' => is_scalar($mxEventId) ? (string) $mxEventId : null,
        ], $arExtra);
    }
}
