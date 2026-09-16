<?php

namespace Logingrupa\Metapixel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\OrderBrowserContext;

/**
 * Deletes EventLog and FailedEvent rows older than the retention window.
 * Wired daily via Plugin::registerSchedule.
 */
final class PurgeEventLog extends Command
{
    /** @var string */
    protected $signature = 'metapixel:purge-event-log';

    /** @var string */
    protected $description = 'Delete EventLog and FailedEvent rows older than 7 days, order browser contexts older than 90 days';

    public function handle(): int
    {
        $sCutoff = (string) Carbon::now()->subDays(FailedEvent::RETENTION_DAYS);
        $iDeletedLog = DB::table('logingrupa_metapixel_event_log')
            ->where('created_at', '<', $sCutoff)
            ->delete();
        $iDeletedFailed = DB::table('logingrupa_metapixel_failed_events')
            ->where('created_at', '<', $sCutoff)
            ->delete();
        $sContextCutoff = (string) Carbon::now()->subDays(OrderBrowserContext::RETENTION_DAYS);
        $iDeletedContexts = DB::table('logingrupa_metapixel_order_browser_contexts')
            ->where('created_at', '<', $sContextCutoff)
            ->delete();

        Log::info('metapixel: purge-event-log', [
            'meta_pixel.rows_deleted' => $iDeletedLog,
            'meta_pixel.failed_rows_deleted' => $iDeletedFailed,
            'meta_pixel.context_rows_deleted' => $iDeletedContexts,
            'meta_pixel.cutoff' => $sCutoff,
            'meta_pixel.context_cutoff' => $sContextCutoff,
        ]);

        $this->info(sprintf(
            'Purged %d EventLog rows and %d FailedEvent rows older than %s, %d order browser contexts older than %s',
            $iDeletedLog,
            $iDeletedFailed,
            $sCutoff,
            $iDeletedContexts,
            $sContextCutoff,
        ));

        return self::SUCCESS;
    }
}
