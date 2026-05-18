<?php

use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

/**
 * Phase 3 D-07 — EventLogWriter::record persists the trailing $arPayload arg
 * to the new payload longText column (Phase 3 D-06). Null when empty array;
 * JSON-encoded otherwise (UNESCAPED_SLASHES + UNESCAPED_UNICODE).
 */
final class EventLogWriterPayloadColumnTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_record_persists_payload_json_encoded_when_non_empty(): void
    {
        $arPayload = ['data' => [['event_name' => 'Purchase', 'event_id' => 'x']]];

        $bWon = EventLogWriter::record('x', 'Purchase', 'capi', new TestSubject, null, 1700000000, 1, $arPayload);
        $this->assertTrue($bWon);

        $sPayload = DB::table(self::TABLE)->where('event_id', 'x')->value('payload');
        $this->assertSame(
            '{"data":[{"event_name":"Purchase","event_id":"x"}]}',
            $sPayload,
        );
    }

    public function test_record_persists_null_payload_when_empty_array(): void
    {
        $bWon = EventLogWriter::record('y', 'Purchase', 'capi', new TestSubject, null, 1700000001, 2, []);
        $this->assertTrue($bWon);

        $sPayload = DB::table(self::TABLE)->where('event_id', 'y')->value('payload');
        $this->assertNull($sPayload);
    }

    public function test_record_returns_true_when_payload_persisted(): void
    {
        $arPayload = ['data' => [['event_name' => 'AddToCart']]];
        $bWon = EventLogWriter::record('z', 'AddToCart', 'capi', new TestSubject, null, 1700000002, 3, $arPayload);
        $this->assertTrue($bWon, 'race-fence won and payload persisted');
        $this->assertSame(1, DB::table(self::TABLE)->where('event_id', 'z')->count());
    }
}
