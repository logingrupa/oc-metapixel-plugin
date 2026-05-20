<?php

namespace Logingrupa\Metapixel\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use System\Classes\SettingsManager;
use Throwable;

/**
 * Backend list controller for the FailedEvents dead-letter queue. D-08 lock —
 * read-only audit UI; rows are write-only sink. Three AJAX handlers + their
 * batch siblings: Replay re-fires the persisted payload through
 * MetaClient::sendForPixel synchronously (D-05); CheckDedup queries the
 * Meta Dataset Quality endpoint and writes 3 inline columns
 * (dedup_pct/emq/dedup_checked_at) per row (D-06); Delete truncates checked
 * rows. (int) post('record_id') + findOrFail validates the user-input
 * boundary in lieu of the Validation trait on the model (Pitfall 10).
 *
 * Replay uses Settings::lookupForSite(null) — D-01 default-row fallback per
 * Open Question 1 Option A (no site_id column on FailedEvent in v2.0).
 * Operators on multi-site setups should configure the default-row credentials
 * as their primary site for safe replay behaviour (README troubleshooting).
 */
class FailedEvents extends Controller
{
    /** @var list<string> */
    public $implement = [
        'Backend.Behaviors.ListController',
    ];

    /** @var string */
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Logingrupa.Metapixel', 'failed_events');
    }

    /**
     * Replay a single FailedEvent through MetaClient synchronously.
     * Per-row AJAX wire: data-request="onReplay" + record_id POST param.
     *
     * @return array<string, string>
     */
    public function onReplay(): array
    {
        $obRow = $this->findRowOrFail($this->postRecordId());

        $this->replayOne($obRow);

        return ['#failedEventList' => $this->listRefresh()];
    }

    /**
     * Batch Replay for all checked rows. Toolbar wire pushes
     * `checked: $('.control-list').listWidget('getChecked')` as POST.
     *
     * @return array<string, string>
     */
    public function onReplayBatch(): array
    {
        foreach ($this->postCheckedIds() as $iRecordId) {
            $obRow = $this->findRow($iRecordId);
            if ($obRow === null) {
                continue;
            }
            $this->replayOne($obRow);
        }

        return ['#failedEventList' => $this->listRefresh()];
    }

    /**
     * Check Meta Dataset Quality for a single FailedEvent row. Writes
     * dedup_pct + emq + dedup_checked_at inline (D-06). Returns the 3
     * column values alongside the list-refresh partial for live JSON refresh.
     *
     * @return array<string, mixed>
     */
    public function onCheckDedup(): array
    {
        $obRow = $this->findRowOrFail($this->postRecordId());

        $arUpdate = $this->checkDedupOne($obRow);

        return array_merge(
            $arUpdate,
            ['#failedEventList' => $this->listRefresh()],
        );
    }

    /**
     * Batch CheckDedup for all checked rows.
     *
     * @return array<string, string>
     */
    public function onCheckDedupBatch(): array
    {
        foreach ($this->postCheckedIds() as $iRecordId) {
            $obRow = $this->findRow($iRecordId);
            if ($obRow === null) {
                continue;
            }
            $this->checkDedupOne($obRow);
        }

        return ['#failedEventList' => $this->listRefresh()];
    }

    /**
     * Delete all checked rows.
     *
     * @return array<string, string>
     */
    public function onDeleteBatch(): array
    {
        $arIds = [];
        foreach ($this->postCheckedIds() as $iRecordId) {
            if ($iRecordId > 0) {
                $arIds[] = $iRecordId;
            }
        }
        if ($arIds !== []) {
            FailedEvent::whereIn('id', $arIds)->delete();
            Flash::success('metapixel: deleted '.count($arIds).' failed event(s)');
        }

        return ['#failedEventList' => $this->listRefresh()];
    }

    /**
     * Shared per-row Replay body — used by onReplay (single) and
     * onReplayBatch (loop). Updates the row in-place; flashes success or
     * error to the operator. Adapter unresolvable → flash error + no
     * dispatch. Every catch documents its reason (Tiger-Style fail-fast).
     */
    private function replayOne(FailedEvent $obRow): void
    {
        $sAdapterType = (string) ($obRow->adapter_type ?? '');

        try {
            /** @var AdapterRegistry $obRegistry */
            $obRegistry = App::make(AdapterRegistry::class);
            $obRegistry->resolveByClass($sAdapterType);
        } catch (Throwable $obException) {
            // silent: adapter no longer registered (operator removed it or
            // the third-party plugin was uninstalled) — replay impossible.
            Flash::error('metapixel: cannot replay event_id '.$obRow->event_id.' — adapter '.$sAdapterType.' not registered');

            return;
        }

        // D-01 + Open Question 1 Option A — FailedEvent has no site_id column
        // in v2.0; replay uses default-row credentials. Operators on multi-site
        // setups should configure default-row as the primary site (README).
        $iSiteId = null;
        $arCreds = Settings::lookupForSite($iSiteId);

        /** @var MetaClient $obClient */
        $obClient = App::make(MetaClient::class);
        $arPayload = is_array($obRow->payload) ? $this->normalisePayload($obRow->payload) : [];

        try {
            $obClient->sendForPixel(
                $arCreds['pixel_id'],
                $arCreds['capi_access_token'],
                $arPayload,
            );
            // success: clear stale http_status from the previous failure so the
            // audit column reflects the latest attempt outcome (the only
            // honest signal — sendForPixel returns the decoded body, not a
            // response status, so we cannot fabricate a "200" here).
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => null,
                'http_status' => null,
            ]);
            Flash::success('metapixel: replay succeeded — event_id '.$obRow->event_id);
        } catch (MetaPixelException $obException) {
            // log-and-persist: write the failure mode onto the row so the
            // operator sees the latest Graph API response in the list UI.
            // Propagate the upstream HTTP status when the concrete exception
            // exposes it (MetaApiTransientException / MetaApiPermanentException
            // both carry getHttpStatus(); MissingPixelConfigException + the
            // CapiToken sibling do not — fall through to null on absence).
            $iStatus = method_exists($obException, 'getHttpStatus')
                ? $obException->getHttpStatus()
                : null;
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => $obException->getMessage(),
                'http_status' => $iStatus,
            ]);
            Flash::error('metapixel: replay failed — '.$obException->getMessage());
        } catch (Throwable $obException) {
            // log-and-persist: unknown failure (timeout, network, parser, …) —
            // no HTTP status is available, clear stale value to avoid lying.
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => $obException->getMessage(),
                'http_status' => null,
            ]);
            Flash::error('metapixel: replay errored — '.$obException->getMessage());
        }
    }

    /**
     * Shared per-row CheckDedup body — used by onCheckDedup and
     * onCheckDedupBatch. Returns the 3 column values + checked_at for
     * live JSON refresh of the single-row case; flashes error on failure
     * without overwriting any existing column values.
     *
     * @return array{dedup_pct: ?float, emq: ?float, checked_at: ?string}
     */
    private function checkDedupOne(FailedEvent $obRow): array
    {
        $arEmpty = ['dedup_pct' => null, 'emq' => null, 'checked_at' => null];

        $arCreds = Settings::lookupForSite(null);
        // Settings::get on test_event_code is permitted — D-02 disallowed
        // rule bans pixel_id / capi_access_token only.
        $mTestEventCode = Settings::get('test_event_code', '');
        $sTestEventCode = is_string($mTestEventCode) ? $mTestEventCode : '';

        try {
            /** @var MetaClient $obClient */
            $obClient = App::make(MetaClient::class);
            $arResponse = $obClient->fetchTestEventsStatus(
                $arCreds['pixel_id'],
                $arCreds['capi_access_token'],
                $sTestEventCode,
                $obRow->event_id,
            );
        } catch (Throwable $obException) {
            // silent: dataset quality fetch is best-effort; existing row
            // dedup_pct / emq / dedup_checked_at MUST NOT be overwritten on
            // failure so the operator keeps the last-known-good snapshot.
            Flash::error('metapixel: dedup check failed — '.$obException->getMessage());

            return $arEmpty;
        }

        $fEmq = $this->extractMetricForEventName($arResponse['event_match_quality'], $obRow->event_name);
        $fDedupRate = $this->extractMetricForEventName($arResponse['deduplication_rate'], $obRow->event_name);
        $fDedupPct = $fDedupRate === null ? null : round($fDedupRate * 100.0, 2);

        $sCheckedAt = Carbon::now()->toDateTimeString();
        $obRow->update([
            'dedup_pct' => $fDedupPct,
            'emq' => $fEmq,
            'dedup_checked_at' => $sCheckedAt,
        ]);

        Flash::success('metapixel: dedup check succeeded for event_id '.$obRow->event_id);

        return [
            'dedup_pct' => $fDedupPct,
            'emq' => $fEmq,
            'checked_at' => $sCheckedAt,
        ];
    }

    /**
     * Tolerant numeric extractor for the Meta Dataset Quality event-name-keyed
     * map shape (`['Purchase' => 8.4, 'ViewContent' => 7.1]`). Returns null
     * when the field is missing, null, or non-scalar — never throws.
     */
    private function extractMetricForEventName(mixed $mField, string $sEventName): ?float
    {
        if (! is_array($mField) || $sEventName === '') {
            return null;
        }
        if (! array_key_exists($sEventName, $mField)) {
            return null;
        }
        $mValue = $mField[$sEventName];
        if (! is_numeric($mValue)) {
            return null;
        }

        return (float) $mValue;
    }

    /**
     * Render the list partial for AJAX-driven refresh after a per-row or
     * batch action. Wraps makePartial('list') so test subclasses can stub
     * the heavy backend ListController rendering.
     */
    protected function listRefresh(): string
    {
        return (string) $this->makePartial('list');
    }

    /**
     * Narrow the mixed return of post('record_id') to int at the boundary.
     * Mirrors the Phase 2 helper-narrowing idiom (Settings::lookupForSite's
     * is_string runtime guard, MetaClient::decodeBody's foreach cast).
     */
    private function postRecordId(): int
    {
        $mRecordId = post('record_id');
        if (is_int($mRecordId)) {
            return $mRecordId;
        }
        if (is_string($mRecordId) && $mRecordId !== '' && ctype_digit($mRecordId)) {
            return (int) $mRecordId;
        }

        return 0;
    }

    /**
     * Narrow the mixed return of post('checked') to list<int> at the boundary.
     *
     * @return list<int>
     */
    private function postCheckedIds(): array
    {
        $mChecked = post('checked');
        if (! is_array($mChecked)) {
            return [];
        }
        $arIds = [];
        foreach ($mChecked as $mId) {
            if (is_int($mId)) {
                $arIds[] = $mId;
            } elseif (is_string($mId) && ctype_digit($mId)) {
                $arIds[] = (int) $mId;
            }
        }

        return $arIds;
    }

    private function findRow(int $iRecordId): ?FailedEvent
    {
        $obRow = FailedEvent::query()->find($iRecordId);

        return $obRow instanceof FailedEvent ? $obRow : null;
    }

    private function findRowOrFail(int $iRecordId): FailedEvent
    {
        $obRow = FailedEvent::query()->findOrFail($iRecordId);
        if (! $obRow instanceof FailedEvent) {
            throw new \RuntimeException('metapixel: query returned non-FailedEvent row for id '.$iRecordId);
        }

        return $obRow;
    }

    /**
     * Narrow array<mixed> from the FailedEvent->payload jsonable column to
     * the array<string, mixed> shape MetaClient::sendForPixel expects.
     * Returns an empty envelope when keys are non-string.
     *
     * @param  array<mixed>  $arRaw
     * @return array<string, mixed>
     */
    private function normalisePayload(array $arRaw): array
    {
        $arOut = [];
        foreach ($arRaw as $mKey => $mValue) {
            $arOut[(string) $mKey] = $mValue;
        }

        return $arOut;
    }
}
