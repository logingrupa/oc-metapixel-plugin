<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixel\Components\EventPixel;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-06 onMarkFired AJAX: validates the supplied event_id against the CAPI
 * row (un-injectable), writes the channel='pixel' twin row via insertOrIgnore
 * for the UNIQUE race-fence on reload. T-03-08-01 + T-03-08-03 mitigation.
 */
#[Group('adapter')]
final class EventPixelMarkFiredTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }

    public function test_onMarkFired_writes_pixel_row_when_event_id_matches_capi_row(): void
    {
        $this->seedCapiRow(42, 'match-uuid');
        $this->mockInput([
            'subject_type' => 'shopaholic.order',
            'subject_id' => 42,
            'event_name' => 'Purchase',
            'event_id' => 'match-uuid',
        ]);

        $arResult = (new EventPixel)->onMarkFired();

        $this->assertSame(['ok' => true], $arResult);
        $this->assertSame(1, DB::table(self::TABLE)
            ->where('channel', 'pixel')->where('event_id', 'match-uuid')->count());
    }

    public function test_onMarkFired_rejects_request_when_event_id_does_not_match_capi_row(): void
    {
        $this->seedCapiRow(10, 'server-event-id');
        $this->mockInput([
            'subject_type' => 'shopaholic.order',
            'subject_id' => 10,
            'event_name' => 'Purchase',
            'event_id' => 'attacker-injected-uuid',
        ]);

        $arResult = (new EventPixel)->onMarkFired();

        $this->assertSame(['ok' => false, 'error' => 'event_id mismatch'], $arResult);
        $this->assertSame(0, DB::table(self::TABLE)->where('channel', 'pixel')->count());
    }

    public function test_onMarkFired_rejects_request_with_invalid_params(): void
    {
        $this->mockInput([
            'subject_type' => '',
            'subject_id' => 1,
            'event_name' => 'Purchase',
            'event_id' => 'whatever',
        ]);

        $arResult = (new EventPixel)->onMarkFired();

        $this->assertSame(['ok' => false, 'error' => 'invalid params'], $arResult);
    }

    public function test_onMarkFired_rejects_when_no_capi_row_exists(): void
    {
        $this->mockInput([
            'subject_type' => 'shopaholic.order',
            'subject_id' => 9999,
            'event_name' => 'Purchase',
            'event_id' => 'no-such-uuid',
        ]);

        $arResult = (new EventPixel)->onMarkFired();

        $this->assertSame(['ok' => false, 'error' => 'no capi row'], $arResult);
    }

    public function test_onMarkFired_race_fence_blocks_second_insert_on_reload(): void
    {
        $this->seedCapiRow(77, 'race-uuid');
        $this->mockInput([
            'subject_type' => 'shopaholic.order',
            'subject_id' => 77,
            'event_name' => 'Purchase',
            'event_id' => 'race-uuid',
        ]);

        $obComponent = new EventPixel;
        $this->assertSame(['ok' => true], $obComponent->onMarkFired());
        $this->assertSame(['ok' => true], $obComponent->onMarkFired());

        $this->assertSame(1, DB::table(self::TABLE)
            ->where('channel', 'pixel')->where('subject_id', 77)->count(),
            'UNIQUE race-fence on (subject_type, subject_id, event_name, channel, site_id) blocked the second insert');
    }

    /**
     * @param  array<string, mixed>  $arInputs
     */
    private function mockInput(array $arInputs): void
    {
        Request::replace($arInputs);
    }

    private function seedCapiRow(int $iSubjectId, string $sEventId): void
    {
        $sNow = (string) Carbon::now();
        DB::table(self::TABLE)->insert([
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'channel' => 'capi',
            'subject_type' => 'shopaholic.order',
            'subject_id' => $iSubjectId,
            'secret_key' => 'sk-'.$iSubjectId,
            'site_id' => 1,
            'event_time' => 1700000000,
            'fired_at' => $sNow,
            'created_at' => $sNow,
            'updated_at' => $sNow,
            'payload' => '{"data":[{"custom_data":{"value":1}}]}',
        ]);
    }
}
