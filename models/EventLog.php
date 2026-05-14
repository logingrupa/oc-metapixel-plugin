<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Models;

use Carbon\Carbon;
use Lovata\OrdersShopaholic\Models\Order;
use Model;
use October\Rain\Database\Builder;
use October\Rain\Database\Relations\MorphTo;
use October\Rain\Database\Traits\Validation;

/**
 * Class EventLog
 *
 * Phase 3.1 REFAC-03 — single source of truth for "has this Meta event fired
 * for this subject (channel-scoped, site-scoped)". Plugin-owned table that
 * supersedes the Phase-3 column-based dedup fence on Lovata's orders table.
 * Plugin no longer mutates Lovata's table — clean SRP for third-party operators.
 *
 * Row shape per channel:
 *   - channel='capi'  — server-side dispatch race-winner. Written by
 *     EventLogWriter::record from SendCapiEvent::handle (Wave 3) BEFORE the
 *     HTTP POST. UNIQUE blocks concurrent dispatches (PayPal return + IPN
 *     race) — exactly one job POSTs to Meta.
 *   - channel='pixel' — browser-side fbq('track','Purchase') confirmation.
 *     Written by PurchasePixel::onMarkFired AJAX (Wave 3) AFTER the browser
 *     fbq call. Once present, PurchasePixel::onRun renders nothing for any
 *     subsequent visit (same device, different device, incognito, +10 days
 *     — server-side persistence is independent of Meta's 7-day eventID
 *     dedup window which expires on the Meta side).
 *
 * Polymorphic subject — designed forward for Phase 4 funnel events
 * (subject = Cart, User, Lead form, etc.). Phase 3.1 ships only Order.
 * MorphTo convention requires `subject_type` + `subject_id` columns —
 * the schema (Wave 1) ships with exactly those names.
 *
 * Race-fence contract: UNIQUE(subject_type, subject_id, event_name, channel,
 * site_id) is enforced at the DB level (see updates/create_metapixel_event_log_table.php).
 * Concurrent INSERTs collide on the constraint; exactly one wins. The
 * `EventLogWriter::record(...)` helper returns true|false based on that race
 * outcome (insertOrIgnore affected-rows === 1 ? winner : loser).
 *
 * Multi-site (Phase 3.1-07 REFAC-13): `site_id` is caller-supplied. Writer
 * pure I/O — accepts explicit `?int $iSiteId` 7th arg, no internal resolve.
 * DRY — resolution policy at call sites. Order-scoped subjects (Watcher,
 * SendCapiEvent, PurchasePixel) pass `SiteResolver::forOrder($obOrder)` so
 * writer+reader symmetry holds across request contexts (admin / queue /
 * frontend) for the same Order. Future non-Order subjects (Lead, AddToCart,
 * ViewContent — Phase 4) may use `SiteResolver::getActiveSiteId()` for
 * request-scoped writes. MySQL UNIQUE treats NULL as distinct, so single-
 * site (`site_id=null`) and multi-site (`site_id=int`) rows coexist on the
 * same table.
 *
 * Append-only — no business mutation methods. Audit-trail rows. `$fillable`
 * excludes `id`, `created_at`, `updated_at` so mass-assignment cannot
 * bypass framework timestamps or the auto-increment key.
 *
 * @property int $id
 * @property string $event_id UUIDv4 paired between CAPI + Pixel for Meta dedup.
 * @property string $event_name 'Purchase' (Phase 3.1) / 'AddToCart' / 'ViewContent' / 'Lead' (Phase 4).
 * @property string $channel EventLog::CHANNEL_CAPI ('capi') or EventLog::CHANNEL_PIXEL ('pixel').
 * @property string $subject_type Polymorphic FK class string (e.g. `Lovata\OrdersShopaholic\Models\Order`).
 * @property int $subject_id Polymorphic FK id.
 * @property string|null $secret_key Direct-lookup slug for /checkout/{slug} render path.
 * @property int|null $site_id October 4 multi-site scope; null on single-site installs.
 * @property int $event_time Meta-spec Unix-seconds, paired between channels.
 * @property Carbon $fired_at Row insertion timestamp.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @mixin Builder
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-03
 * @see plugins/logingrupa/metapixelshopaholic/updates/create_metapixel_event_log_table.php (Wave 1)
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/EventLogWriter.php (writes rows of this model)
 * @see Order — the only subject_type bound in Phase 3.1 (PHPDoc-only import, no runtime coupling).
 */
final class EventLog extends Model
{
    use Validation;

    /** @var string Server-side CAPI dispatch channel — race-fence winner row. */
    public const CHANNEL_CAPI = 'capi';

    /** @var string Browser-side Pixel confirmation channel — fbq fired marker. */
    public const CHANNEL_PIXEL = 'pixel';

    /** @var string Meta event name for completed checkout. */
    public const EVENT_PURCHASE = 'Purchase';

    /** @var string */
    public $table = 'logingrupa_metapixel_event_log';

    /** @var array<string,string> */
    public $rules = [
        'event_id'     => 'required|string|max:36',
        'event_name'   => 'required|string|max:64',
        'channel'      => 'required|string|max:16',
        'subject_type' => 'required|string|max:255',
        'subject_id'   => 'required|integer',
        'secret_key'   => 'nullable|string|max:64',
        'site_id'      => 'nullable|integer',
        'event_time'   => 'required|integer',
        'fired_at'     => 'required',
    ];

    /** @var list<string> */
    public $fillable = [
        'event_id',
        'event_name',
        'channel',
        'subject_type',
        'subject_id',
        'secret_key',
        'site_id',
        'event_time',
        'fired_at',
    ];

    /** @var list<string> */
    public $dates = ['fired_at', 'created_at', 'updated_at'];

    /** @var array<string,string> */
    public $casts = [
        'subject_id' => 'int',
        'site_id'    => 'int',
        'event_time' => 'int',
    ];

    /**
     * October declarative polymorphic relation. Phase 3.1 binds Order only;
     * Phase 4 may bind Cart, User, Lead form. Column convention
     * `subject_type` + `subject_id` matches October MorphTo default — no
     * `$obTable->morphs('subject')` needed in the migration.
     *
     * @var array<string, array<int|string, mixed>>
     */
    public $morphTo = [
        'subject' => [],
    ];

    /**
     * Typed polymorphic accessor for phpstan level 10 + IDE completion.
     * Property declaration above is the runtime relation; this method
     * surfaces the typed return for static analysis.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }
}
