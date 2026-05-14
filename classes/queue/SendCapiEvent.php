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
use Logingrupa\Metapixelshopaholic\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;
use Lovata\OrdersShopaholic\Models\Order;
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
 * Idempotency / race-fence (Phase 3.1 REFAC-06): the legacy column-CAS on
 * Lovata's `orders` table is GONE. The race fence now lives on the
 * plugin-owned `logingrupa_metapixel_event_log` table via the 5-column
 * UNIQUE constraint (subject_type, subject_id, event_name, channel,
 * site_id). `handle()` calls `EventLogWriter::record(...)` BEFORE
 * `MetaClient::send`. `insertOrIgnore` returns 1 = race winner (proceed
 * with POST) or 0 = race loser (peer already POSTed — log INFO + return,
 * no HTTP traffic).
 *
 * v1.1.0 BREAKING CHANGE (SCE-03 deliberately broken): the Phase-3 signature
 * `__construct(string $sEventName, array $arPayload)` adds a required third
 * parameter `Order $obSubject`. Subject is needed by `EventLogWriter::record`
 * to populate the polymorphic `subject_type` + `subject_id` columns AND the
 * `secret_key` for the future `/checkout/{slug}` lookup path. `Order` is
 * `SerializesModels`-compatible (the trait we already use), so the readonly
 * Order property survives queue rehydrate cleanly. No call sites outside
 * this plugin instantiate `SendCapiEvent`; the v1.1.0 minor version bump
 * (Wave-1 `updates/version.yaml`) documents the break.
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
 *   - T-3.1-12 (constructor break): accepted — v1.1.0 documents the break.
 *   - T-3.1-13 (race-fence repudiation): mitigated — EventLog UNIQUE row +
 *     fired_at timestamp + UUID event_id form the append-only audit trail.
 *   - T-3.1-14 (DoS on hot path): mitigated — `insertOrIgnore` is a single
 *     round-trip; latency comparable to the legacy column-CAS UPDATE.
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
     *                                           (`['data' => [['event_id' => ..., ...]]]`).
     * @param  Order  $obSubject   Polymorphic subject for the EventLog race-fence row
     *                             (v1.1.0 BREAKING — Phase 3.1 REFAC-06). Order is
     *                             `SerializesModels`-compatible so the readonly property
     *                             survives queue rehydrate.
     */
    public function __construct(
        public readonly string $sEventName,
        public readonly array $arPayload,
        public readonly Order $obSubject,
    ) {}

    /**
     * Race-fence pre-call → MetaClient HTTP POST → transient/permanent
     * exception routing. Race-fence loss (peer already POSTed) returns
     * without calling `$obClient->send`. Transient → rethrow. Permanent /
     * missing-config → write FailedEvent + return.
     *
     * @param  MetaClient  $obClient  Container-resolved via type-hint.
     */
    public function handle(MetaClient $obClient): void
    {
        // Phase 3.1 REFAC-06: race-fence moves from the deleted legacy
        // column on Lovata's orders table to the event_log table's
        // 5-col UNIQUE constraint. `EventLogWriter::record` returns:
        //   - true  → this job's INSERT created the row (race winner) → POST.
        //   - false → UNIQUE blocked OR DB error (race loser) → no POST.
        if (! $this->raceFenceWon()) {
            Log::info(
                'Metapixel CAPI dispatch lost race — peer already POSTed',
                $this->buildLogContext(),
            );

            return;
        }

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
        } catch (MetaApiPermanentException|MissingPixelConfigException|MissingCapiTokenException $obException) {
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
     * Atomic race-fence INSERT via EventLogWriter. Returns true when this job
     * won the UNIQUE-constraint race, false when a concurrent dispatch
     * already recorded the CAPI row OR the payload is malformed OR a DB
     * write failure occurred (EventLogWriter absorbs DB errors as a
     * Tiger-Style fail-safe — surface them as race-loss so no double-fire
     * happens on infra outage).
     *
     * Payload parsing: extracts `event_id` + `event_time` from
     * `$this->arPayload['data'][0]` (PayloadBuilder envelope shape locked in
     * Phase 3 PB-01). Both must be present and well-typed; otherwise return
     * false (refuse to dispatch a malformed payload — no HTTP POST).
     */
    private function raceFenceWon(): bool
    {
        $arFirst = $this->extractFirstEvent();
        $mxEventId = $arFirst['event_id'] ?? null;
        $sEventId = is_string($mxEventId) ? $mxEventId : '';
        $mxEventTime = $arFirst['event_time'] ?? null;
        $iEventTime = is_int($mxEventTime) ? $mxEventTime : 0;

        if ($sEventId === '' || $iEventTime === 0) {
            return false; // malformed payload — refuse to dispatch
        }

        return EventLogWriter::record(
            $sEventId,
            $this->sEventName,
            EventLog::CHANNEL_CAPI,
            $this->obSubject,
            $this->stringOrNull($this->obSubject->getAttribute('secret_key')),
            $iEventTime,
            SiteResolver::forOrder($this->obSubject),
        );
    }

    /**
     * Read the first event envelope from `$this->arPayload['data'][0]` with
     * full typed narrowing. PayloadBuilder envelope shape: `['data' => [[
     * 'event_id' => ..., 'event_time' => ..., ... ]]]`. Returns an empty
     * array when the shape doesn't match (malformed payload).
     *
     * @return array<string, mixed>
     */
    private function extractFirstEvent(): array
    {
        $mxData = $this->arPayload['data'] ?? null;
        if (! is_array($mxData)) {
            return [];
        }
        $mxFirst = $mxData[0] ?? null;

        return is_array($mxFirst) ? $mxFirst : [];
    }

    /**
     * Narrow a mixed value to `string|null` — non-empty string returns as-is,
     * everything else returns null. Mirrors OrderStatusWatcher::intOrZero /
     * stringOrEmpty narrowing pattern (PHPSTAN-01 lock). Phpstan level 10
     * accepts the is_string narrowing without `@var` or assert.
     */
    private function stringOrNull(mixed $mValue): ?string
    {
        if (is_string($mValue) && $mValue !== '') {
            return $mValue;
        }

        return null;
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
        $arFirst = $this->extractFirstEvent();
        $mxEventId = $arFirst['event_id'] ?? null;

        return array_merge([
            'meta_pixel.event_name' => $this->sEventName,
            'meta_pixel.event_id' => is_scalar($mxEventId) ? (string) $mxEventId : null,
        ], $arExtra);
    }
}
