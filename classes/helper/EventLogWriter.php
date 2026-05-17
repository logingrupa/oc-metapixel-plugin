<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;

/**
 * UNIQUE race-fence writer for logingrupa_metapixel_event_log. Returns true
 * when this caller won the insert; false when the row already exists OR
 * when the database refused the write. False is the SAFE direction — if
 * we can't tell who won, we assume the peer won so no double-fire happens.
 *
 * subject_type is resolved via the registered adapter (opaque alias), NEVER
 * via the subject's class FQN — that anti-pattern would store class FQNs
 * which then collide across namespace-rename events.
 */
final class EventLogWriter
{
    public static function record(
        string $sEventId,
        string $sEventName,
        string $sChannel,
        object $obSubject,
        ?string $sSecretKey,
        int $iEventTime,
        ?int $iSiteId,
    ): bool {
        try {
            /** @var AdapterRegistry $obRegistry */
            $obRegistry = App::make(AdapterRegistry::class);
            $obAdapter = $obRegistry->resolveFor($obSubject);
            if ($obAdapter === null) {
                Log::warning('metapixel: EventLogWriter — no adapter registered for subject', [
                    'meta_pixel.subject_class' => get_class($obSubject),
                    'meta_pixel.event_name' => $sEventName,
                    'meta_pixel.channel' => $sChannel,
                ]);

                return false;
            }

            $sSubjectType = $obAdapter->getSubjectType($obSubject);
            $iSubjectId = $obAdapter->getSubjectId($obSubject);
            if ($iSubjectId <= 0) {
                Log::warning('metapixel: EventLogWriter rejected non-positive subject id', [
                    'meta_pixel.subject_type' => $sSubjectType,
                    'meta_pixel.subject_id' => $iSubjectId,
                ]);

                return false;
            }

            $sNow = (string) Carbon::now();
            $iAffected = DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([
                'event_id' => $sEventId,
                'event_name' => $sEventName,
                'channel' => $sChannel,
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'secret_key' => $sSecretKey,
                'site_id' => $iSiteId,
                'event_time' => $iEventTime,
                'fired_at' => $sNow,
                'created_at' => $sNow,
                'updated_at' => $sNow,
            ]);

            return $iAffected === 1;
        } catch (\Throwable $obException) {
            Log::critical('metapixel: EventLogWriter::record DB write FAILED', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
                'meta_pixel.event_id' => $sEventId,
                'meta_pixel.event_name' => $sEventName,
                'meta_pixel.channel' => $sChannel,
            ]);

            // fail-safe: peer assumed to have won so no double-fire is risked
            return false;
        }
    }
}
