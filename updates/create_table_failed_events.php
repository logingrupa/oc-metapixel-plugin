<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateTableFailedEvents
 *
 * PAY-05 + WR-07 — dead-letter sink for permanently-failed Meta CAPI events.
 * Written by SendCapiEvent::handle() via FailedEvent::createFromPayloadAndException
 * when MetaApiPermanentException terminates retry chain. Unique idx on
 * (event_id, http_status) defends double-write across retry catch + Laravel
 * failed() exhaustion hook.
 *
 * Schema:
 *   id           UNSIGNED INT AUTOINCREMENT
 *   event_id     VARCHAR(36) INDEX                 UUIDv4 admin replay key
 *   event_name   VARCHAR(64) INDEX                 Purchase / ViewContent / ...
 *   payload      LONGTEXT                          raw JSON envelope
 *   graph_error  TEXT NULL                         Meta Graph API error msg
 *   http_status  SMALLINT UNSIGNED NULL INDEX      4xx/5xx classification
 *   attempts     UNSIGNED INT DEFAULT 0            queue-job retry counter
 *   created_at   TIMESTAMP
 *   updated_at   TIMESTAMP
 *   UNIQUE (event_id, http_status)                 WR-07 idempotency
 *
 * Idempotent up() + reversible down(). Phase 5 HARD-01 ships backend list
 * controller against this table.
 *
 * @see plugins/logingrupa/backinstockshopaholic/updates/create_table_offersubscribers.php — analog
 */
class CreateTableFailedEvents extends Migration
{
    const TABLE = 'logingrupa_metapixel_failed_events';
    const UNIQUE_INDEX = 'metapixel_failed_events_event_status_unique';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $obTable): void {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->string('event_id', 36)->index();
            $obTable->string('event_name', 64)->index();
            $obTable->longText('payload');
            $obTable->text('graph_error')->nullable();
            $obTable->smallInteger('http_status')->unsigned()->nullable()->index();
            $obTable->unsignedInteger('attempts')->default(0);
            $obTable->timestamps();

            // WR-07: defense-in-depth uniqueness — retry catch + failed() hook
            // cannot double-write same logical permanent failure.
            $obTable->unique(['event_id', 'http_status'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
