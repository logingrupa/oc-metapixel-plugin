<?php

namespace Logingrupa\Metapixel\Controllers;

use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use LogicException;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Meta\DatasetQualityRows;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use System\Classes\SettingsManager;
use Throwable;

/**
 * Backend list controller for the FailedEvents dead-letter queue. Read-only
 * audit UI; rows are a write-only sink. Replay re-fires the persisted payload
 * through MetaClient::sendForPixel synchronously and stamps replayed_at;
 * Delete removes checked rows; Check dedup reads the Meta Dataset Quality
 * endpoint once for the whole pixel and renders EMQ + coverage per event
 * name above the list. (int) post('record_id') + findOrFail validates the
 * user-input boundary in lieu of the Validation trait on the model.
 *
 * WARNING: Replay AND Check dedup do NOT honour per-site credentials.
 * Both call Settings::lookupForSite(null). FailedEvent rows carry no site_id
 * column, the adapter contract carries no subject-loader method, and
 * re-hydrating the subject from subject_type + subject_id to call
 * EventSubjectAdapter::getSiteId would require a contract expansion. On
 * multi-site installs operators MUST configure the default-row credentials
 * as their primary site's pixel.
 *
 * @method string listRefresh($definition = null) provided by Backend.Behaviors.ListController; PHPDoc tells PHPStan level 10 that the magic-__call method exists and returns a string-castable list-partial HTML fragment.
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
     * List page. Flags a synchronous queue: every CAPI send then runs inside
     * the visitor's request and the retry schedule never executes.
     */
    public function index(): void
    {
        $this->vars['bQueueIsSync'] = config('queue.default') === 'sync';
        $this->vars['iRetentionDays'] = FailedEvent::RETENTION_DAYS;
        $obListBehavior = $this->asExtension('ListController');
        if (! $obListBehavior instanceof ListController) {
            throw new LogicException('metapixel: FailedEvents requires the ListController behavior');
        }
        $obListBehavior->index();
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
     * Fetch Meta Dataset Quality for the pixel once and render EMQ + coverage
     * per event name into the panel above the list. On failure the panel is
     * left as it was and the error is flashed.
     *
     * @return array<string, string>
     */
    public function onCheckDatasetQuality(): array
    {
        $arCreds = Settings::lookupForSite(null);

        try {
            /** @var MetaClient $obClient */
            $obClient = App::make(MetaClient::class);
            $arResponse = $obClient->fetchDatasetQuality($arCreds['pixel_id'], $arCreds['capi_access_token']);
        } catch (Throwable $obException) {
            // silent: dataset quality fetch is best-effort; the operator sees
            // the Graph error in the flash and keeps the previous panel.
            Flash::error(trans(
                'logingrupa.metapixel::lang.failed_events.flash_dedup_error',
                ['error' => $obException->getMessage()],
            ));

            return [];
        }

        return [
            '#metapixelDatasetQuality' => $this->renderDatasetQuality(
                DatasetQualityRows::fromResponse($arResponse),
                Carbon::now(),
            ),
        ];
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
            Flash::success(trans(
                'logingrupa.metapixel::lang.failed_events.flash_delete_success',
                ['count' => (string) count($arIds)],
            ));
        }

        return ['#failedEventList' => $this->listRefresh()];
    }

    /**
     * Renders the dataset quality panel. Separate so tests can stub the
     * view layer the same way they stub listRefresh().
     *
     * @param  list<array{event_name: string, emq: ?float, coverage: ?float}>  $arRows
     */
    protected function renderDatasetQuality(array $arRows, Carbon $obFetchedAt): string
    {
        $mHtml = $this->makePartial('dataset_quality', [
            'arRows' => $arRows,
            'obFetchedAt' => $obFetchedAt,
        ]);

        return is_string($mHtml) ? $mHtml : '';
    }

    /**
     * Shared per-row Replay body. Updates the row in place; flashes success
     * or error to the operator. Adapter unresolvable: flash error, no
     * dispatch. Every catch documents its reason (Tiger-Style fail-fast).
     */
    private function replayOne(FailedEvent $obRow): void
    {
        if ($obRow->isTooOldToReplay()) {
            Flash::error(trans(
                'logingrupa.metapixel::lang.failed_events.flash_replay_too_old',
                ['event_id' => (string) $obRow->event_id, 'days' => (string) FailedEvent::RETENTION_DAYS],
            ));

            return;
        }

        $sAdapterType = (string) ($obRow->adapter_type ?? '');

        try {
            /** @var AdapterRegistry $obRegistry */
            $obRegistry = App::make(AdapterRegistry::class);
            $obRegistry->resolveByClass($sAdapterType);
        } catch (Throwable $obException) {
            // silent: adapter no longer registered (operator removed it or
            // the third-party plugin was uninstalled), replay impossible.
            Flash::error(trans(
                'logingrupa.metapixel::lang.failed_events.flash_replay_adapter_missing',
                ['event_id' => (string) $obRow->event_id, 'adapter' => $sAdapterType],
            ));

            return;
        }

        // FailedEvent has no site_id column; replay uses default-row
        // credentials (see the class docblock).
        $iSiteId = null;
        $arCreds = Settings::lookupForSite($iSiteId);

        /** @var MetaClient $obClient */
        $obClient = App::make(MetaClient::class);
        $arPayload = $this->normalisePayload($obRow->payload);

        try {
            $obClient->sendForPixel(
                $arCreds['pixel_id'],
                $arCreds['capi_access_token'],
                $arPayload,
            );
            // success: stamp replayed_at and clear the previous failure so the
            // row reflects the latest attempt (sendForPixel returns the decoded
            // body, not a response status, so no "200" is fabricated here).
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => null,
                'http_status' => null,
                'replayed_at' => Carbon::now(),
            ]);
            Flash::success(trans(
                'logingrupa.metapixel::lang.failed_events.flash_replay_success',
                ['event_id' => (string) $obRow->event_id],
            ));
        } catch (MetaPixelException $obException) {
            // log-and-persist: write the failure mode onto the row so the
            // operator sees the latest Graph API response in the list UI.
            // Propagate the upstream HTTP status when the concrete exception
            // exposes it (MetaApiTransientException / MetaApiPermanentException
            // both carry getHttpStatus(); MissingPixelConfigException + the
            // CapiToken sibling do not, fall through to null on absence).
            $iStatus = method_exists($obException, 'getHttpStatus')
                ? $obException->getHttpStatus()
                : null;
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => FailedEvent::graphErrorFrom($obException),
                'http_status' => $iStatus,
            ]);
            $sReason = $obException->metaReason();
            Flash::error(trans(
                'logingrupa.metapixel::lang.failed_events.flash_replay_error',
                ['error' => $sReason === null ? $obException->getMessage() : $obException->getMessage().': '.$sReason],
            ));
        } catch (Throwable $obException) {
            // log-and-persist: unknown failure (timeout, network, parser, ...),
            // no HTTP status is available, clear the stale value to avoid lying.
            $obRow->update([
                'attempts' => $obRow->attempts + 1,
                'graph_error' => FailedEvent::graphErrorFrom($obException),
                'http_status' => null,
            ]);
            Flash::error(trans(
                'logingrupa.metapixel::lang.failed_events.flash_replay_errored',
                ['error' => $obException->getMessage()],
            ));
        }
    }

    /**
     * Narrow the mixed return of post('record_id') to int at the boundary.
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

    /**
     * Locate a FailedEvent row by id at the user-input boundary. Soft-finds
     * (no ModelNotFoundException, so no backend AJAX 500) and emits a flash
     * on stale-page-load scenarios (operator deleted the row in tab A then
     * hit Replay in tab B). RuntimeException is thrown after the flash so
     * the caller short-circuits before dispatching any Meta API traffic.
     */
    private function findRowOrFail(int $iRecordId): FailedEvent
    {
        if ($iRecordId <= 0) {
            Flash::error(trans('logingrupa.metapixel::lang.failed_events.flash_row_missing'));
            throw new \RuntimeException('metapixel: invalid record_id '.$iRecordId);
        }

        $obRow = FailedEvent::query()->find($iRecordId);
        if (! $obRow instanceof FailedEvent) {
            Flash::error(trans('logingrupa.metapixel::lang.failed_events.flash_row_missing'));
            throw new \RuntimeException('metapixel: failed event row '.$iRecordId.' not found');
        }

        return $obRow;
    }

    /**
     * Narrow array<mixed> from the FailedEvent->payload jsonable column to
     * the array<string, mixed> shape MetaClient::sendForPixel expects.
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
