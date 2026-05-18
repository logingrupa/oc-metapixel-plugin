<?php

namespace Logingrupa\Metapixel\Tests\Fixtures\Migrations;

use Illuminate\Support\Facades\Schema;

/**
 * Hermetic migration helper for the `test_subjects` table. Used by
 * EventPixelTest setUp/tearDown — provides a stable Eloquent target so
 * EventPixel's subject_class lookup has a real DB row to resolve.
 */
final class CreateTestSubjectsTable
{
    public static function up(): void
    {
        if (Schema::hasTable('test_subjects')) {
            return;
        }
        Schema::create('test_subjects', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('secret_key')->nullable();
            $obTable->timestamps();
        });
    }

    public static function down(): void
    {
        Schema::dropIfExists('test_subjects');
    }
}
