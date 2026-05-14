<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Components\PurchasePixel;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Phase 3.1 Wave-5 REFAC-11 closure — full Purchase pipeline end-to-end
 * integration test. Codifies the 4 BRIEF acceptance scenarios + 1 multi-site
 * sanity check as automated CI proofs so the deferred-runtime block in
 * VERIFICATION.md (lines 162-178) collapses to live-environment plumbing only
 * (real PayPal IPN, real Pixel script handshake, Meta Events Manager dashboard).
 *
 * Scenarios codified (BRIEF.md lines 280-294):
 *
 *   - Scenario 1 (PayPal CAPI+Pixel pair, same event_id, deduplicated):
 *     paid Order save triggers OrderStatusWatcher::handleUpdated →
 *     SendCapiEvent (sync queue) → EventLogWriter::record(channel='capi') →
 *     exactly one Guzzle POST captured by MockHandler →
 *     PurchasePixel::onRun() renders arMetaEvent with the CAPI row's event_id →
 *     PurchasePixel::onMarkFired() inserts the channel='pixel' row → both
 *     EventLog rows share event_id AND event_time.
 *
 *   - Scenario 2 (bank-transfer admin flip: CAPI only, Pixel renders on
 *     later visit): first save with status_id=1 (new) MUST NOT fire; admin
 *     flip to status_id=5 fires exactly one CAPI POST + records CAPI row;
 *     customer-side onRun() later renders with the persisted CAPI row's
 *     event_id + event_time.
 *
 *   - Scenario 3 (status flip-flop with refire OFF: no second dispatch,
 *     no second HTTP POST): refire flag OFF; flip 1→5 fires; flip 5→3
 *     is a no-op (away-from-paid); flip 3→5 short-circuits via
 *     alreadyDispatched (EventLog has CAPI row); MockHandler history stays
 *     at 1, EventLog count stays at 1.
 *
 *   - Scenario 4 (refresh + new-device incognito: Purchase does NOT re-fire):
 *     pre-seed CAPI + Pixel rows; two FRESH PurchasePixel component
 *     instances (simulating refresh + different-device incognito) both
 *     onRun() return null + arMetaEvent stays null; second onMarkFired
 *     returns ['ok' => true, 'won_race' => false] (UNIQUE collapsed
 *     no-op); EventLog pixel-row count stays at 1.
 *
 *   - Bonus multi-site sanity (criterion 9 cross-link):
 *     switches Config('system.active_site') between 1 and 2 across two
 *     dispatchSync calls for the SAME Order id → two independent CAPI
 *     rows + two MockHandler POSTs. Exercises the FULL SendCapiEvent
 *     dispatch chain (not just the writer) so the integration surface
 *     is provably multi-site safe.
 *
 * Determinism (Tiger-Style "same input → same output"):
 *   - private const FIXED_EVENT_TIME = 1715000000;
 *   - Carbon::setTestNow freezes wall clock in setUp; tearDown unfreezes.
 *   - $arPayload['data'][0]['event_time'] is always seeded with
 *     self::FIXED_EVENT_TIME — SendCapiEvent reads event_time from the
 *     payload (not time()), so payload injection is the source of truth.
 *
 * Watcher dispatch path (LOCKED EXECUTOR DIRECTION, plan lines 298-307):
 *   Every scenario drives the chain via (new OrderStatusWatcher())->
 *   handleUpdated($obOrder) after $obOrder->save(). Queue is forced to
 *   `sync` in setUp so SendCapiEvent::dispatch (called from
 *   fireForwardDispatch's DB::afterCommit closure) resolves into an
 *   inline handle() invocation. NO direct dispatchSync calls from test
 *   bodies — that would bypass Watcher payload construction.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md (acceptance criteria 1-9)
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/VERIFICATION.md (Deferred-Runtime Verification lines 162-178)
 * @see plugins/logingrupa/metapixelshopaholic/tests/Feature/SendCapiEventEventLogTest.php (MockHandler MC-04 pattern + primeSettings HR-02 mitigation)
 * @see plugins/logingrupa/metapixelshopaholic/tests/Feature/OrderStatusWatcherEventLogTest.php (Event::subscribe + makePaidOrder fixture)
 * @see plugins/logingrupa/metapixelshopaholic/tests/Feature/PurchasePixelEventLogGateTest.php (onRun + onMarkFired component test pattern)
 * @see plugins/logingrupa/metapixelshopaholic/tests/Feature/MultiSiteEventLogTest.php (Config::set('system.active_site', $i) injection)
 */
final class PurchaseEndToEndIntegrationTest extends MetapixelTestCase
{
    /**
     * Deterministic event_time literal — Tiger-Style "same input → same output".
     * Picked to be a Unix-seconds value Meta's CAPI would accept (May 2024).
     */
    private const int FIXED_EVENT_TIME = 1715000000;

    /** Live nailscosmetics.lv Pixel id — read-only test constant. */
    private const string PIXEL_ID = '2291486191076331';

    /** CAPI token literal — never leaves the test process. */
    private const string CAPI_TOKEN = 'EAA-integration-token';

    protected function setUp(): void
    {
        parent::setUp();

        // LOCKED EXECUTOR DIRECTION (plan line 302): force sync queue so
        // SendCapiEvent::dispatch resolves into an inline handle() call.
        Config::set('queue.default', 'sync');

        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        $this->bootEventLogTable();
        OrderFixtures::provisionHermeticOfferProductTables();

        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();

        $this->primeSettings();
        $this->primePluginGuardEnabled(self::PIXEL_ID);

        // Belt-and-suspenders Carbon freeze; payload event_time injection
        // (makePayload) is the actual source of truth.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FIXED_EVENT_TIME));

        // Plugin::boot wires this via Event::subscribe — replicate manually
        // since the hermetic test harness has autoRegister=false.
        Event::subscribe(OrderStatusWatcher::class);
        OrderStatusWatcher::flushStatusCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // T-3.1-25 mitigation: prevent multi-site active-site bleed.
        Config::set('system.active_site', null);
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Scenario 1 — PayPal end-to-end CAPI + Pixel pair, same event_id.
     *
     * Paid Order save → Watcher → SendCapiEvent (sync) → EventLogWriter
     * (channel=capi) → one Guzzle POST → PurchasePixel::onRun renders
     * arMetaEvent from CAPI row → onMarkFired inserts Pixel row → both
     * rows share event_id + event_time.
     */
    public function test_paypal_flow_fires_capi_then_pixel_with_same_event_id(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "scenario-1"}'),
        ], $arHistory);

        // Drive chain via Watcher (no dispatchSync shortcut — locked direction).
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        // CAPI side: exactly one MockHandler POST + one CAPI row.
        $this->assertCount(1, $arHistory, 'Scenario 1: exactly one CAPI POST expected.');
        $iCapiRows = EventLog::where('channel', EventLog::CHANNEL_CAPI)->count();
        $this->assertSame(1, $iCapiRows, 'Scenario 1: exactly one CAPI row expected.');

        /** @var EventLog $obCapiRow */
        $obCapiRow = EventLog::where('channel', EventLog::CHANNEL_CAPI)->first();
        $sCapiEventId = (string) $obCapiRow->event_id;
        $iCapiEventTime = (int) $obCapiRow->event_time;

        // Pixel side: onRun renders arMetaEvent from CAPI row.
        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();
        $this->assertNotNull($obComponent->arMetaEvent, 'Scenario 1: onRun MUST populate arMetaEvent.');
        $this->assertSame($sCapiEventId, $obComponent->arMetaEvent['event_id'],
            'Scenario 1: Pixel and CAPI MUST share event_id.');
        $this->assertSame($iCapiEventTime, $obComponent->arMetaEvent['event_time'],
            'Scenario 1: Pixel and CAPI MUST share event_time.');

        // Browser AJAX confirmation: onMarkFired inserts Pixel row.
        Request::merge(['event_id' => $sCapiEventId]);
        $arResult = $obComponent->onMarkFired();
        $this->assertSame(['ok' => true, 'won_race' => true], $arResult,
            'Scenario 1: first onMarkFired MUST win race.');

        $iPixelRows = EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count();
        $this->assertSame(1, $iPixelRows, 'Scenario 1: exactly one Pixel row expected.');

        /** @var EventLog $obPixelRow */
        $obPixelRow = EventLog::where('channel', EventLog::CHANNEL_PIXEL)->first();
        $this->assertSame($sCapiEventId, (string) $obPixelRow->event_id,
            'Scenario 1: Pixel row event_id MUST equal CAPI row event_id.');
        $this->assertSame($iCapiEventTime, (int) $obPixelRow->event_time,
            'Scenario 1: Pixel row event_time MUST equal CAPI row event_time.');
    }

    /**
     * Scenario 2 — bank-transfer admin flip: CAPI only, Pixel on later visit.
     *
     * Order born at status_id=1 (new) — no dispatch. Admin flip to
     * status_id=5 (new-payment-received) triggers Watcher → one CAPI POST.
     * No Pixel twin yet (customer not on /checkout/{slug}). Later visit
     * via onRun populates arMetaEvent from the persisted CAPI row.
     */
    public function test_bank_transfer_admin_flip_fires_capi_only_pixel_renders_on_later_visit(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        // Demote to pending so the first save is at status 'new' (non-paid).
        $obOrder->forceFill(['status_id' => 1])->save();
        $obOrder = $obOrder->fresh();

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "scenario-2"}'),
        ], $arHistory);

        // Pre-condition: nothing has fired.
        $this->assertCount(0, $arHistory, 'Scenario 2: zero POSTs before admin flip.');
        $this->assertSame(0, EventLog::count(), 'Scenario 2: zero EventLog rows before admin flip.');

        // Admin flip to paid status — Watcher fires.
        $obOrder->status_id = 5;
        $obOrder->save();
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        $this->assertCount(1, $arHistory, 'Scenario 2: exactly one CAPI POST after admin flip.');
        $this->assertSame(1, EventLog::where('channel', EventLog::CHANNEL_CAPI)->count(),
            'Scenario 2: exactly one CAPI row after admin flip.');
        $this->assertSame(0, EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count(),
            'Scenario 2: zero Pixel rows immediately after admin flip (customer not on /checkout/{slug} yet).');

        // Customer visits /checkout/{slug} later → onRun populates arMetaEvent
        // from the persisted CAPI row.
        /** @var EventLog $obCapiRow */
        $obCapiRow = EventLog::where('channel', EventLog::CHANNEL_CAPI)->first();
        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNotNull($obComponent->arMetaEvent,
            'Scenario 2: customer visit MUST render Pixel from persisted CAPI row.');
        $this->assertSame((string) $obCapiRow->event_id, $obComponent->arMetaEvent['event_id'],
            'Scenario 2: Pixel render event_id MUST equal persisted CAPI row event_id.');
    }

    /**
     * Scenario 3 — status flip-flop with refire OFF: no second event_log
     * row, no second HTTP POST.
     *
     * Flip 1→5 fires (one POST + one CAPI row). Flip 5→3 is a no-op
     * (Watcher's isAtPaidStatus returns false). Flip 3→5 short-circuits
     * via alreadyDispatched (CAPI row already exists). MockHandler queue
     * has only ONE Response — if Watcher fails to short-circuit and a
     * second POST is attempted, MockHandler raises OutOfBoundsException
     * (Tiger-Style: exhaustion-as-assertion-mechanism).
     */
    public function test_status_flip_flop_with_refire_off_does_not_redispatch(): void
    {
        // HR-02 mitigation: reflection setAttribute (Settings::set flaps).
        $obSettings = Settings::instance();
        $obSettings->setAttribute('refire_purchase_on_status_flip', false);

        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill(['status_id' => 1])->save();
        $obOrder = $obOrder->fresh();

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "scenario-3"}'),
        ], $arHistory);

        // First flip 1→5: race winner.
        $obOrder->status_id = 5;
        $obOrder->save();
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        $this->assertCount(1, $arHistory, 'Scenario 3: first paid flip MUST fire exactly one POST.');
        $this->assertSame(1, EventLog::where('channel', EventLog::CHANNEL_CAPI)->count(),
            'Scenario 3: baseline CAPI row count = 1.');

        // Flip 5→3 (complete = away from paid): Watcher no-op via isAtPaidStatus.
        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 3;
        $obOrder->save();
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        $this->assertCount(1, $arHistory, 'Scenario 3: away flip MUST NOT POST.');
        $this->assertSame(1, EventLog::where('channel', EventLog::CHANNEL_CAPI)->count(),
            'Scenario 3: away flip MUST NOT add EventLog row.');

        // Flip 3→5 (back to paid): alreadyDispatched gate fires (CAPI row exists).
        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 5;
        $obOrder->save();
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        $this->assertCount(1, $arHistory,
            'Scenario 3: back-to-paid MUST short-circuit (alreadyDispatched gate). MockHandler stays at 1.');
        $this->assertSame(1, EventLog::where('channel', EventLog::CHANNEL_CAPI)->count(),
            'Scenario 3: back-to-paid MUST NOT add a second CAPI row.');
    }

    /**
     * Scenario 4 — pre-seeded Pixel row suppresses re-fire across
     * devices/sessions/time.
     *
     * Pre-seed both CAPI and Pixel rows (paid order). Two FRESH
     * PurchasePixel instances both onRun() → arMetaEvent stays null
     * (simulating refresh + different-device incognito). Second
     * onMarkFired call returns ['ok' => true, 'won_race' => false]
     * (UNIQUE-collapsed no-op); Pixel row count stays at 1.
     */
    public function test_pixel_row_suppresses_refire_across_devices_and_sessions(): void
    {
        // Bind a non-null active_site so the UNIQUE composite's site_id is a
        // concrete int on both seeded rows AND the writer's upcoming
        // insertOrIgnore call. Under SQLite (test DB) and MySQL (production)
        // UNIQUE treats NULL values as DISTINCT — two rows with NULL site_id
        // can coexist even when other columns match. Binding site_id=1 here
        // exercises the multi-site UNIQUE collision branch that production
        // staging hits when the operator runs October 4 with the multi-site
        // module installed (BRIEF.md REFAC-04 + sibling MultiSiteEventLogTest).
        Config::set('system.active_site', 1);

        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = 'ffffffff-eeee-dddd-cccc-bbbbbbbbbbbb';
        $this->seedEventLogRow($obOrder, $sEventId, self::FIXED_EVENT_TIME, EventLog::CHANNEL_CAPI, 1);
        $this->seedEventLogRow($obOrder, $sEventId, self::FIXED_EVENT_TIME, EventLog::CHANNEL_PIXEL, 1);

        // First "device" render — refresh after Pixel fired.
        $obFirst = $this->makeComponent((string) $obOrder->secret_key);
        $obFirst->onRun();
        $this->assertNull($obFirst->arMetaEvent,
            'Scenario 4: refresh after Pixel row exists MUST render nothing.');

        // Simulate different-device incognito: flush all in-process caches +
        // instantiate a FRESH component.
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
        $this->primePluginGuardEnabled(self::PIXEL_ID);

        $obSecond = $this->makeComponent((string) $obOrder->secret_key);
        $obSecond->onRun();
        $this->assertNull($obSecond->arMetaEvent,
            'Scenario 4: different-device incognito MUST render nothing (server-side persistence).');

        // Attempt a second onMarkFired with the same event_id — UNIQUE collapse.
        Request::merge(['event_id' => $sEventId]);
        $arResult = $obSecond->onMarkFired();
        $this->assertSame(['ok' => true, 'won_race' => false], $arResult,
            'Scenario 4: second onMarkFired with same event_id MUST return ok=true, won_race=false.');

        $iPixelRows = EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count();
        $this->assertSame(1, $iPixelRows,
            'Scenario 4: UNIQUE-collapsed onMarkFired MUST NOT insert a duplicate Pixel row.');
    }

    /**
     * Bonus multi-site sanity (acceptance criterion 9 cross-link).
     *
     * Two CAPI dispatches for the SAME Order id under different active-site
     * bindings → two independent CAPI rows + two MockHandler POSTs.
     * Exercises the FULL Watcher → SendCapiEvent → EventLogWriter chain
     * (not just the writer-in-isolation pattern MultiSiteEventLogTest
     * codifies). UNIQUE composite includes site_id, so the two rows live
     * on distinct unique-key tuples.
     */
    public function test_multi_site_same_order_id_records_two_independent_capi_rows(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "site-1"}'),
            new Response(200, [], '{"events_received": 1, "fbtrace_id": "site-2"}'),
        ], $arHistory);

        // Site A — active_site=1.
        Config::set('system.active_site', 1);
        (new OrderStatusWatcher)->handleUpdated($obOrder);
        $this->assertCount(1, $arHistory, 'Multi-site: site 1 dispatch MUST POST once.');
        $this->assertSame(1, EventLog::count(), 'Multi-site: one row after site 1 dispatch.');
        /** @var EventLog $obFirst */
        $obFirst = EventLog::first();
        $this->assertSame(1, (int) $obFirst->site_id, 'Multi-site: first row site_id = 1.');

        // Site B — active_site=2 for SAME Order id.
        Config::set('system.active_site', 2);
        (new OrderStatusWatcher)->handleUpdated($obOrder);

        $this->assertCount(2, $arHistory, 'Multi-site: site 2 dispatch MUST POST a second time.');
        $this->assertSame(2, EventLog::count(),
            'Multi-site: SAME Order id under two site_ids MUST yield two rows.');

        $obSecond = EventLog::where('site_id', 2)->first();
        $this->assertNotNull($obSecond, 'Multi-site: second row must have site_id=2.');
    }

    /**
     * Build a MetaClient backed by a MockHandler Guzzle Client AND set up a
     * Guzzle history middleware writing into the caller-provided by-ref
     * array. MC-04 pattern — copy verbatim from SendCapiEventEventLogTest.
     *
     * @param  list<Response|\Exception>  $arResponses
     * @param  array<int, array<string, mixed>>  $arHistory  passed by reference
     */
    private function bindMetaClientWithMockResponses(array $arResponses, array &$arHistory): MockHandler
    {
        $obMockHandler = new MockHandler($arResponses);
        $obStack = HandlerStack::create($obMockHandler);

        $obHistoryMw = Middleware::history($arHistory);
        $obStack->push($obHistoryMw);

        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);
        $this->app->instance(MetaClient::class, new MetaClient($obGuzzle));

        return $obMockHandler;
    }

    /**
     * Prime Settings via the reflection pattern (HR-02 workaround —
     * Settings::set flaps under SQLite-in-memory). Also seeds the
     * refire flag to OFF (the default; tests that need ON override).
     */
    private function primeSettings(): void
    {
        $obInstance = Settings::instance();
        $obInstance->setAttribute('pixel_id', self::PIXEL_ID);
        $obInstance->setAttribute('capi_access_token', self::CAPI_TOKEN);
        $obInstance->setAttribute('test_event_code', '');
        $obInstance->setAttribute('refire_purchase_on_status_flip', false);
        $obInstance->setAttribute('paid_status_code', 'new-payment-received');
    }

    /**
     * Reflection-prime PluginGuard into the enabled state. Mirrors
     * OrderStatusWatcherEventLogTest::primePluginGuardEnabled — bypasses
     * Settings::set HR-02 flap by writing directly to the memo properties.
     */
    private function primePluginGuardEnabled(string $sPixelId): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, false);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, $sPixelId);

        App::singleton('metapixel.disabled', fn (): bool => false);
    }

    /**
     * Pre-insert an EventLog row via the RAW DB facade — Eloquent's `$casts`
     * coerces null site_id → 0 on model insert which would break UNIQUE
     * collision with the writer's `DB::table(...)->insertOrIgnore(...)`
     * path that preserves NULL. Seeding via the same DB facade guarantees
     * the UNIQUE key tuple matches byte-for-byte against the writer's
     * upcoming INSERT so collision semantics fire as designed.
     *
     * `$iSiteId` defaults to null (single-site/CLI/queue worker context);
     * tests exercising the multi-site UNIQUE branch pass a concrete int.
     */
    private function seedEventLogRow(
        Order $obOrder,
        string $sEventId,
        int $iEventTime,
        string $sChannel,
        ?int $iSiteId = null,
    ): void {
        $sNow = (string) Carbon::createFromTimestamp($iEventTime);
        DB::table((new EventLog)->table)->insert([
            'event_id' => $sEventId,
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => $sChannel,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->secret_key,
            'site_id' => $iSiteId,
            'event_time' => $iEventTime,
            'fired_at' => $sNow,
            'created_at' => $sNow,
            'updated_at' => $sNow,
        ]);
    }

    /**
     * Instantiate PurchasePixel with an ArrayAccess stub (mirrors
     * Cms\Classes\CodeBase contract). Pattern lifted from
     * PurchasePixelEventLogGateTest::makeComponent.
     */
    private function makeComponent(string $sOrderSlug): PurchasePixel
    {
        $obStub = new class implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            public array $vars = [];

            public mixed $controller = null;

            #[\Override]
            public function offsetSet($offset, $value): void
            {
                $this->vars[(string) $offset] = $value;
            }

            #[\Override]
            public function offsetGet($offset): mixed
            {
                return $this->vars[(string) $offset] ?? null;
            }

            #[\Override]
            public function offsetExists($offset): bool
            {
                return array_key_exists((string) $offset, $this->vars);
            }

            #[\Override]
            public function offsetUnset($offset): void
            {
                unset($this->vars[(string) $offset]);
            }
        };

        $obComponent = new PurchasePixel($obStub);
        $obComponent->setProperty('orderSlug', $sOrderSlug);

        return $obComponent;
    }
}
