<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\EventLog;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Database\Builder;

final class EventLogModelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_fillable_matches_migration_columns(): void
    {
        $arExpected = [
            'channel',
            'event_id',
            'event_name',
            'event_time',
            'fired_at',
            'payload',
            'secret_key',
            'site_id',
            'subject_id',
            'subject_type',
        ];

        $arActual = (new EventLog)->getFillable();
        sort($arActual);

        $this->assertSame($arExpected, $arActual);
    }

    public function test_channel_constants_are_capi_and_pixel(): void
    {
        $this->assertSame('capi', EventLog::CHANNEL_CAPI);
        $this->assertSame('pixel', EventLog::CHANNEL_PIXEL);
    }

    public function test_event_log_has_no_morph_to_subject_relation(): void
    {
        $this->assertFalse(
            method_exists(EventLog::class, 'subject'),
            'EventLog MUST NOT expose a subject() MorphTo — subject_type is an opaque alias, not a class FQN (P-05).'
        );
    }

    public function test_scope_for_subject_returns_query_builder(): void
    {
        $obQuery = (new EventLog)->newQuery()->forSubject('shopaholic.order', 42);

        $this->assertInstanceOf(Builder::class, $obQuery);
    }

    public function test_casts_subject_id_site_id_event_time_to_int(): void
    {
        $obLog = new EventLog;
        $obLog->subject_id = '42';
        $obLog->site_id = '7';
        $obLog->event_time = '1700000000';

        $this->assertSame(42, $obLog->subject_id);
        $this->assertSame(7, $obLog->site_id);
        $this->assertSame(1700000000, $obLog->event_time);
    }
}
