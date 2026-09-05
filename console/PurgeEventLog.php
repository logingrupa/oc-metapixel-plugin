<?php

namespace Logingrupa\Metapixel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\FailedEvent;

/**
 * Deletes EventLog and FailedEvent rows older than the retention window.
 * Wired daily via Plugin::registerSchedule.
 */
final class PurgeEventLog extends Command
{
    /** @var string */
    protected $signature = 'metapixel:purge-event-log';

    /** @var string */
    protected $description = 'Delete EventLog and FailedEvent rows older than 7 days';

    public function handle(): int
    {
        $sCutoff = (string) Carbon::now()->subDays(FailedEvent::RETENTION_DAYS);
        $iDeletedLog = DB::table('logingrupa_metapixel_event_log')
            ->where('created_at', '<', $sCutoff)
            ->delete();
        $iDeletedFailed = DB::table('logingrupa_metapixel_failed_events')
            ->where('created_at', '<', $sCutoff)
            ->delete();

        Log::info('metapixel: purge-event-log', [
            'meta_pixel.rows_deleted' => $iDeletedLog,
            'meta_pixel.failed_rows_deleted' => $iDeletedFailed,
            'meta_pixel.cutoff' => $sCutoff,
        ]);

        $this->info(sprintf(
            'Purged %d EventLog rows and %d FailedEvent rows older than %s',
            $iDeletedLog,
            $iDeletedFailed,
            $sCutoff,
        ));

        return self::SUCCESS;
    }
}
