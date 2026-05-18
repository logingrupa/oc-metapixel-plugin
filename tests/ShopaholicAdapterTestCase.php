<?php

namespace Logingrupa\Metapixel\Tests;

use Illuminate\Support\Facades\Schema;

/**
 * Hermetic Lovata Orders / Cart / Catalog schema for adapter tests. Loads
 * minimal columns only — avoids the real plugin migration chain. Run B
 * (minimal install) excludes adapter tests via #[Group('adapter')].
 */
abstract class ShopaholicAdapterTestCase extends MetapixelTestCase
{
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

    protected function bootCartTable(): void
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

    protected function bootCartPositionTable(): void
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

    protected function bootOffersAndProductsTables(): void
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

    protected function dropHermeticSchemas(): void
    {
        Schema::dropIfExists('lovata_orders_shopaholic_orders');
        Schema::dropIfExists('lovata_orders_shopaholic_statuses');
        Schema::dropIfExists('lovata_orders_shopaholic_cart_positions');
        Schema::dropIfExists('lovata_orders_shopaholic_carts');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_products');

        parent::dropHermeticSchemas();
    }
}
