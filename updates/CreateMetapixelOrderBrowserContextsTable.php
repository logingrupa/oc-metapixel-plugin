<?php

namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Buyer browser context captured when an order is created, read when its
 * Purchase fires later from a request that is not the buyer's browser.
 */
class CreateMetapixelOrderBrowserContextsTable extends Migration
{
    public const TABLE = 'logingrupa_metapixel_order_browser_contexts';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->engine = 'InnoDB';
            $obTable->bigIncrements('id');
            $obTable->unsignedInteger('order_id');
            $obTable->string('client_ip_address', 45)->nullable();
            $obTable->text('client_user_agent')->nullable();
            $obTable->string('fbp', 64)->nullable();
            $obTable->string('fbc', 255)->nullable();
            $obTable->text('event_source_url')->nullable();
            $obTable->timestamps();

            $obTable->unique('order_id', 'metapixel_order_browser_contexts_order_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
