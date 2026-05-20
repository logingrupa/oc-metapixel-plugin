<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class FailedEventModelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_fillable_matches_migration_columns(): void
    {
        $arExpected = [
            'adapter_type',
            'attempts',
            'dedup_checked_at',
            'dedup_pct',
            'emq',
            'event_id',
            'event_name',
            'graph_error',
            'http_status',
            'payload',
            'subject_id',
            'subject_type',
        ];

        $arActual = (new FailedEvent)->getFillable();
        sort($arActual);

        $this->assertSame($arExpected, $arActual);
    }

    public function test_payload_round_trips_as_array(): void
    {
        $obFailed = new FailedEvent;
        $obFailed->payload = ['data' => [['event_name' => 'Purchase']]];

        $this->assertContains('payload', $obFailed->getJsonable());
        $this->assertIsArray($obFailed->payload);
        $this->assertSame(['data' => [['event_name' => 'Purchase']]], $obFailed->payload);
    }

    public function test_attempts_and_http_status_cast_to_int(): void
    {
        $obFailed = new FailedEvent;
        $obFailed->attempts = '3';
        $obFailed->http_status = '400';

        $this->assertSame(3, $obFailed->attempts);
        $this->assertSame(400, $obFailed->http_status);
    }

    public function test_fillable_includes_subject_type_and_subject_id_for_h2(): void
    {
        $arFillable = (new FailedEvent)->getFillable();

        $this->assertContains('subject_type', $arFillable);
        $this->assertContains('subject_id', $arFillable);
    }
}
