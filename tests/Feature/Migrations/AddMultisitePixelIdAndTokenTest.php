<?php

use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddMultisitePixelIdAndToken;

/**
 * Wave 0 RED — fails until plan 04-01 ships.
 *
 * MULT-06 / D-03: schema-additive only. Migration is a no-op guarded by
 * Schema::hasTable('system_settings'); down() does nothing. Existence is for
 * marketplace install-log traceability.
 */
final class AddMultisitePixelIdAndTokenTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_up_is_idempotent_when_system_settings_table_present(): void
    {
        $this->assertTrue(Schema::hasTable('system_settings'), 'fixture precondition');

        (new AddMultisitePixelIdAndToken)->up();
        (new AddMultisitePixelIdAndToken)->up();

        $this->assertTrue(Schema::hasTable('system_settings'), 'up() MUST NOT drop the table.');
    }

    public function test_up_is_idempotent_when_system_settings_table_absent(): void
    {
        Schema::dropIfExists('system_settings');

        $this->assertFalse(Schema::hasTable('system_settings'));

        (new AddMultisitePixelIdAndToken)->up();

        $this->assertFalse(Schema::hasTable('system_settings'), 'up() MUST NOT create or assume the table.');
    }

    public function test_down_is_noop(): void
    {
        $iBeforeColumnCount = count(Schema::getColumnListing('system_settings'));

        (new AddMultisitePixelIdAndToken)->down();

        $iAfterColumnCount = count(Schema::getColumnListing('system_settings'));

        $this->assertSame($iBeforeColumnCount, $iAfterColumnCount, 'down() MUST NOT mutate schema.');
    }
}
