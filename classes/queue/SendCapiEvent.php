<?php

namespace Logingrupa\Metapixel\Classes\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Classes\Helper\SiteResolver;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use Throwable;

/**
 * Queue job that bridges adapter → EventLog race-fence → MetaClient send.
 *
 * Hook: metapixel.event.before_dispatch — halt-able via Event::fire(..., true).
 *   Signature: function(string, array &$arPayload, object): mixed
 *   Return false to veto. Mutating event_id/event_time is forbidden (the
 *   server↔browser dedup contract). Snapshot+restore guarantees enforcement.
 *
 * Hook: metapixel.event.after_dispatch — observe-only successful-dispatch tap.
 *   Signature: function(string, array, object, array $arGraphResponse): mixed
 *
 * Hook: metapixel.event.dead_letter — observe-only permanent-failure alert.
 *   Signature: function(string, array, object, \Throwable): mixed
 *
 * Listener exceptions on any hook are caught + Log::warning + continue —
 * never propagate to the dispatch pipeline.
 *
 * writeFailedEvent populates FailedEvent.subject_type + subject_id from the
 * resolved adapter when available (enables Phase 4 admin UI re-resolution).
 * BindingResolutionException early-return passes null — adapter does not
 * exist, re-resolution is impossible.
 */
final class SendCapiEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const HOOK_BEFORE_DISPATCH = 'metapixel.event.before_dispatch';

    public const HOOK_AFTER_DISPATCH = 'metapixel.event.after_dispatch';

    public const HOOK_DEAD_LETTER = 'metapixel.event.dead_letter';

    /** @var int Laravel queue retry attempts (1 initial + 3 backoffs = 4 total tries) */
    public int $tries = 3;

    /** @var list<int> backoff seconds */
    public array $backoff = [1, 4, 16];

    /**
     * @param  array<string, mixed>  $arPayload
     */
    public function __construct(
        public readonly string $sEventName,
        public array $arPayload,
        public readonly object $obSubject,
        public readonly string $sAdapterClass,
    ) {}

    public function handle(AdapterRegistry $obRegistry, MetaClient $obClient): void
    {
        try {
            $obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);
        } catch (BindingResolutionException $obException) {
            Log::critical('metapixel: adapter rehydrate failed — dead-lettered', [
                'meta_pixel.adapter_class' => $this->sAdapterClass,
                'meta_pixel.event_id' => $this->readEventId(),
                'meta_pixel.event_name' => $this->sEventName,
                'meta_pixel.exception' => get_class($obException),
            ]);
            $this->writeFailedEvent($obException, null, null);

            return;
        }

        if ($this->fireBeforeDispatchHalt($obAdapter)) {
            Log::info('metapixel: dispatch halted by before_dispatch listener', [
                'meta_pixel.event_id' => $this->readEventId(),
                'meta_pixel.event_name' => $this->sEventName,
            ]);

            return;
        }

        $iSiteId = SiteResolver::forSubject($this->obSubject, $obAdapter);

        $bWonRaceFence = EventLogWriter::record(
            $this->readEventId(),
            $this->sEventName,
            'capi',
            $this->obSubject,
            $obAdapter->getSecretKey($this->obSubject),
            $this->readEventTime(),
            $iSiteId,
        );
        if (! $bWonRaceFence) {
            return;
        }

        $arCreds = Settings::lookupForSite($iSiteId);

        try {
            $arResponse = $obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $this->arPayload);
        } catch (MetaApiTransientException $obException) {
            throw $obException;
        } catch (MetaApiPermanentException|MissingPixelConfigException|MissingCapiTokenException $obException) {
            $iStatus = $obException instanceof MetaApiPermanentException ? $obException->getHttpStatus() : null;
            $this->writeFailedEvent($obException, $iStatus, $obAdapter);
            $this->fireDeadLetter($obException, $obAdapter);

            return;
        }

        $this->fireAfterDispatch($arResponse, $obAdapter);
    }

    public function failed(Throwable $obException): void
    {
        $obAdapter = null;
        try {
            /** @var AdapterRegistry $obRegistry */
            $obRegistry = app(AdapterRegistry::class);
            $obAdapter = $obRegistry->resolveByClass($this->sAdapterClass);
        } catch (Throwable $obResolveException) {
            // Silent: failed() runs on the retry-exhaustion path; cannot escalate.
            // The original exception is what matters; resolution failure here is logged
            // implicitly via the writeFailedEvent path with null adapter.
        }

        $iStatus = $obException instanceof MetaApiTransientException ? $obException->getHttpStatus() : null;
        $this->writeFailedEvent($obException, $iStatus, $obAdapter);
        $this->fireDeadLetter($obException, $obAdapter);
    }

    /**
     * Fire metapixel.event.before_dispatch with halt semantics + payload mutation contract.
     *
     * Listeners receive [$sEventName, &$arPayload, $obSubject]. Return literal false to
     * veto dispatch. Mutating $arPayload['data'][0]['event_id'] or 'event_time' is
     * forbidden — this method snapshots both fields and restores them after the hook
     * fires so a misbehaving listener cannot break Meta dedup.
     *
     * Returns true when dispatch should halt.
     */
    private function fireBeforeDispatchHalt(EventSubjectAdapter $obAdapter): bool
    {
        try {
            $sEventId = $this->readEventId();
            $iEventTime = $this->readEventTime();

            $arMutablePayload = $this->arPayload;
            $mResult = Event::fire(
                self::HOOK_BEFORE_DISPATCH,
                [$this->sEventName, &$arMutablePayload, $this->obSubject],
                true,
            );

            if (isset($arMutablePayload['data'][0]) && is_array($arMutablePayload['data'][0])) {
                $arMutablePayload['data'][0]['event_id'] = $sEventId;
                $arMutablePayload['data'][0]['event_time'] = $iEventTime;
            }
            $this->arPayload = $arMutablePayload;

            return $mResult === false;
        } catch (Throwable $obException) {
            Log::warning('metapixel: before_dispatch listener threw — treating as abstain', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
                'meta_pixel.event_id' => $this->readEventId(),
            ]);

            return false;
        }
    }

    /**
     * Fire metapixel.event.after_dispatch — observe-only. Payload is passed by value;
     * no listener mutation contract.
     *
     * @param  array<string, mixed>  $arResponse
     */
    private function fireAfterDispatch(array $arResponse, EventSubjectAdapter $obAdapter): void
    {
        try {
            Event::fire(self::HOOK_AFTER_DISPATCH, [
                $this->sEventName,
                $this->arPayload,
                $this->obSubject,
                $arResponse,
            ]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: after_dispatch listener threw — observed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.event_id' => $this->readEventId(),
            ]);
        }
    }

    /**
     * Fire metapixel.event.dead_letter — observe-only. Listeners receive the original
     * throwable; no listener mutation contract.
     */
    private function fireDeadLetter(Throwable $obException, ?EventSubjectAdapter $obAdapter): void
    {
        try {
            Event::fire(self::HOOK_DEAD_LETTER, [
                $this->sEventName,
                $this->arPayload,
                $this->obSubject,
                $obException,
            ]);
        } catch (Throwable $obListenerException) {
            Log::warning('metapixel: dead_letter listener threw — observed', [
                'meta_pixel.listener_exception' => get_class($obListenerException),
                'meta_pixel.event_id' => $this->readEventId(),
            ]);
        }
    }

    /**
     * Persist a FailedEvent row. When the adapter is non-null, subject_type and
     * subject_id are populated from it so Phase 4 admin UI can re-resolve the subject
     * for replay. The BindingResolutionException early-return path passes null —
     * adapter does not exist; re-resolution is impossible by definition.
     */
    private function writeFailedEvent(Throwable $obException, ?int $iHttpStatus, ?EventSubjectAdapter $obAdapter): void
    {
        try {
            $arContext = $obException instanceof MetaPixelException ? $obException->getContext() : [];
            $sSubjectType = $obAdapter !== null ? $obAdapter->getSubjectType($this->obSubject) : null;
            $iSubjectId = $obAdapter !== null ? $obAdapter->getSubjectId($this->obSubject) : null;

            FailedEvent::create([
                'event_id' => $this->readEventId(),
                'event_name' => $this->sEventName,
                'adapter_type' => $this->sAdapterClass,
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'payload' => $this->arPayload,
                'graph_error' => $obException->getMessage()."\n".json_encode($arContext),
                'http_status' => $iHttpStatus,
                'attempts' => $this->attempts() ?: 1,
            ]);
        } catch (Throwable $obDbException) {
            // Silent: DB write failure on a dead-letter path cannot itself escalate.
            // The exception was already logged via Log::critical or Log::warning upstream.
            Log::warning('metapixel: writeFailedEvent — DB insert failed', [
                'meta_pixel.exception' => get_class($obDbException),
            ]);
        }
    }

    private function readEventId(): string
    {
        if (! isset($this->arPayload['data'][0]) || ! is_array($this->arPayload['data'][0])) {
            return '';
        }
        $mEventId = $this->arPayload['data'][0]['event_id'] ?? '';

        return is_string($mEventId) ? $mEventId : '';
    }

    private function readEventTime(): int
    {
        if (! isset($this->arPayload['data'][0]) || ! is_array($this->arPayload['data'][0])) {
            return 0;
        }
        $mEventTime = $this->arPayload['data'][0]['event_time'] ?? 0;

        return is_int($mEventTime) ? $mEventTime : 0;
    }
}
