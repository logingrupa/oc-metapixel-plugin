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
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

/**
 * Feature test locking the Plan 03-06 PAY-03 OrderStatusWatcher dispatch
 * contract. Each test exercises one CONTEXT-line-162 scenario or one of
 * the additional event_id / event_time persistence invariants from
 * BLOCKER-2 plan revision.
 *
 *   1. test_fresh_paid_order_dispatches_send_capi_event_once — happy path.
 *   2. test_same_paid_status_save_does_not_redispatch — idempotency fence.
 *   3. test_status_flip_away_then_back_with_refire_off_fires_only_once
 *      — column persists across flip-flop when refire=false (default).
 *   4. test_status_flip_away_then_back_with_refire_on_fires_twice — refire
 *      cleared on away-transition, fires again on back-to-paid.
 *   5. test_plugin_disabled_does_not_dispatch — PluginGuard short-circuit.
 *   6. test_admin_created_already_paid_order_dispatches — eloquent.created.
 *   7. test_event_id_persisted_to_meta_purchase_event_id_column — UUID
 *      round-trip from handler to DB.
 *   8. test_event_time_persisted_to_meta_purchase_event_time_column
 *      — companion column written atomically (Pixel-twin contract).
 *   9. test_refire_on_clears_both_event_id_and_event_time_columns — refire
 *      away-clear nulls BOTH columns in a single saveQuietly.
 *  10. test_event_time_is_within_two_seconds_of_now — sanity check on the
 *      time() reading inside the handler.
 *
 * Test isolation:
 *  - Event::subscribe(OrderStatusWatcher::class) is wired in setUp() because
 *    Plugin::boot() doesn't run in the hermetic test harness (autoRegister =
 *    autoMigrate = false in MetapixelTestCase).
 *  - Queue::fake() replaces the queue manager so SendCapiEvent::dispatch
 *    records dispatch attempts without actually running handle().
 *  - PluginGuard reflection-prime sidesteps HR-02 (Settings::set('') round-trip
 *    flap in the SQLite-in-memory harness).
 *  - tearDown() flushes model event listeners so subscriber state doesn't
 *    bleed across test methods.
 */
final class OrderStatusWatcherTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        OrderFixtures::provisionHermeticOfferProductTables();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();

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

        Queue::assertPushed(SendCapiEvent::class, fn (SendCapiEvent $obJob): bool => $obJob->sEventName === 'Purchase');
        $this->assertNotNull($obOrder->fresh()->meta_purchase_event_id, 'meta_purchase_event_id must be persisted after dispatch.');
    }

    public function test_same_paid_status_save_does_not_redispatch(): void
    {
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();
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
        $obOrder->status_id = 4; // 'canceled'
        $obOrder->save();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 1);
        // refire=off: meta_purchase_event_id stays populated across the flip.
        $this->assertNotNull($obOrder->fresh()->meta_purchase_event_id);
    }

    public function test_status_flip_away_then_back_with_refire_on_fires_twice(): void
    {
        Settings::set('refire_purchase_on_status_flip', true);
        Cache::flush();
        Settings::clearInternalCache();

        $obOrder = $this->makeOrderAtPendingStatus();

        $obOrder->status_id = 5;
        $obOrder->save();
        $sFirstUuid = $obOrder->fresh()->meta_purchase_event_id;
        $this->assertNotNull($sFirstUuid, 'first paid flip must persist the UUID.');

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 4;
        $obOrder->save();
        $this->assertNull($obOrder->fresh()->meta_purchase_event_id, 'refire=on away-transition must clear the UUID column.');
        $this->assertNull($obOrder->fresh()->meta_purchase_event_time, 'refire=on away-transition must clear the event_time column.');

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 5;
        $obOrder->save();

        Queue::assertPushed(SendCapiEvent::class, 2);
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

    public function test_event_id_persisted_to_meta_purchase_event_id_column(): void
    {
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        $sPersisted = $obOrder->fresh()->meta_purchase_event_id;
        $this->assertNotNull($sPersisted);
        $this->assertTrue(Uuid::isValid((string) $sPersisted), 'persisted event_id must be a valid UUID.');

        // Assert the dispatched payload's event_id equals the persisted column.
        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($sPersisted): bool {
            $mxEventId = $obJob->arPayload['data'][0]['event_id'] ?? null;

            return is_string($mxEventId) && $mxEventId === (string) $sPersisted;
        });
    }

    public function test_event_time_persisted_to_meta_purchase_event_time_column(): void
    {
        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();

        $iPersisted = (int) $obOrder->fresh()->meta_purchase_event_time;
        $this->assertNotSame(0, $iPersisted, 'meta_purchase_event_time must be persisted alongside event_id.');

        // Assert dispatched payload's event_time equals the persisted column.
        Queue::assertPushed(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($iPersisted): bool {
            $mxEventTime = $obJob->arPayload['data'][0]['event_time'] ?? null;

            return is_int($mxEventTime) && $mxEventTime === $iPersisted;
        });
    }

    public function test_refire_on_clears_both_event_id_and_event_time_columns(): void
    {
        Settings::set('refire_purchase_on_status_flip', true);
        Cache::flush();
        Settings::clearInternalCache();

        $obOrder = $this->makeOrderAtPendingStatus();
        $obOrder->status_id = 5;
        $obOrder->save();
        $this->assertNotNull($obOrder->fresh()->meta_purchase_event_id);
        $this->assertNotNull($obOrder->fresh()->meta_purchase_event_time);

        $obOrder = $obOrder->fresh();
        $obOrder->status_id = 4;
        $obOrder->save();

        $this->assertNull($obOrder->fresh()->meta_purchase_event_id, 'away-transition must clear event_id atomically.');
        $this->assertNull($obOrder->fresh()->meta_purchase_event_time, 'away-transition must clear event_time atomically.');
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
     * back to pending (status_id=1 from bootOrdersStatuses) AND clear the
     * dedup columns the OrderStatusWatcher will have written during the
     * fixture's initial eloquent.created event (makePaidOrder() creates
     * the order at status_id=5, which fires the handleCreated path under
     * the global Event subscription). Without the clear, the test's
     * subsequent status_id=5 save() would hit the idempotency fence and
     * no-op. Also resets Queue::fake's pushed-job buffer so the test
     * starts from a clean count.
     */
    private function makeOrderAtPendingStatus(): Order
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'status_id' => 1,
            'meta_purchase_event_id' => null,
            'meta_purchase_event_time' => null,
        ])->save();
        // Reset Queue::fake buffer so the test counts only its own dispatches.
        Queue::fake();

        return $obOrder->fresh();
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
