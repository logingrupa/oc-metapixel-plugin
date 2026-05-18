<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\Shopaholic\Models\Offer;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-01 (carry-forward) contract proof — ShopaholicCartPositionAdapter
 * satisfies the 10 invariants of EventSubjectAdapterContractTestCase.
 * Hermetic CartPosition + Cart + Offer + Product schemas are provisioned
 * inline (the abstract base extends MetapixelTestCase, not
 * ShopaholicAdapterTestCase).
 */
#[Group('adapter')]
final class ShopaholicCartPositionAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCartPositionTable();
        $this->bootCartTable();
        $this->bootOffersAndProductsTables();
        $this->bootPricesTable();
        Settings::clearInternalCache();
        Settings::set(['default_currency_code' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_prices');
        Schema::dropIfExists('lovata_orders_shopaholic_cart_positions');
        Schema::dropIfExists('lovata_orders_shopaholic_carts');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_products');
        parent::tearDown();
    }

    protected function makeAdapter(): EventSubjectAdapter
    {
        return new ShopaholicCartPositionAdapter;
    }

    protected function makeSubject(): object
    {
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => 1, 'name' => 'Test Product', 'slug' => 'test-product'],
        ]);
        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => 1, 'product_id' => 1, 'name' => 'Test Offer', 'price_value' => 12.50],
        ]);
        DB::table('lovata_orders_shopaholic_carts')->insertOrIgnore([
            ['id' => 1, 'site_id' => 1],
        ]);
        DB::table('lovata_orders_shopaholic_cart_positions')->insertOrIgnore([
            ['id' => 1, 'cart_id' => 1, 'item_id' => 1, 'item_type' => Offer::class, 'quantity' => 2],
        ]);

        $obPosition = CartPosition::find(1);
        if ($obPosition === null) {
            $this->fail('Failed to load CartPosition fixture');
        }

        return $obPosition;
    }

    private function bootCartPositionTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_cart_positions')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_cart_positions', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('cart_id')->nullable();
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->text('property')->nullable();
            $obTable->integer('quantity')->nullable();
            $obTable->timestamps();
            $obTable->softDeletes();
        });
    }

    private function bootCartTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_carts')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_carts', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->integer('site_id')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootPricesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_prices')) {
            return;
        }
        Schema::create('lovata_shopaholic_prices', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('item_id');
            $obTable->string('item_type');
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootOffersAndProductsTables(): void
    {
        if (! Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('product_id')->nullable();
                $obTable->string('name')->nullable();
                $obTable->decimal('price_value', 15, 2)->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->integer('sort_order')->default(0);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_products')) {
            Schema::create('lovata_shopaholic_products', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->nullable();
                $obTable->string('slug')->nullable()->unique();
                $obTable->boolean('active')->default(true);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
    }
}
