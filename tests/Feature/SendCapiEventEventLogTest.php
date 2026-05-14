<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

/**
 * Feature test locking Phase 3.1 REFAC-06 + REFAC-11 SendCapiEvent
 * race-fence contract via the plugin-owned event_log table.
 *
 * Three invariants:
 *
 *   1. test_first_dispatch_records_capi_row_and_posts_to_meta — race
 *      winner path. EventLogWriter::record returns true → MetaClient::send
 *      proceeds → MockHandler captures one POST + event_log table has
 *      exactly one CAPI row matching the dispatched event_id.
 *
 *   2. test_second_concurrent_dispatch_returns_false_no_http_post — race
 *      loser path. A CAPI row pre-exists for the same Order on the same
 *      channel. EventLogWriter::record's INSERT IGNORE returns affected=0
 *      → SendCapiEvent::handle's raceFenceWon guard returns false → log
 *      INFO "lost race" + return. MockHandler MUST NOT capture any POST.
 *      EventLog count stays at 1 (the pre-existing row).
 *
 *   3. test_db_write_failure_during_record_does_not_cascade — Tiger-Style
 *      boundary catch. Drop the event_log table mid-test; dispatchSync
 *      MUST return normally (no Throwable leaks). EventLogWriter's
 *      silent catch absorbs the QueryException + returns false →
 *      handle() treats infra failure as race-loss → no HTTP POST.
 *
 * Multi-site contract is exercised in MultiSiteEventLogTest (deferred);
 * the SiteResolver branch is exercised indirectly here via the
 * single-site `site_id=null` path which `EventLogWriter::record` writes
 * automatically when SiteResolver returns null in the CLI / queue
 * worker context the test runs under.
 *
 * Settings priming uses the MetaClient pattern (reflection setAttribute
 * on Settings::instance()) — HR-02 multi-Settings::set flap workaround.
 *
 * MetaClient is bound into the container with a MockHandler-backed
 * Guzzle Client so SendCapiEvent::handle's auto-resolution picks up the
 * captured-history-enabled instance (MC-04 pattern).
 */
final class SendCapiEventEventLogTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        $this->bootEventLogTable();
        OrderFixtures::provisionHermeticOfferProductTables();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();

        $this->primeSettings();
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        parent::tearDown();
    }

    public function test_first_dispatch_records_capi_row_and_posts_to_meta(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();
        $arPayload = $this->makePayload($sEventId, $iEventTime);

        $arHistory = [];
        $obMockHandler = $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "abc"}'),
        ], $arHistory);

        SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);

        // MockHandler invariant: exactly one POST left the queue worker.
        $this->assertCount(1, $arHistory, 'race-winner path MUST POST exactly one request to Meta.');
        $this->assertSame(0, $obMockHandler->count(), 'MockHandler queue MUST be drained on race-winner path.');

        // EventLog invariant: exactly one CAPI row, with the dispatched event_id.
        $iEventLogCount = EventLog::where('channel', EventLog::CHANNEL_CAPI)->count();
        $this->assertSame(1, $iEventLogCount, 'EventLog must contain exactly one CAPI row after race-winner dispatch.');

        /** @var EventLog $obRow */
        $obRow = EventLog::where('channel', EventLog::CHANNEL_CAPI)->first();
        $this->assertSame($sEventId, $obRow->event_id, 'EventLog.event_id must equal the dispatched payload event_id.');
        $this->assertSame(EventLog::EVENT_PURCHASE, $obRow->event_name);
        $this->assertSame(Order::class, $obRow->subject_type);
        $this->assertSame((int) $obOrder->id, (int) $obRow->subject_id);
        $this->assertSame($iEventTime, (int) $obRow->event_time);
        // Single-site / CLI context — SiteResolver returns null.
        $this->assertNull($obRow->site_id, 'Single-site / CLI context records site_id=NULL.');
    }

    public function test_second_concurrent_dispatch_returns_false_no_http_post(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();
        $arPayload = $this->makePayload($sEventId, $iEventTime);

        // Pre-insert the EventLog CAPI row that a concurrent dispatch would
        // have written (race-winner peer already POSTed to Meta). This is
        // the "race loser" simulation — INSERT IGNORE in EventLogWriter
        // will collide on UNIQUE(subject_type, subject_id, event_name,
        // channel, site_id=NULL) → affected_rows=0 → returns false.
        $this->preInsertCapiRow($obOrder, $sEventId, $iEventTime);
        $iEventLogCountBefore = EventLog::count();
        $this->assertSame(1, $iEventLogCountBefore, 'pre-condition: exactly one CAPI row exists before dispatch.');

        // Capture log output to assert the "lost race" INFO line. The
        // SCE-05 capture-by-reference pattern — register a log listener
        // that appends to a local array.
        $arLogCapture = [];
        Log::listen(function ($obEntry) use (&$arLogCapture): void {
            $arLogCapture[] = [
                'level' => $obEntry->level,
                'message' => $obEntry->message,
            ];
        });

        $arHistory = [];
        $obMockHandler = $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "should-not-be-sent"}'),
        ], $arHistory);

        SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);

        // MockHandler invariant: ZERO POSTs left the queue worker (race lost).
        $this->assertCount(0, $arHistory, 'race-loser path MUST NOT POST to Meta.');
        $this->assertSame(1, $obMockHandler->count(), 'MockHandler queue MUST retain the queued response (never consumed).');

        // EventLog invariant: count unchanged at 1 (the pre-inserted row).
        $iEventLogCountAfter = EventLog::count();
        $this->assertSame(1, $iEventLogCountAfter, 'EventLog count must remain 1 (race loser does not insert).');

        // Log invariant: "lost race" INFO line captured.
        $bFoundLostRace = false;
        foreach ($arLogCapture as $arEntry) {
            if ($arEntry['level'] === 'info' && str_contains($arEntry['message'], 'lost race')) {
                $bFoundLostRace = true;
                break;
            }
        }
        $this->assertTrue($bFoundLostRace, 'race-loser path MUST log INFO "lost race" via the SCE-05 capture-by-reference pattern.');
    }

    public function test_db_write_failure_during_record_does_not_cascade(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = Uuid::uuid4()->toString();
        $arPayload = $this->makePayload($sEventId);

        // Drop the event_log table BEFORE dispatch → EventLogWriter::record
        // raises QueryException → silent Throwable catch returns false →
        // SendCapiEvent::handle treats as race-loss → no HTTP POST.
        Schema::drop('logingrupa_metapixel_event_log');

        // Capture Log::critical output to assert EventLogWriter's silent-catch
        // surfaces the infra failure to operators (T-3.1-08 mitigation).
        $arLogCapture = [];
        Log::listen(function ($obEntry) use (&$arLogCapture): void {
            $arLogCapture[] = [
                'level' => $obEntry->level,
                'message' => $obEntry->message,
            ];
        });

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        $bThrown = false;
        try {
            SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);
        } catch (\Throwable $obException) {
            $bThrown = true;
        }

        $this->assertFalse($bThrown, 'DB-write failure during EventLogWriter::record MUST be absorbed (Tiger-Style boundary catch).');
        $this->assertCount(0, $arHistory, 'DB outage path MUST NOT POST to Meta (fail-safe).');

        // Log invariant: critical entry captured from EventLogWriter's silent catch.
        $bFoundCritical = false;
        foreach ($arLogCapture as $arEntry) {
            if ($arEntry['level'] === 'critical' && str_contains($arEntry['message'], 'EventLogWriter::record DB write FAILED')) {
                $bFoundCritical = true;
                break;
            }
        }
        $this->assertTrue($bFoundCritical, 'EventLogWriter MUST emit Log::critical on DB write failure (operator telemetry).');
    }

    /**
     * Phase 3.1-07 REFAC-13 RED — writer receives caller-resolved site_id
     * from forOrder, not getActiveSiteId. Active_site DELIBERATELY diverges
     * from Order.site_id to force the cross-context bug. RED until Task 6
     * lock-steps EventLogWriter signature + SendCapiEvent call site.
     */
    public function test_writer_called_with_resolved_site_id_from_caller(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->site_id = 7;
        $obOrder->save();
        $obOrder = $obOrder->fresh();

        // Diverge active_site from Order.site_id (admin /back context lies).
        Config::set('system.active_site', null);

        $sEventId = '77777777-7777-7777-7777-777777777777';
        $iEventTime = 1715000000;
        $arPayload = $this->makePayload($sEventId, $iEventTime);

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);

        $obRow = EventLog::where('subject_id', $obOrder->id)->first();
        $this->assertNotNull($obRow, 'CAPI row MUST persist after dispatch.');
        $this->assertSame(
            7,
            (int) $obRow->site_id,
            'EventLogWriter MUST receive site_id from caller (forOrder), not getActiveSiteId.',
        );

        // Reset Config so subsequent files do not inherit binding.
        Config::set('system.active_site', null);
    }

    /**
     * Build a MetaClient backed by a MockHandler Guzzle Client AND set up a
     * Guzzle history middleware writing into the caller-provided by-ref
     * array. MC-04 pattern — explicit by-ref parameter wins over array
     * destructuring (which would lose the by-ref binding on $arHistory).
     *
     * @param  list<\GuzzleHttp\Psr7\Response|\Exception>  $arResponses
     * @param  array<int, array<string, mixed>>            $arHistory  passed by reference
     */
    private function bindMetaClientWithMockResponses(array $arResponses, array &$arHistory): MockHandler
    {
        $obMockHandler = new MockHandler($arResponses);
        $obStack = HandlerStack::create($obMockHandler);

        $obHistoryMw = \GuzzleHttp\Middleware::history($arHistory);
        $obStack->push($obHistoryMw);

        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);
        $this->app->instance(MetaClient::class, new MetaClient($obGuzzle));

        return $obMockHandler;
    }

    /**
     * Prime Settings via the reflection pattern from MetaClientTest (HR-02
     * workaround — Settings::set + Cache::flush flaps under multi-set load).
     */
    private function primeSettings(string $sPixelId = '2291486191076331', string $sCapiToken = 'EAA-test-token'): void
    {
        $obInstance = Settings::instance();
        $obInstance->setAttribute('pixel_id', $sPixelId);
        $obInstance->setAttribute('capi_access_token', $sCapiToken);
        $obInstance->setAttribute('test_event_code', '');
    }

    /**
     * Build a minimal valid CAPI envelope so dispatchSync exercises the real
     * handle() path without depending on PayloadBuilder/Order fixtures.
     *
     * @return array<string, mixed>
     */
    private function makePayload(string $sEventId, int $iEventTime = 0): array
    {
        if ($iEventTime === 0) {
            $iEventTime = time();
        }

        return [
            'data' => [
                [
                    'event_id' => $sEventId,
                    'event_name' => 'Purchase',
                    'event_time' => $iEventTime,
                    'action_source' => 'website',
                ],
            ],
        ];
    }

    /**
     * Pre-insert an EventLog CAPI row matching the active site_id branch
     * (single-site / CLI / queue worker context → site_id NULL). Used by
     * the race-loser test to seed the UNIQUE collision before
     * SendCapiEvent::handle's EventLogWriter::record runs.
     */
    private function preInsertCapiRow(Order $obOrder, string $sEventId, int $iEventTime): void
    {
        $obRow = new EventLog;
        $obRow->forceFill([
            'event_id' => $sEventId,
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => EventLog::CHANNEL_CAPI,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->getAttribute('secret_key'),
            'site_id' => null,
            'event_time' => $iEventTime,
            'fired_at' => date('Y-m-d H:i:s'),
        ]);
        $obRow->save();
    }
}
