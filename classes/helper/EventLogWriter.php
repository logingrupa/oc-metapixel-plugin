<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Throwable;

/**
 * Phase 3.1 REFAC-05 race-fence writer — pure I/O primitive (SRP).
 *
 * Inserts row into `logingrupa_metapixel_event_log` via `insertOrIgnore`.
 * Database UNIQUE constraint
 *   (subject_type, subject_id, event_name, channel, site_id)
 * is the atomic race fence. Concurrent INSERTs collide; exactly one wins.
 * Laravel 12 `insertOrIgnore` returns affected-row count = race-winner signal:
 *   - $iAffected === 1 → row created (race winner — proceed)
 *   - $iAffected === 0 → UNIQUE blocked (race loser — abort)
 *
 * Used by:
 *   - Wave 3 SendCapiEvent::handle  — channel='capi',  BEFORE MetaClient::send.
 *   - Wave 3 PurchasePixel::onMarkFired — channel='pixel', AFTER browser fbq.
 *
 * Single helper per concern — Tiger-Style "No duplicate code".
 *
 * Tiger-Style boundary catch on Throwable: DB-write failure does NOT cascade.
 * Returns false on DB error (fail-safe: peer assumed to have won → no
 * double-fire of HTTP POST / fbq). Mirrors SendCapiEvent::writeFailedEvent
 * silent-catch precedent (Phase 3 T-03-22 lock).
 *
 * Subject extraction (extractSubjectId): rejects objects without numeric
 * getKey() — returns 0 → record() returns false + logs warning. T-3.1-06
 * mitigation. Phase 3.1 callers pass Order (always int key); guard exists
 * for future Phase 4 subjects.
 *
 * Site scoping: Phase 3.1-07 REFAC-13 DRY — writer is pure I/O. Caller
 * passes ?int $iSiteId (resolution policy at call sites). NULL on
 * single-site / CLI / queue context — UNIQUE NULL-distinct keeps rows
 * separate from multi-site rows on same table.
 *
 * Threat model:
 *   - T-3.1-06 (Spoofing): invalid subject id → return false + warn.
 *   - T-3.1-08 (DoS): DB outage → return false → caller no Meta POST.
 *   - T-3.1-11 (Repudiation): fired_at + created_at on every INSERT;
 *     UNIQUE prevents mutation → append-only audit trail.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-05
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-13
 * @see plugins/logingrupa/metapixelshopaholic/models/EventLog.php
 * @see plugins/logingrupa/postnordshippingshopaholic/updates/create_order_properties.php line 38 insertOrIgnore idiom
 */
final class EventLogWriter
{
    /**
     * Atomically record event_log row via UNIQUE race fence.
     * Returns true on race win, false on race loss / DB error.
     *
     * @param  string       $sEventId   UUIDv4 paired between CAPI + Pixel for Meta dedup.
     * @param  string       $sEventName 'Purchase' (Phase 3.1) / Phase 4 funnel events.
     * @param  string       $sChannel   EventLog::CHANNEL_CAPI or EventLog::CHANNEL_PIXEL.
     * @param  object       $obSubject  Polymorphic subject (Phase 3.1: only Order).
     * @param  string|null  $sSecretKey Direct-lookup slug for /checkout/{slug}.
     * @param  int          $iEventTime Meta-spec Unix seconds (paired browser+server).
     * @param  ?int         $iSiteId    Caller-resolved site_id (Phase 3.1-07 REFAC-13 DRY).
     */
    public static function record(
        string $sEventId,
        string $sEventName,
        string $sChannel,
        object $obSubject,
        ?string $sSecretKey,
        int $iEventTime,
        ?int $iSiteId = null,
    ): bool {
        try {
            $sSubjectType = get_class($obSubject);
            $iSubjectId = self::extractSubjectId($obSubject);

            if ($iSubjectId <= 0) {
                Log::warning('Metapixel: EventLogWriter rejected subject with invalid id', [
                    'meta_pixel.subject_type' => $sSubjectType,
                    'meta_pixel.subject_id' => $iSubjectId,
                    'meta_pixel.event_id' => $sEventId,
                    'meta_pixel.event_name' => $sEventName,
                    'meta_pixel.channel' => $sChannel,
                ]);

                return false;
            }

            $sNow = (string) Carbon::now();

            // Race fence: insertOrIgnore returns affected count
            // (1 = winner, 0 = UNIQUE collision = loser).
            $iAffected = DB::table((new EventLog())->table)->insertOrIgnore([
                'event_id'     => $sEventId,
                'event_name'   => $sEventName,
                'channel'      => $sChannel,
                'subject_type' => $sSubjectType,
                'subject_id'   => $iSubjectId,
                'secret_key'   => $sSecretKey,
                'site_id'      => $iSiteId,
                'event_time'   => $iEventTime,
                'fired_at'     => $sNow,
                'created_at'   => $sNow,
                'updated_at'   => $sNow,
            ]);

            return $iAffected === 1;
        } catch (Throwable $obException) {
            // silent: DB-write failure must NOT cascade — Tiger-Style boundary.
            // Fail-safe: return false → caller no HTTP POST / fbq. Mirrors
            // SendCapiEvent writeFailedEvent silent-catch (Phase 3 T-03-22).
            // T-3.1-08 surfaces infra failure via Log::critical without
            // hammering Meta API on every retry.
            Log::critical('Metapixel: EventLogWriter::record DB write FAILED', [
                'meta_pixel.event_id' => $sEventId,
                'meta_pixel.event_name' => $sEventName,
                'meta_pixel.channel' => $sChannel,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Narrow $obSubject->getKey() to int, 0 on no-match. Mirrors
     * OrderStatusWatcher::intOrZero (Phase 3 PHPSTAN-01 narrowing).
     * Phpstan level 10 accepts is_int / is_string + is_numeric narrow
     * without `@var` or `assert`.
     */
    private static function extractSubjectId(object $obSubject): int
    {
        if (!method_exists($obSubject, 'getKey')) {
            return 0;
        }

        $mKey = $obSubject->getKey();

        if (is_int($mKey)) {
            return $mKey;
        }

        if (is_string($mKey) && is_numeric($mKey)) {
            return (int) $mKey;
        }

        return 0;
    }
}
