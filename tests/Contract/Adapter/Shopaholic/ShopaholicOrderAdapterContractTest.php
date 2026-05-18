<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-01 contract proof — ShopaholicOrderAdapter satisfies all 10 invariants
 * of EventSubjectAdapterContractTestCase. Hermetic Orders + Statuses tables
 * are provisioned inline (the abstract base extends MetapixelTestCase, not
 * ShopaholicAdapterTestCase, so the cart-plugin schema must be created here).
 */
#[Group('adapter')]
final class ShopaholicOrderAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOrdersTable();
        $this->bootOrdersStatuses();
        $this->bootOrderPositionsTable();
        $this->bootPromoMechanismTable();
        $this->bootTaxesTable();
        Settings::clearInternalCache();
        Settings::set(['default_currency_code' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_taxes');
        Schema::dropIfExists('lovata_orders_shopaholic_order_promo_mechanism');
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_orders_shopaholic_orders');
        Schema::dropIfExists('lovata_orders_shopaholic_statuses');
        parent::tearDown();
    }

    protected function makeAdapter(): EventSubjectAdapter
    {
        return new ShopaholicOrderAdapter;
    }

    protected function makeSubject(): object
    {
        // In-memory Order without persisting — invariant 02 requires a positive
        // subject_id, so we set id via the public Eloquent setAttribute API.
        // Persisting + Order::find() triggers a Lovata model-event cascade
        // (PromoMechanism + ActiveListStore taxes + product/offer relations)
        // that drags in 5+ tables irrelevant to the contract assertions.
        $obOrder = new Order;
        $obOrder->setAttribute('id', 1);
        $obOrder->setAttribute('status_id', 5);
        $obOrder->setAttribute('secret_key', 'test-secret-abc');
        $obOrder->setAttribute('total_price_value', 12.50);
        $obOrder->setAttribute('email', 'a@b.test');
        $obOrder->setAttribute('phone', '123');
        $obOrder->setAttribute('name', 'A');
        $obOrder->setAttribute('last_name', 'B');
        $obOrder->setAttribute('site_id', 1);
        $obOrder->setRelation('order_position', collect());
        $obOrder->setRelation('currency', null);
        $obOrder->setRelation('status', null);

        return $obOrder;
    }

    private function bootOrdersTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_orders')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_orders', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('status_id')->nullable();
            $obTable->string('order_number')->nullable();
            $obTable->string('secret_key')->nullable();
            $obTable->decimal('total_price_value', 15, 2)->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->string('email')->nullable();
            $obTable->string('phone')->nullable();
            $obTable->string('name')->nullable();
            $obTable->string('last_name')->nullable();
            $obTable->integer('site_id')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootTaxesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_taxes')) {
            return;
        }
        Schema::create('lovata_shopaholic_taxes', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->decimal('percent', 15, 2)->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    private function bootPromoMechanismTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_order_promo_mechanism')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_order_promo_mechanism', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('order_position_id')->nullable();
            $obTable->text('priority')->nullable();
            $obTable->text('property')->nullable();
            $obTable->string('type')->nullable();
            $obTable->integer('related_id')->nullable();
            $obTable->string('related_type')->nullable();
            $obTable->decimal('discount_value', 15, 4)->nullable();
            $obTable->string('discount_type')->nullable();
            $obTable->text('discount_data')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootOrderPositionsTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_order_positions')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_order_positions', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->integer('offer_id')->nullable();
            $obTable->decimal('price_value', 15, 2)->nullable();
            $obTable->integer('quantity')->default(1);
            $obTable->text('property')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootOrdersStatuses(): void
    {
        if (! Schema::hasTable('lovata_orders_shopaholic_statuses')) {
            Schema::create('lovata_orders_shopaholic_statuses', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name');
                $obTable->string('code')->unique();
                $obTable->string('color')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->boolean('is_user_show')->default(true);
                $obTable->unsignedInteger('user_status_id')->nullable();
                $obTable->integer('sort_order')->default(0);
                $obTable->timestamps();
            });
        }
        DB::table('lovata_orders_shopaholic_statuses')->insertOrIgnore([
            ['id' => 1, 'name' => 'New', 'code' => 'new', 'sort_order' => 1, 'is_user_show' => 1],
            ['id' => 5, 'name' => 'New payment received', 'code' => 'new-payment-received', 'sort_order' => 5, 'is_user_show' => 1],
        ]);
    }
}
