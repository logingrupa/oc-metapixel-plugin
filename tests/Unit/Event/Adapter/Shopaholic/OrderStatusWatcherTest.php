<?php

namespace Logingrupa\Metapixel\Tests\Unit\Event\Adapter\Shopaholic;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-03 — OrderStatusWatcher dispatch logic. Exercises the handler directly
 * (not through real eloquent.updated firing) and asserts SendCapiEvent::dispatch
 * is called on the paid-status match path AND skipped on the non-match / no-
 * status-change paths. Tiger-Style log + return (no rethrow) is asserted on
 * the catch path.
 */
#[Group('adapter')]
final class OrderStatusWatcherTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHermeticTables();
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(Order::class, ShopaholicOrderAdapter::class);
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
            'paid_status_code' => 'new-payment-received',
            'default_currency_code' => 'EUR',
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_taxes');
        Schema::dropIfExists('lovata_orders_shopaholic_order_promo_mechanism');
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_orders_shopaholic_orders');
        Schema::dropIfExists('lovata_orders_shopaholic_statuses');
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_dispatches_purchase_on_paid_status_match_with_status_changed(): void
    {
        Bus::fake();
        Log::spy();
        $obOrder = $this->makePaidOrder(iOriginalStatusId: 1, iCurrentStatusId: 5);

        (new OrderStatusWatcher)->handle($obOrder);

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'Purchase'
                && $obJob->sAdapterClass === ShopaholicOrderAdapter::class;
        });
    }

    public function test_does_not_dispatch_when_status_code_does_not_match_paid_setting(): void
    {
        Bus::fake();
        $obOrder = $this->makePaidOrder(iOriginalStatusId: 1, iCurrentStatusId: 3);

        (new OrderStatusWatcher)->handle($obOrder);

        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_does_not_dispatch_when_status_unchanged(): void
    {
        Bus::fake();
        // Already-persisted Order with status_id=5 but wasChanged returns false
        // (no change applied this save).
        $obOrder = $this->makePaidOrder(iOriginalStatusId: 5, iCurrentStatusId: 5, bExists: true);

        (new OrderStatusWatcher)->handle($obOrder);

        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_catches_payload_build_exception_logs_warning_does_not_rethrow(): void
    {
        Bus::fake();
        Settings::set(['default_currency_code' => '']);
        Log::spy();

        // Currency-less order at the paid status — PayloadBuilder will trip
        // OrderHasNoCurrencyException during resolveCurrency. Watcher catches
        // it, logs warning, returns silently.
        $obOrder = $this->makePaidOrder(iOriginalStatusId: 1, iCurrentStatusId: 5);

        (new OrderStatusWatcher)->handle($obOrder);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $sMessage): bool {
                return str_contains($sMessage, 'OrderStatusWatcher payload-build failed');
            })
            ->atLeast()->once();
        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    private function makePaidOrder(int $iOriginalStatusId, int $iCurrentStatusId, bool $bExists = false): Order
    {
        $obStatus = new Status;
        $obStatus->setAttribute('id', $iCurrentStatusId);
        $obStatus->setAttribute('code', $iCurrentStatusId === 5 ? 'new-payment-received' : 'complete');

        $obOrder = new Order;
        $obOrder->setAttribute('id', 1);
        $obOrder->setAttribute('site_id', 1);
        $obOrder->setAttribute('secret_key', 'sec-abc');
        $obOrder->setAttribute('status_id', $iCurrentStatusId);
        $obOrder->setRelation('status', $obStatus);
        $obOrder->setRelation('order_position', collect());
        $obOrder->setRelation('currency', null);

        if ($bExists) {
            $obOrder->exists = true;
            $obOrder->syncOriginal();
        } else {
            // Simulate an Eloquent post-save state where status_id flipped
            // from $iOriginalStatusId to $iCurrentStatusId. Eloquent's
            // wasChanged() reads the internal `$changes` array (populated by
            // finishSave). We populate it via reflection so the test stays
            // decoupled from the model's persist + save lifecycle.
            $obOrder->exists = true;
            $obOrder->syncOriginal();
            $obChangesProp = new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'changes');
            $obChangesProp->setAccessible(true);
            $obChangesProp->setValue($obOrder, ['status_id' => $iOriginalStatusId]);
        }

        return $obOrder;
    }

    private function bootHermeticTables(): void
    {
        if (! Schema::hasTable('lovata_orders_shopaholic_orders')) {
            Schema::create('lovata_orders_shopaholic_orders', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('status_id')->nullable();
                $obTable->string('secret_key')->nullable();
                $obTable->integer('site_id')->nullable();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_orders_shopaholic_statuses')) {
            Schema::create('lovata_orders_shopaholic_statuses', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name');
                $obTable->string('code')->unique();
                $obTable->integer('sort_order')->default(0);
                $obTable->timestamps();
            });
            DB::table('lovata_orders_shopaholic_statuses')->insertOrIgnore([
                ['id' => 5, 'name' => 'New payment received', 'code' => 'new-payment-received', 'sort_order' => 5],
                ['id' => 3, 'name' => 'Complete', 'code' => 'complete', 'sort_order' => 3],
            ]);
        }
        if (! Schema::hasTable('lovata_orders_shopaholic_order_positions')) {
            Schema::create('lovata_orders_shopaholic_order_positions', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('order_id');
                $obTable->integer('quantity')->default(1);
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_orders_shopaholic_order_promo_mechanism')) {
            Schema::create('lovata_orders_shopaholic_order_promo_mechanism', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('order_id');
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_taxes')) {
            Schema::create('lovata_shopaholic_taxes', function ($obTable): void {
                $obTable->increments('id');
                $obTable->decimal('percent', 15, 2)->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->integer('sort_order')->default(0);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
    }
}
