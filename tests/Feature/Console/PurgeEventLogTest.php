<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Console\PurgeEventLog;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Logingrupa\Metapixel\Updates\ReplaceDedupColumnsWithReplayedAt;

/**
 * metapixel:purge-event-log deletes EventLog and FailedEvent rows older
 * than 7 days, preserves newer rows. Carbon::setTestNow pins the cutoff.
 */
final class PurgeEventLogTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    private const FAILED_TABLE = 'logingrupa_metapixel_failed_events';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        (new ReplaceDedupColumnsWithReplayedAt)->up();
        // Register the console command directly into Laravel's Artisan kernel for the
        // test container — MetapixelTestCase keeps autoRegister=false to stay light, so
        // we cannot rely on Plugin::register() wiring the command via October's
        // registerConsoleCommand path.
        $obKernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $obKernel->registerCommand($this->app->make(PurgeEventLog::class));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        (new ReplaceDedupColumnsWithReplayedAt)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_purge_deletes_failed_events_older_than_seven_days_keeps_newer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        DB::table(self::FAILED_TABLE)->insert($this->makeFailedRow('uuid-old', '2026-05-12 11:59:59'));
        DB::table(self::FAILED_TABLE)->insert($this->makeFailedRow('uuid-new', '2026-05-13 12:00:01'));

        $iExit = Artisan::call('metapixel:purge-event-log');
        $this->assertSame(0, $iExit);

        $this->assertSame(1, DB::table(self::FAILED_TABLE)->count());
        $this->assertSame('uuid-new', DB::table(self::FAILED_TABLE)->first()->event_id);
    }

    /** @return array<string, mixed> */
    private function makeFailedRow(string $sEventId, string $sCreatedAt): array
    {
        return [
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'adapter_type' => 'fake',
            'payload' => '{"data":[]}',
            'graph_error' => 'boom',
            'attempts' => 1,
            'created_at' => $sCreatedAt,
            'updated_at' => $sCreatedAt,
        ];
    }

    public function test_purge_deletes_rows_older_than_seven_days_keeps_newer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        // > 7 days old — must be deleted.
        DB::table(self::TABLE)->insert($this->makeRow('uuid-old', 'old-subject', 1, '2026-05-12 11:59:59'));
        // < 7 days old — must survive.
        DB::table(self::TABLE)->insert($this->makeRow('uuid-new', 'new-subject', 2, '2026-05-13 12:00:01'));

        $iExit = Artisan::call('metapixel:purge-event-log');
        $this->assertSame(0, $iExit);

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $obSurvivor = DB::table(self::TABLE)->first();
        $this->assertSame('uuid-new', $obSurvivor->event_id);
    }

    public function test_purge_returns_zero_when_no_rows_exist(): void
    {
        $this->assertSame(0, DB::table(self::TABLE)->count());

        $iExit = Artisan::call('metapixel:purge-event-log');
        $this->assertSame(0, $iExit);
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    /** @return array<string, mixed> */
    private function makeRow(string $sEventId, string $sSubjectType, int $iSubjectId, string $sCreatedAt): array
    {
        return [
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'channel' => 'capi',
            'subject_type' => $sSubjectType,
            'subject_id' => $iSubjectId,
            'secret_key' => null,
            'site_id' => null,
            'event_time' => 1700000000,
            'payload' => null,
            'fired_at' => $sCreatedAt,
            'created_at' => $sCreatedAt,
            'updated_at' => $sCreatedAt,
        ];
    }
}
