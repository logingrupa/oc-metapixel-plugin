<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-02 (carry-forward) — ShopaholicCartPositionValueResolver unit tests.
 * Covers SKU content_id format (single + multi-offer branches), MorphTo
 * $item null-guard (Pitfall 1), quantity × price_value resolveValue,
 * currency Settings.default_currency_code fallback, and the exception
 * path when the chain exhausts.
 *
 * Subjects + offers + products are built in-memory via setAttribute +
 * setRelation. Persisting + Offer::find() drags Lovata's pure-accessor
 * cascade (PriceHelper -> CurrencyHelper -> lovata_shopaholic_currency)
 * into the test — irrelevant to the resolver contract.
 */
#[Group('adapter')]
final class ShopaholicCartPositionValueResolverTest extends ShopaholicAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCartPositionTable();
        $this->bootCartTable();
        $this->bootOffersAndProductsTables();
        $this->bootCurrencyTable();
        Settings::clearInternalCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_currency');
        parent::tearDown();
    }

    private function bootCurrencyTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_currency')) {
            return;
        }
        Schema::create('lovata_shopaholic_currency', function ($obTable): void {
            $obTable->increments('id');
            $obTable->boolean('active')->default(false);
            $obTable->boolean('is_default')->default(false);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('symbol')->nullable();
            $obTable->decimal('rate', 15, 4)->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
        DB::table('lovata_shopaholic_currency')->insertOrIgnore([
            ['id' => 1, 'active' => 1, 'is_default' => 1, 'code' => 'EUR', 'symbol' => '€', 'rate' => 1, 'sort_order' => 1, 'name' => 'Euro'],
        ]);
    }

    public function test_resolve_content_ids_returns_sku_single_offer_form_when_one_offer_per_product(): void
    {
        $obOffer = $this->makeOfferWithProduct(iProductId: 42, iOfferId: 7, iSiblingOffers: 0);
        $obPosition = $this->makeBarePositionForOffer($obOffer);

        $this->assertSame(['SKU-42'], (new ShopaholicCartPositionValueResolver)->resolveContentIds($obPosition));
    }

    public function test_resolve_content_ids_returns_sku_multi_offer_form_when_multiple_offers_per_product(): void
    {
        $obOffer = $this->makeOfferWithProduct(iProductId: 42, iOfferId: 7, iSiblingOffers: 2);
        $obPosition = $this->makeBarePositionForOffer($obOffer);

        $this->assertSame(['SKU-42-7'], (new ShopaholicCartPositionValueResolver)->resolveContentIds($obPosition));
    }

    public function test_resolve_content_ids_returns_empty_when_item_morphto_is_null(): void
    {
        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setRelation('item', null);

        $this->assertSame([], (new ShopaholicCartPositionValueResolver)->resolveContentIds($obPosition));
    }

    public function test_resolve_value_returns_zero_when_item_morphto_is_null(): void
    {
        // Null-guard: when MorphTo $item is null (Pitfall 1), resolveValue
        // returns 0.0 regardless of quantity — no offer to multiply against.
        // The quantity-times-price multiplication path is exercised by the
        // contract-test invariant 10 (PayloadBuilder runs resolveValue on a
        // real persisted CartPosition). Asserting an exact arithmetic value
        // in unit context requires the full Lovata price/currency stack —
        // see Plan 03-02 deviation #5 for the analogous Order resolveValue
        // test where the same Lovata pure-accessor cascade made an exact
        // arithmetic assertion non-hermetic.
        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setAttribute('quantity', 5);
        $obPosition->setRelation('item', null);

        $this->assertSame(0.0, (new ShopaholicCartPositionValueResolver)->resolveValue($obPosition));
    }

    public function test_resolve_num_items_returns_quantity(): void
    {
        $obOffer = $this->makeOfferWithProduct(iProductId: 1, iOfferId: 1, iSiblingOffers: 0);
        $obPosition = $this->makeBarePositionForOffer($obOffer, iQuantity: 7);

        $this->assertSame(7, (new ShopaholicCartPositionValueResolver)->resolveNumItems($obPosition));
    }

    public function test_resolve_currency_falls_back_to_settings_default_when_set(): void
    {
        Settings::set(['default_currency_code' => 'NOK']);
        $obPosition = new CartPosition;

        $this->assertSame('NOK', (new ShopaholicCartPositionValueResolver)->resolveCurrency($obPosition));
    }

    public function test_resolve_currency_throws_when_no_settings_default(): void
    {
        Settings::set(['default_currency_code' => '']);
        $obPosition = new CartPosition;

        $this->expectException(OrderHasNoCurrencyException::class);
        (new ShopaholicCartPositionValueResolver)->resolveCurrency($obPosition);
    }

    private function makeOfferWithProduct(int $iProductId, int $iOfferId, int $iSiblingOffers): Offer
    {
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => $iProductId, 'name' => "Product-{$iProductId}", 'slug' => "p-{$iProductId}"],
        ]);
        // Seed the offer table with the offer + its siblings so the Product->offer
        // HasMany count() resolves the multi-offer branch reading siblings via DB.
        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => $iOfferId, 'product_id' => $iProductId, 'name' => "Offer-{$iOfferId}"],
        ]);
        for ($iIdx = 1; $iIdx <= $iSiblingOffers; $iIdx++) {
            DB::table('lovata_shopaholic_offers')->insertOrIgnore([
                ['id' => 1000 + $iIdx, 'product_id' => $iProductId, 'name' => "Sibling-{$iIdx}"],
            ]);
        }

        $obProduct = new Product;
        $obProduct->setAttribute('id', $iProductId);
        $obProduct->setAttribute('name', "Product-{$iProductId}");
        $obProduct->exists = true;

        $obOffer = new Offer;
        $obOffer->setAttribute('id', $iOfferId);
        $obOffer->setAttribute('product_id', $iProductId);
        $obOffer->setRelation('product', $obProduct);

        return $obOffer;
    }

    private function makeBarePositionForOffer(Offer $obOffer, int $iQuantity = 1): CartPosition
    {
        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setAttribute('quantity', $iQuantity);
        $obPosition->setRelation('item', $obOffer);

        return $obPosition;
    }
}
