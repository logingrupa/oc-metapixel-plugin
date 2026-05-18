<?php

namespace Logingrupa\Metapixel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes EventLog rows older than 7 days. Wired daily via
 * Plugin::registerSchedule (Phase 3 D-08 lock).
 */
final class PurgeEventLog extends Command
{
    /** @var string */
    protected $signature = 'metapixel:purge-event-log';

    /** @var string */
    protected $description = 'Delete EventLog rows older than 7 days (Phase 3 TTL purge)';

    public function handle(): int
    {
        $sCutoff = (string) Carbon::now()->subDays(7);
        $iDeleted = DB::table('logingrupa_metapixel_event_log')
            ->where('created_at', '<', $sCutoff)
            ->delete();

        Log::info('metapixel: purge-event-log', [
            'meta_pixel.rows_deleted' => $iDeleted,
            'meta_pixel.cutoff' => $sCutoff,
        ]);

        $this->info(sprintf('Purged %d EventLog rows older than %s', $iDeleted, $sCutoff));

        return self::SUCCESS;
    }
}
