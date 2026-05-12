<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Support;

use DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Real-DB fixture factory for Phase 3 PayloadBuilder + UserDataHasher unit tests.
 *
 * Hermetic-SQLite pattern: provisions just enough schema (offers, products,
 * order_positions) that the production `Order` + `OrderPosition` accessors
 * + `PayloadBuilder::buildSkuId`'s `Offer::where(...)->count()` query can
 * execute against an in-memory database. NO `declare(strict_types=1);` —
 * test-helper convention matches RetryPaymentShopaholic's FakePaymentGateway.
 *
 * IMPORTANT: Lovata.OrdersShopaholic's `Order` model has a deliberately
 * narrow `$fillable` (mass-assignment allowlist excludes `secret_key`,
 * `order_number`, `email`, `phone`, `name`, `last_name` — these are set
 * inside `OrderProcessor` on real-world create). To populate them in
 * fixtures we use `forceFill` (bypasses mass-assignment) and `save`.
 *
 * Hermetic schemas dropped by `MetapixelTestCase::dropHermeticSchemas`
 * via the new entries we add in this plan's follow-up. Each test that
 * uses these fixtures should call `OrderFixtures::provisionHermeticOfferProductTables()`
 * in its own `setUp()`.
 *
 * @see plugins/logingrupa/metapixelshopaholic/classes/meta/PayloadBuilder.php (Task 3)
 * @see plugins/logingrupa/metapixelshopaholic/classes/meta/UserDataHasher.php (Task 2)
 */
final class OrderFixtures
{
    public const int SINGLE_OFFER_PRODUCT_ID = 10;

    public const int SINGLE_OFFER_ID = 101;

    public const int MULTI_OFFER_PRODUCT_ID = 11;

    public const int MULTI_OFFER_ID = 102;

    public const int MULTI_OFFER_SECOND_ID = 103;

    public const string EXPECTED_SINGLE_SKU = 'SKU-10';

    public const string EXPECTED_MULTI_SKU = 'SKU-11-102';

    /**
     * Provision the minimal `lovata_shopaholic_offers`, `lovata_shopaholic_products`,
     * and `lovata_orders_shopaholic_order_positions` tables on the SQLite-in-memory
     * connection. Idempotent — safe to call multiple times per test.
     */
    public static function provisionHermeticOfferProductTables(): void
    {
        if (! Schema::hasTable('lovata_shopaholic_products')) {
            Schema::create('lovata_shopaholic_products', function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->unsignedInteger('product_id')->index();
                $obTable->string('name')->nullable();
                $obTable->decimal('price', 15, 2)->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_orders_shopaholic_order_positions')) {
            Schema::create('lovata_orders_shopaholic_order_positions', function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->unsignedInteger('order_id')->index();
                $obTable->unsignedInteger('item_id')->nullable();
                $obTable->string('item_type')->nullable();
                $obTable->unsignedInteger('offer_id')->index();
                $obTable->unsignedInteger('product_id')->nullable();
                $obTable->unsignedInteger('quantity')->default(1);
                $obTable->decimal('price_value', 15, 2)->default(0);
                $obTable->string('currency_code', 3)->nullable();
                $obTable->timestamps();
            });
        }
    }

    /**
     * Drop hermetic offer/product/order-position tables in tearDown(). Safe to
     * call when the tables were never created. MetapixelTestCase will reference
     * this from `dropHermeticSchemas()` once subsequent plans add the call.
     */
    public static function dropHermeticOfferProductTables(): void
    {
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_products');
    }

    /**
     * Build a persisted paid Order with two positions:
     *  - position 1: product 10 (single-offer) — `SKU-10` content_id
     *  - position 2: product 11 (multi-offer)  — `SKU-11-102` content_id
     *
     * `secret_key`, `order_number`, `email`, `phone`, `name`, `last_name` are
     * populated via `forceFill` because Lovata.OrdersShopaholic's `Order`
     * `$fillable` excludes them — they are written by `OrderProcessor` in
     * real-world flows.
     *
     * @return Order  freshly-fetched row so `order_position` HasMany is lazy-loadable
     */
    public static function makePaidOrder(): Order
    {
        self::provisionHermeticOfferProductTables();
        self::seedOfferProductCatalog();

        $obOrder = new Order();
        $obOrder->forceFill([
            'status_id' => 5,
            'order_number' => '260512-9001',
            'secret_key' => 'test-secret-aaaaaaaaa',
            'currency_id' => 1,
            'email' => 'guest@example.com',
            'phone' => '+371 20 000 000',
            'name' => 'Test',
            'last_name' => 'User',
            'total_price_value' => 49.95,
        ]);
        $obOrder->save();

        DB::table('lovata_orders_shopaholic_order_positions')->insert([
            [
                'order_id' => $obOrder->id,
                'offer_id' => self::SINGLE_OFFER_ID,
                'product_id' => self::SINGLE_OFFER_PRODUCT_ID,
                'quantity' => 2,
                'price_value' => 19.95,
                'currency_code' => 'EUR',
            ],
            [
                'order_id' => $obOrder->id,
                'offer_id' => self::MULTI_OFFER_ID,
                'product_id' => self::MULTI_OFFER_PRODUCT_ID,
                'quantity' => 1,
                'price_value' => 10.05,
                'currency_code' => 'EUR',
            ],
        ]);

        return $obOrder->fresh();
    }

    /**
     * Variant where every position points to product 11 — exhaustively
     * exercises the `SKU-{product_id}-{offer_id}` multi-offer branch.
     */
    public static function makeMultiOfferOrder(): Order
    {
        self::provisionHermeticOfferProductTables();
        self::seedOfferProductCatalog();

        $obOrder = new Order();
        $obOrder->forceFill([
            'status_id' => 5,
            'order_number' => '260512-9002',
            'secret_key' => 'test-secret-bbbbbbbbb',
            'currency_id' => 1,
            'email' => 'multi@example.com',
            'phone' => '+371 20 000 001',
            'name' => 'Multi',
            'last_name' => 'Offer',
            'total_price_value' => 30.00,
        ]);
        $obOrder->save();

        DB::table('lovata_orders_shopaholic_order_positions')->insert([
            [
                'order_id' => $obOrder->id,
                'offer_id' => self::MULTI_OFFER_ID,
                'product_id' => self::MULTI_OFFER_PRODUCT_ID,
                'quantity' => 1,
                'price_value' => 10.00,
                'currency_code' => 'EUR',
            ],
            [
                'order_id' => $obOrder->id,
                'offer_id' => self::MULTI_OFFER_SECOND_ID,
                'product_id' => self::MULTI_OFFER_PRODUCT_ID,
                'quantity' => 2,
                'price_value' => 10.00,
                'currency_code' => 'EUR',
            ],
        ]);

        return $obOrder->fresh();
    }

    /**
     * Variant with email = null — locks the UserDataHasher branch where
     * `em` is omitted and `external_id` is derived from `secret_key` alone.
     */
    public static function makeGuestOrderWithoutEmail(): Order
    {
        self::provisionHermeticOfferProductTables();
        self::seedOfferProductCatalog();

        $obOrder = new Order();
        $obOrder->forceFill([
            'status_id' => 5,
            'order_number' => '260512-9003',
            'secret_key' => 'test-secret-ccccccccc',
            'currency_id' => 1,
            'email' => null,
            'phone' => null,
            'name' => null,
            'last_name' => null,
            'total_price_value' => 19.95,
        ]);
        $obOrder->save();

        DB::table('lovata_orders_shopaholic_order_positions')->insert([
            [
                'order_id' => $obOrder->id,
                'offer_id' => self::SINGLE_OFFER_ID,
                'product_id' => self::SINGLE_OFFER_PRODUCT_ID,
                'quantity' => 1,
                'price_value' => 19.95,
                'currency_code' => 'EUR',
            ],
        ]);

        return $obOrder->fresh();
    }

    /**
     * Seed deterministic offer/product catalog so the `Offer::where('product_id', ...)->count()`
     * lookup in PayloadBuilder::buildSkuId returns 1 for product 10 (single-offer
     * → `SKU-10`) and 2 for product 11 (multi-offer → `SKU-11-{offer_id}`).
     */
    private static function seedOfferProductCatalog(): void
    {
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => self::SINGLE_OFFER_PRODUCT_ID, 'name' => 'Single-offer product'],
            ['id' => self::MULTI_OFFER_PRODUCT_ID, 'name' => 'Multi-offer product'],
        ]);

        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => self::SINGLE_OFFER_ID, 'product_id' => self::SINGLE_OFFER_PRODUCT_ID, 'name' => 'Single offer'],
            ['id' => self::MULTI_OFFER_ID, 'product_id' => self::MULTI_OFFER_PRODUCT_ID, 'name' => 'Multi offer 1'],
            ['id' => self::MULTI_OFFER_SECOND_ID, 'product_id' => self::MULTI_OFFER_PRODUCT_ID, 'name' => 'Multi offer 2'],
        ]);
    }
}
