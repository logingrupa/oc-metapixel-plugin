<?php

namespace Logingrupa\Metapixel\Tests;

use Illuminate\Support\Facades\Schema;

/**
 * Test case for Logingrupa.Metapixel ShopaholicAdapter integration.
 *
 * Provisions a minimal hermetic Lovata Orders schema in SQLite-in-memory so
 * adapter tests do not need the real Lovata.OrdersShopaholic migration chain
 * (slow + SQLite-unfriendly for some indexed column drops).
 *
 * Tests under tests/Unit/Adapter/Shopaholic and tests/Feature/Adapter/Shopaholic
 * extend this class via the Pest.php uses() binding. Run B (minimal install)
 * does NOT execute the Adapter subdirectory testsuite.
 */
abstract class ShopaholicAdapterTestCase extends MetapixelTestCase
{
    /**
     * Provision the minimal lovata_orders_shopaholic_orders table — only the
     * columns ShopaholicAdapter touches. Mirrors the Lovata v1.33 schema for
     * status_id, secret_key, total_price_value, currency_id, customer fields,
     * site_id.
     */
    protected function bootOrdersTable(): void
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

    /**
     * Provision lovata_orders_shopaholic_statuses with the canonical Lovata
     * statuses plus the custom new-payment-received code (status_id=5) that
     * Settings.paid_status_code defaults to.
     */
    protected function bootOrdersStatuses(): void
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

        \DB::table('lovata_orders_shopaholic_statuses')->insertOrIgnore([
            ['id' => 1, 'name' => 'New',                  'code' => 'new',                  'sort_order' => 1, 'is_user_show' => 1],
            ['id' => 2, 'name' => 'In progress',          'code' => 'in_progress',          'sort_order' => 2, 'is_user_show' => 1],
            ['id' => 3, 'name' => 'Complete',             'code' => 'complete',             'sort_order' => 3, 'is_user_show' => 1],
            ['id' => 4, 'name' => 'Canceled',             'code' => 'canceled',             'sort_order' => 4, 'is_user_show' => 1],
            ['id' => 5, 'name' => 'New payment received', 'code' => 'new-payment-received', 'sort_order' => 5, 'is_user_show' => 1],
        ]);
    }

    protected function dropHermeticSchemas(): void
    {
        Schema::dropIfExists('lovata_orders_shopaholic_orders');
        Schema::dropIfExists('lovata_orders_shopaholic_statuses');

        parent::dropHermeticSchemas();
    }
}
