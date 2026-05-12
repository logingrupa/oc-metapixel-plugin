<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateTableFailedEvents
 *
 * PAY-05 — creates `logingrupa_metapixel_failed_events`, the dead-letter sink
 * for permanently-failed Meta CAPI events. Written by SendCapiEvent::handle()
 * (plan 03-05) via `FailedEvent::createFromPayloadAndException` when a
 * MetaApiPermanentException terminates the retry chain.
 *
 * Schema (CONTEXT Area 4 Q1 + Q2):
 *   - id            UNSIGNED INT AUTOINCREMENT       (framework PK)
 *   - event_id      VARCHAR(36) INDEX                (UUIDv4 — admin replay key)
 *   - event_name    VARCHAR(64) INDEX                (Purchase, ViewContent, ...)
 *   - payload       LONGTEXT                          (raw JSON envelope sent to Meta)
 *   - graph_error   TEXT NULL                         (Meta Graph API error message)
 *   - http_status   SMALLINT UNSIGNED NULL INDEX     (4xx/5xx classification)
 *   - attempts      UNSIGNED INT DEFAULT 0           (queue-job retry counter)
 *   - created_at    TIMESTAMP                         (framework)
 *   - updated_at    TIMESTAMP                         (framework)
 *
 * Phase 5 HARD-01 ships the backend list controller against this table.
 *
 * Reversible `down()` drops the table. Idempotent — `up()` no-ops if the table
 * already exists.
 *
 * @see plugins/logingrupa/backinstockshopaholic/updates/create_table_offersubscribers.php — analog
 */
class CreateTableFailedEvents extends Migration
{
    const TABLE = 'logingrupa_metapixel_failed_events';

    /**
     * Apply migration — create the failed_events table.
     */
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
        });
    }

    /**
     * Rollback migration — drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
