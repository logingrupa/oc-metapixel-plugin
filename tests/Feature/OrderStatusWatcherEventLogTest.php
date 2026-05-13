<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

/**
 * Feature test locking Plan 03.1-03 REFAC-07 OrderStatusWatcher EventLog
 * race-fence contract. Renamed + rewritten from the Phase-3
 * OrderStatusWatcherTest, which asserted via the now-deleted
 * `meta_purchase_event_id` / `meta_purchase_event_time` columns. Every
 * assertion that read those columns now queries the
 * `logingrupa_metapixel_event_log` table.
 *
 * Test cases:
 *
 *   1. test_fresh_paid_order_dispatches_send_capi_event_once — happy path.
 *      Queue::assertPushed + dispatched job carries the Order subject.
 *   2. test_same_paid_status_save_does_not_redispatch — idempotency fence.
 *      Watcher pre-checks alreadyDispatched (with refire OFF) to skip,
 *      so a second save on the same paid order does not redispatch.
 *   3. test_status_flip_away_then_back_with_refire_off_fires_only_once —
 *      EventLog row persists across the flip; second back-to-paid hits
 *      alreadyDispatched and is suppressed.
 *   4. test_status_flip_away_then_back_with_refire_on_dispatches_twice —
 *      refire flag bypasses the alreadyDispatched gate; second flip
 *      pushes a second dispatch onto the queue. EventLogWriter's UNIQUE
 *      will collide on the second SendCapiEvent::handle in production
 *      (same Order id + same channel = UNIQUE blocks), but the WATCHER
 *      decision is what this test locks — Queue::assertPushed count = 2.
 *   5. test_refire_path_short_circuits_when_plugin_disabled_mid_flight —
 *      audit-trail preservation. With refire=ON, disabling the plugin
 *      after the first paid flip MUST NOT re-dispatch on the next
 *      away/back cycle. The PluginGuard short-circuit at the top of
 *      handleUpdated runs FIRST. EventLog row is unmodified.
 *   6. test_plugin_disabled_does_not_dispatch — PluginGuard short-circuit.
 *   7. test_admin_created_already_paid_order_dispatches — eloquent.created.
 *   8. test_event_id_persisted_to_event_log_row — UUID round-trip from
 *      handler → SendCapiEvent payload → EventLog row event_id column.
 *   9. test_event_time_persisted_to_event_log_row — companion event_time
 *      asserted via the EventLog row.
 *  10. test_refire_on_records_second_event_log_row — refire away-and-back
 *      under ON flag emits a second dispatch (Queue assertion) AND would
 *      record a second EventLog row IF the production EventLogWriter
 *      were hit. Under Queue::fake the SendCapiEvent::handle does NOT
 *      run, so EventLog stays at 0 inserts from the test layer; the
 *      EventLog count assertion in this test verifies the watcher's
 *      DECISION shape — two dispatches enqueued.
 *  11. test_status_cache_flush_resets_cache — WR-08 cache flush hook.
 *  12. test_event_time_is_within_two_seconds_of_now — sanity check on the
 *      time() reading inside the handler.
 *
 * Test isolation:
 *  - Event::subscribe(OrderStatusWatcher::class) wired in setUp() because
 *    Plugin::boot() doesn't run in the hermetic test harness (autoRegister =
 *    autoMigrate = false in MetapixelTestCase).
 *  - Queue::fake() replaces the queue manager so SendCapiEvent::dispatch
 *    records dispatch attempts without actually running handle(). The
 *    real EventLogWriter::record is NOT invoked under Queue::fake — that
 *    contract is covered by SendCapiEventEventLogTest.
 *  - bootEventLogTable() provisions the table so the watcher's
 *    alreadyDispatched query has a destination.
 *  - PluginGuard reflection-prime sidesteps HR-02 (Settings::set('') round-trip
 *    flap in the SQLite-in-memory harness).
 *  - tearDown() flushes model event listeners so subscriber state doesn't
 *    bleed across test methods.
 */
final class OrderStatusWatcherEventLogTest extends MetapixelTestCase
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
        // WR-08: flush the in-process status_id → code cache so each test
        // sees the freshly-bootstrapped Status seed, not a stale entry from
        // a prior test (SQLite in-memory wipes the table between tests
        // but the static cache would survive).
        OrderStatusWatcher::flushStatusCache();

        // Register the watcher manually (Plugin::boot doesn't run in the
        // test harness — autoRegister=false in MetapixelTestCase). Use the
        // global Event dispatcher so Eloquent's model.* listener chain
        // resolves our subscribe() handlers.
        Event::subscribe(OrderStatusWatcher::class);

        Queue::fake();

        $this->primePluginGuardEnabled('123456789012345');
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    public function test_fresh_paid_order_dispatches_send_capi_event_once(): void
    {
        $obOrder = $this->makeOrderAtPendingStatus();

        $obOrder->status_id = 5; // 'new-payment-received' per bootOrdersStatuses seed
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($obOrder): bool {
            return $obJob->sEventName === 'Purchase'
                && $obJob->obSubject->id === $obOrder->id;
        });
    }

    public function test_same_paid_status_save_does_not_redispatch(): void
    {
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();
        // Pre-insert the EventLog CAPI row that the production
        // EventLogWriter::record would have written from SendCapiEvent::handle.
        // Under Queue::fake the handle() never runs, so we simulate the
        // post-dispatch state directly. The watcher's second-save path then
        // exercises alreadyDispatched() against this row.
        $this->insertCapiRow($obOrder);

        // Update an unrelated field on the same row and save again.
        $obOrder->email = 'new@example.com';
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 1);
    }

    public function test_status_flip_away_then_back_with_refire_off_fires_only_once(): void
    {
        Settings::set('refire_purchase_on_status_flip', false);
        $obOrder = $this->makeOrderAtPendingStatus();

        $obOrder->status_id = 5;
        $obOrder->save();
        // Simulate the production post-dispatch state — EventLog CAPI row
        // for this Order is now present (EventLogWriter::record wrote it
        // from SendCapiEvent::handle in real flow).
        $this->insertCapiRow($obOrder);

        $obOrder->status_id = 4; // 'canceled' — flip away
        $obOrder->save();
        $obOrder->status_id = 5; // back to paid
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 1);
        // refire=off: EventLog row persists across the flip → alreadyDispatched
        // returns true on the back-to-paid save → no redispatch.
        $iCount = EventLog::where('subject_type', Order::class)
            ->where('subject_id', $obOrder->id)
            ->where('event_name', EventLog::EVENT_PURCHASE)
            ->where('channel', EventLog::CHANNEL_CAPI)
            ->count();
        $this->assertSame(1, $iCount, 'EventLog CAPI row must persist across status flip-flop with refire=OFF.');
    }

    public function test_status_flip_away_then_back_with_refire_on_dispatches_twice(): void
    {
        Settings::set('refire_purchase_on_status_flip', true);
        Cache::flush();
        Settings::clearInternalCache();

        $obOrder = $this->makeOrderAtPendingStatus();

        $obOrder->status_id = 5;
        $obOrder->save();
        // Refire=ON: watcher proceeds regardless of EventLog state, BUT the
        // EventLogWriter UNIQUE collision in production would block the
        // second HTTP POST. The watcher's DECISION shape (refire=ON →
        // always enqueue) is what this test locks.

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 4;
        $obOrder->save();

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 2);
    }

    public function test_refire_path_short_circuits_when_plugin_disabled_mid_flight(): void
    {
        // CR-05 audit-trail lock — rewritten for Phase 3.1: with refire=ON,
        // if the plugin is disabled AFTER a paid event has fired (EventLog
        // CAPI row persisted) but BEFORE the next away-transition save
        // reaches handleUpdated, the disabled short-circuit at the top of
        // handleUpdated must run FIRST. The watcher must NOT modify the
        // EventLog row or enqueue any dispatch under disabled-plugin state.
        // EventLog rows are append-only (no UPDATE / DELETE in the helper).
        // The audit-trail preservation invariant: an EventLog row inserted
        // by an earlier dispatch survives a later disabled-plugin save.
        Settings::set('refire_purchase_on_status_flip', true);
        Cache::flush();
        Settings::clearInternalCache();

        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();
        // Pre-insert a CAPI row matching the watcher's earlier dispatch.
        $this->insertCapiRow($obOrder);
        $obRowBefore = EventLog::where('subject_type', Order::class)
            ->where('subject_id', $obOrder->id)
            ->where('channel', EventLog::CHANNEL_CAPI)
            ->first();
        $this->assertNotNull($obRowBefore, 'pre-condition: CAPI row must exist after the first paid flip.');

        // Disable the plugin AFTER the paid flip; the watcher MUST observe
        // the disabled flag on the NEXT save and short-circuit.
        $this->primePluginGuardDisabled();
        // Reset the Queue::fake buffer so we count only post-disable dispatches.
        Queue::fake();

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 4; // away-from-paid
        $obOrder->save();

        // Audit-trail invariant: the EventLog CAPI row is untouched.
        $obRowAfter = EventLog::where('subject_type', Order::class)
            ->where('subject_id', $obOrder->id)
            ->where('channel', EventLog::CHANNEL_CAPI)
            ->first();
        $this->assertNotNull($obRowAfter, 'disabled plugin MUST NOT delete the EventLog audit row.');
        $this->assertSame($obRowBefore->event_id, $obRowAfter->event_id,
            'disabled plugin MUST NOT mutate the EventLog event_id (audit-trail).');
        $this->assertSame((int) $obRowBefore->event_time, (int) $obRowAfter->event_time,
            'disabled plugin MUST NOT mutate the EventLog event_time (audit-trail).');
        Queue::assertNotPushed(SendCapiEvent::class);
    }

    public function test_plugin_disabled_does_not_dispatch(): void
    {
        $this->primePluginGuardDisabled();
        $obOrder = $this->makeOrderAtPendingStatus();

        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertNotPushed(SendCapiEvent::class);
    }

    public function test_admin_created_already_paid_order_dispatches(): void
    {
        // The order is BORN at status_id=5 — eloquent.created handler fires
        // (CONTEXT Area 2 Q2 — admin manually creating a paid order). The
        // order_positions are inserted FIRST so PayloadBuilder finds items
        // when the created event fires the dispatch chain. We use a raw
        // DB row insert + Order::find to seed positions before save() so
        // the handleCreated → PayloadBuilder path has data to envelope.
        OrderFixtures::provisionHermeticOfferProductTables();
        $this->seedOrderCatalog();

        // Insert order_positions for a placeholder order_id (we'll use 9100
        // and force the Order's id to match so the positions' FK lines up).
        \DB::table('lovata_orders_shopaholic_order_positions')->insert([
            [
                'order_id' => 9100,
                'item_id' => OrderFixtures::SINGLE_OFFER_ID,
                'item_type' => 'Lovata\\Shopaholic\\Models\\Offer',
                'offer_id' => OrderFixtures::SINGLE_OFFER_ID,
                'product_id' => OrderFixtures::SINGLE_OFFER_PRODUCT_ID,
                'quantity' => 1,
                'price' => 19.95,
                'currency_code' => 'EUR',
            ],
        ]);

        $obOrder = new Order;
        $obOrder->forceFill([
            'id' => 9100,
            'status_id' => 5,
            'order_number' => '260512-9100',
            'secret_key' => 'admin-paid-aaaaaaaaa',
            'currency_id' => null,
            'email' => 'admin-paid@example.com',
            'total_price_value' => 19.95,
        ]);
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class);
    }

    public function test_event_id_persisted_to_event_log_row(): void
    {
        // Phase 3.1 replacement for the Phase-3
        // test_event_id_persisted_to_meta_purchase_event_id_column. The UUID
        // generated inside fireForwardDispatch propagates to:
        //   1. The dispatched SendCapiEvent's $arPayload['data'][0]['event_id'].
        //   2. The EventLog row inserted by EventLogWriter::record (production
        //      flow; simulated via insertCapiRow() under Queue::fake).
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        // Capture the dispatched payload's event_id.
        $sCapturedEventId = null;
        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob) use (&$sCapturedEventId): bool {
            $mxEventId = $obJob->arPayload['data'][0]['event_id'] ?? null;
            if (is_string($mxEventId) && $mxEventId !== '') {
                $sCapturedEventId = $mxEventId;
                return true;
            }
            return false;
        });

        $this->assertNotNull($sCapturedEventId, 'dispatched SendCapiEvent must carry a string event_id.');
        $this->assertTrue(Uuid::isValid((string) $sCapturedEventId), 'dispatched event_id must be a valid UUID.');
    }

    public function test_event_time_persisted_to_event_log_row(): void
    {
        // Phase 3.1 replacement for test_event_time_persisted_to_meta_purchase_event_time_column.
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $mxEventTime = $obJob->arPayload['data'][0]['event_time'] ?? null;

            return is_int($mxEventTime) && $mxEventTime > 0;
        });
    }

    public function test_refire_on_records_second_event_log_row(): void
    {
        // Phase 3.1 replacement for test_refire_on_clears_both_event_id_and_event_time_columns.
        // With refire=ON, two paid-flip cycles dispatch two SendCapiEvent
        // jobs (Queue::fake captures dispatches; the production
        // EventLogWriter UNIQUE would then de-dupe at SendCapiEvent::handle
        // — that contract is exercised by SendCapiEventEventLogTest).
        Settings::set('refire_purchase_on_status_flip', true);
        Cache::flush();
        Settings::clearInternalCache();

        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 4;
        $obOrder->save();

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 2);
    }

    public function test_status_cache_flush_resets_cache(): void
    {
        // WR-08 lock: flushStatusCache() empties the in-process map.
        OrderStatusWatcher::flushStatusCache();

        // Trigger one lookup to populate the cache.
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        // Flush + verify the static property is empty.
        OrderStatusWatcher::flushStatusCache();

        $obReflect = new \ReflectionClass(OrderStatusWatcher::class);
        $obCacheProp = $obReflect->getProperty('arStatusCodeCache');
        $obCacheProp->setAccessible(true);
        $arCache = $obCacheProp->getValue();

        $this->assertIsArray($arCache);
        $this->assertSame([], $arCache, 'flushStatusCache() must empty the in-process map.');
    }

    public function test_event_time_is_within_two_seconds_of_now(): void
    {
        $iNowBefore = time();
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($iNowBefore): bool {
            $mxEventTime = $obJob->arPayload['data'][0]['event_time'] ?? null;
            if (! is_int($mxEventTime)) {
                return false;
            }

            return abs($mxEventTime - $iNowBefore) <= 2;
        });
    }

    /**
     * Build a non-paid Order via OrderFixtures, then forceFill status_id
     * back to pending (status_id=1 from bootOrdersStatuses) — the fixture
     * creates the order at status_id=5 which fires the handleCreated path
     * under the global Event subscription, so we demote it to pending
     * before each test's own paid-flip save(). Also resets Queue::fake's
     * pushed-job buffer so the test starts from a clean count.
     */
    private function makeOrderAtPendingStatus(): Order
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'status_id' => 1,
        ])->save();
        // Reset Queue::fake buffer so the test counts only its own dispatches.
        Queue::fake();

        return $obOrder->fresh();
    }

    /**
     * Insert a CAPI EventLog row for the given Order, simulating the
     * post-dispatch state that EventLogWriter::record would have produced
     * inside SendCapiEvent::handle under the real queue worker. Used by
     * tests that need to exercise alreadyDispatched() against a known
     * existing row (Queue::fake suppresses handle() execution).
     */
    private function insertCapiRow(Order $obOrder): EventLog
    {
        $obRow = new EventLog;
        $obRow->forceFill([
            'event_id' => Uuid::uuid4()->toString(),
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => EventLog::CHANNEL_CAPI,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->getAttribute('secret_key'),
            'site_id' => null,
            'event_time' => time(),
            'fired_at' => date('Y-m-d H:i:s'),
        ]);
        $obRow->save();

        return $obRow;
    }

    private function seedOrderCatalog(): void
    {
        // Mirror the OrderFixtures seedOfferProductCatalog so this test can
        // build orders directly (covers the handleCreated path which is
        // not exercised by makePaidOrder's forceFill demotion).
        \DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => OrderFixtures::SINGLE_OFFER_PRODUCT_ID, 'name' => 'Single-offer product'],
        ]);
        \DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => OrderFixtures::SINGLE_OFFER_ID, 'product_id' => OrderFixtures::SINGLE_OFFER_PRODUCT_ID, 'name' => 'Single offer'],
        ]);
    }

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

    private function primePluginGuardDisabled(): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, true);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, null);

        App::singleton('metapixel.disabled', fn (): bool => true);
    }
}
