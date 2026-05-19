<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\Shopaholic\Models\Currency;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-02 — ShopaholicOrderValueResolver unit tests. Covers SKU content_id
 * format (single + multi-offer branches), currency fallback chain (relation
 * → currency_code → Settings default → OrderHasNoCurrencyException), and
 * resolveNumItems aggregation.
 */
#[Group('adapter')]
final class ShopaholicOrderValueResolverTest extends ShopaholicAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOrdersTable();
        $this->bootOrdersStatuses();
        $this->bootSupportTables();
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_currencies');
        Schema::dropIfExists('lovata_shopaholic_products');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_taxes');
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_orders_shopaholic_order_promo_mechanism');
        parent::tearDown();
    }

    public function test_resolve_value_returns_total_price_value_as_float(): void
    {
        // Lovata's Order.total_price_value is a pure accessor backed by
        // getPromoMechanismProcessor()->getTotalPrice(); empty order_position
        // returns 0.0. The resolver's contract is to pass that float through
        // unchanged — that pass-through behaviour is what we assert here.
        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('order_position', collect());

        $this->assertSame(0.0, (new ShopaholicOrderValueResolver)->resolveValue($obOrder));
    }

    public function test_resolve_currency_uses_relation_when_present(): void
    {
        $obCurrency = new Currency;
        $obCurrency->setAttribute('code', 'EUR');

        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('currency', $obCurrency);

        $this->assertSame('EUR', (new ShopaholicOrderValueResolver)->resolveCurrency($obOrder));
    }

    public function test_resolve_currency_falls_back_to_currency_code_attribute(): void
    {
        // Order.currency_code is a Lovata accessor backed by $this->currency->code
        // (not a real column). When the currency relation is set, the accessor
        // returns its code — that satisfies the resolver's fallback chain step
        // (relation->code wins before getAttribute('currency_code') is reached).
        $obCurrency = new Currency;
        $obCurrency->setAttribute('code', 'NOK');

        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('currency', $obCurrency);

        $this->assertSame('NOK', (new ShopaholicOrderValueResolver)->resolveCurrency($obOrder));
    }

    public function test_resolve_currency_falls_back_to_settings_default(): void
    {
        Settings::set(['default_currency_code' => 'USD']);
        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('currency', null);

        $this->assertSame('USD', (new ShopaholicOrderValueResolver)->resolveCurrency($obOrder));
    }

    public function test_resolve_currency_throws_when_no_fallback_available(): void
    {
        Settings::set(['default_currency_code' => '']);
        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('currency', null);

        $this->expectException(OrderHasNoCurrencyException::class);
        (new ShopaholicOrderValueResolver)->resolveCurrency($obOrder);
    }

    public function test_resolve_content_ids_returns_empty_when_no_positions(): void
    {
        $obOrder = $this->makeBareOrder([]);
        $obOrder->setRelation('order_position', collect());

        $this->assertSame([], (new ShopaholicOrderValueResolver)->resolveContentIds($obOrder));
    }

    public function test_resolve_num_items_sums_quantity_across_positions(): void
    {
        $obOrder = $this->makeBareOrder([]);

        $obPos1 = new OrderPosition;
        $obPos1->setAttribute('quantity', 2);
        $obPos2 = new OrderPosition;
        $obPos2->setAttribute('quantity', 3);

        $obOrder->setRelation('order_position', collect([$obPos1, $obPos2]));

        $this->assertSame(5, (new ShopaholicOrderValueResolver)->resolveNumItems($obOrder));
    }

    public function test_resolve_content_ids_uses_single_offer_sku_format(): void
    {
        $obOrder = $this->makeBareOrder([]);
        $obOffer = $this->makeOfferForProduct(productId: 42, offerId: 7, offerCount: 1);

        $obPos = new OrderPosition;
        $obPos->setRelation('item', $obOffer);

        $obOrder->setRelation('order_position', collect([$obPos]));

        $this->assertSame(['SKU-42'], (new ShopaholicOrderValueResolver)->resolveContentIds($obOrder));
    }

    public function test_resolve_content_ids_uses_multi_offer_sku_format(): void
    {
        $obOrder = $this->makeBareOrder([]);
        $obOffer = $this->makeOfferForProduct(productId: 42, offerId: 7, offerCount: 3);

        $obPos = new OrderPosition;
        $obPos->setRelation('item', $obOffer);

        $obOrder->setRelation('order_position', collect([$obPos]));

        $this->assertSame(['SKU-42-7'], (new ShopaholicOrderValueResolver)->resolveContentIds($obOrder));
    }

    /**
     * Regression — Gap 1. Orphaned offer (product relation resolves to null
     * because product_id points at a deleted product) MUST NOT raise
     * TypeError when buildContentId calls intAttr(Model, ...). The Pattern 4
     * productOf() helper narrows getRelationValue('product') to ?Product and
     * the null-guard returns the documented 'SKU-0' fallback.
     */
    public function test_resolve_content_ids_handles_orphaned_offer_without_typeerror(): void
    {
        $obOrder = $this->makeBareOrder([]);

        $obOffer = new Offer;
        $obOffer->setAttribute('id', 7);
        $obOffer->setAttribute('product_id', 999);
        // Force the product relation cache to null — mirrors a getRelationValue
        // result for an offer whose product_id has no matching products row.
        $obOffer->setRelation('product', null);

        $obPos = new OrderPosition;
        $obPos->setRelation('item', $obOffer);
        $obOrder->setRelation('order_position', collect([$obPos]));

        $this->assertSame(['SKU-0'], (new ShopaholicOrderValueResolver)->resolveContentIds($obOrder));
    }

    /**
     * @param  array<string, mixed>  $arAttributes
     */
    private function makeBareOrder(array $arAttributes): Order
    {
        $obOrder = new Order;
        $obOrder->setAttribute('id', 1);
        $obOrder->setAttribute('site_id', 1);
        foreach ($arAttributes as $sKey => $mValue) {
            $obOrder->setAttribute($sKey, $mValue);
        }

        return $obOrder;
    }

    private function makeOfferForProduct(int $productId, int $offerId, int $offerCount): Offer
    {
        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => $offerId, 'product_id' => $productId, 'name' => "Offer-{$offerId}"],
        ]);
        // Add (offerCount - 1) sibling offers under the same product so
        // $obProduct->offer->count() returns exactly offerCount.
        $arSiblings = [];
        for ($iSiblingIndex = 1; $iSiblingIndex < $offerCount; $iSiblingIndex++) {
            $arSiblings[] = ['id' => 1000 + $iSiblingIndex, 'product_id' => $productId, 'name' => "Sibling-{$iSiblingIndex}"];
        }
        if ($arSiblings !== []) {
            DB::table('lovata_shopaholic_offers')->insertOrIgnore($arSiblings);
        }
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => $productId, 'name' => "Product-{$productId}", 'slug' => "p-{$productId}"],
        ]);

        $obOffer = Offer::find($offerId);
        if ($obOffer === null) {
            $this->fail('Failed to load Offer fixture');
        }

        return $obOffer;
    }

    private function bootSupportTables(): void
    {
        if (! Schema::hasTable('lovata_orders_shopaholic_order_positions')) {
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
                $obTable->string('name')->nullable();
                $obTable->decimal('percent', 15, 2)->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->integer('sort_order')->default(0);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', function ($obTable): void {
                $obTable->integer('id')->primary();
                $obTable->integer('product_id')->nullable();
                $obTable->string('name')->nullable();
                $obTable->string('code')->nullable();
                $obTable->decimal('price', 15, 2)->nullable();
                $obTable->integer('quantity')->default(0);
                $obTable->boolean('active')->default(true);
                $obTable->integer('sort_order')->default(0);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_products')) {
            Schema::create('lovata_shopaholic_products', function ($obTable): void {
                $obTable->integer('id')->primary();
                $obTable->integer('category_id')->nullable();
                $obTable->integer('brand_id')->nullable();
                $obTable->string('name')->nullable();
                $obTable->string('slug')->nullable();
                $obTable->string('code')->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_currencies')) {
            Schema::create('lovata_shopaholic_currencies', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('code');
                $obTable->string('symbol')->nullable();
                $obTable->integer('sort_order')->default(0);
                $obTable->timestamps();
            });
        }
    }
}
