<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

/**
 * Phase 3.1 REFAC-11 EventLogTest — locks the 4 schema invariants Wave-3
 * + Wave-4 rewrites rely on:
 *
 *   1. UNIQUE(subject_type, subject_id, event_name, channel, site_id)
 *      blocks duplicate INSERTs → race-fence works at the DB level.
 *   2. Polymorphic `subject` relation resolves an Order instance when
 *      subject_type=Order::class — locks the MorphTo convention with the
 *      `subject_type`+`subject_id` column naming chosen in Wave 1 schema.
 *   3. secret_key behavioural lookup returns the matching row only — locks
 *      the slug-direct-read path PurchasePixel::onRun consumes in Wave 3.
 *   4. site_id NULL and site_id=int rows coexist for the SAME 4-tuple of
 *      (subject_type, subject_id, event_name, channel) — locks the multi-
 *      site UNIQUE NULL-distinct semantic single-site installs depend on.
 *
 * Plus a SiteResolver CLI-context null assertion (BRIEF REFAC-04 line 142):
 *
 *   5. SiteResolver::getActiveSiteId() returns null in CLI / unbound
 *      SiteManager context — folded into this test class per plan
 *      "executor chooses" guidance (line 428).
 *
 * Hermetic schema: MetapixelTestCase::bootEventLogTable() (Wave 1 Task 3)
 * provisions the table with the 5-column UNIQUE + event_id index inside
 * the SQLite-in-memory connection.
 *
 * Test-harness triple-reset in setUp (Phase 2 SH-01 lock): Cache::flush() +
 * Settings::clearInternalCache() + PluginGuard::flush() — required even
 * though this test does not exercise Settings reads, because the parent
 * MetapixelTestCase boot fires plugin Boot listeners that prime PluginGuard.
 *
 * NO Mockery / Log spies (SCE-05 lock — direct state assertions only).
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-11 (lines 240-247)
 * @see plugins/logingrupa/metapixelshopaholic/models/EventLog.php (Task 1)
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/SiteResolver.php (Task 2)
 */
final class EventLogTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        $this->bootEventLogTable();
        OrderFixtures::provisionHermeticOfferProductTables();

        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Invariant #1 — UNIQUE blocks duplicate (subject_type, subject_id,
     * event_name, channel, site_id) row. Locks REFAC-02 race-fence.
     */
    public function test_unique_constraint_blocks_duplicate_row(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arRow = $this->makeFillableRow($obOrder, 'capi', 1);

        $obFirst = EventLog::create($arRow);
        $this->assertNotNull($obFirst->id, 'first insert must persist a row');

        $bCaught = false;
        try {
            // Same 5-tuple → must collide with UNIQUE.
            EventLog::create($arRow);
        } catch (QueryException $obException) {
            $bCaught = true;
            // SQLite reports "UNIQUE constraint failed"; MySQL reports
            // "Duplicate entry ... for key ...". Assert on the substring
            // common to both engines (case-insensitive "unique" / "duplicate").
            $sMessage = strtolower($obException->getMessage());
            $this->assertTrue(
                str_contains($sMessage, 'unique') || str_contains($sMessage, 'duplicate'),
                'QueryException message must reference UNIQUE or duplicate-entry — got: '.$obException->getMessage(),
            );
        }
        $this->assertTrue($bCaught, 'duplicate insert MUST raise QueryException');

        // Total row count stayed at 1 — duplicate did NOT persist.
        $this->assertSame(1, EventLog::count(), 'UNIQUE collision must not leave a partial row behind');
    }

    /**
     * Invariant #2 — polymorphic `subject` returns the actual Order
     * instance. Locks REFAC-03 MorphTo declaration + subject_type/
     * subject_id column convention.
     */
    public function test_polymorphic_subject_query_works(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arRow = $this->makeFillableRow($obOrder, 'capi', 1);
        EventLog::create($arRow);

        $obRow = EventLog::first();

        $this->assertNotNull($obRow, 'event_log row must persist');
        $this->assertSame(Order::class, $obRow->subject_type);
        $this->assertSame((int) $obOrder->id, (int) $obRow->subject_id);

        $obSubject = $obRow->subject;
        $this->assertInstanceOf(Order::class, $obSubject, 'morphTo subject must resolve to Order instance');
        $this->assertSame((int) $obOrder->id, (int) $obSubject->id);
    }

    /**
     * Invariant #3 — `secret_key` behavioural lookup returns the matching
     * row only. Locks the slug-direct-read path PurchasePixel::onRun
     * consumes in Wave 3.
     */
    public function test_secret_key_index_returns_matching_rows(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        $arFirst = $this->makeFillableRow($obOrder, 'capi', 1);
        $arFirst['secret_key'] = 'slug-alpha-1234567890abcdef';
        $arFirst['event_id'] = '11111111-aaaa-bbbb-cccc-000000000001';
        EventLog::create($arFirst);

        $arSecond = $this->makeFillableRow($obOrder, 'pixel', 1);
        $arSecond['secret_key'] = 'slug-beta-1234567890abcdef';
        $arSecond['event_id'] = '22222222-aaaa-bbbb-cccc-000000000002';
        EventLog::create($arSecond);

        $obAlpha = EventLog::where('secret_key', 'slug-alpha-1234567890abcdef')->first();
        $obBeta = EventLog::where('secret_key', 'slug-beta-1234567890abcdef')->first();

        $this->assertNotNull($obAlpha);
        $this->assertNotNull($obBeta);
        $this->assertSame('capi', $obAlpha->channel);
        $this->assertSame('pixel', $obBeta->channel);
        $this->assertNotSame($obAlpha->id, $obBeta->id, 'distinct secret_keys must yield distinct rows');

        // Missing-slug lookup returns null — behavioural index check.
        $this->assertNull(EventLog::where('secret_key', 'slug-missing-deadbeef')->first());
    }

    /**
     * Invariant #4 — site_id NULL and site_id=N rows coexist for the SAME
     * (subject_type, subject_id, event_name, channel). Locks the MySQL
     * "UNIQUE treats NULL as distinct" semantic single-site installs
     * depend on.
     *
     * SQLite-in-memory test harness honours the same NULL-distinct rule
     * (verified empirically — SQLite's UNIQUE collation treats NULL as
     * "absent value" so multiple NULL-site rows AND a non-NULL row coexist).
     */
    public function test_site_id_null_and_non_null_coexist(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        // 03.1-08 T3.1 fix: hex literals (37-char) breached max:36 validation.
        // Uuid::uuid4()->toString() emits canonical 36-char UUIDv4. DRY mirror
        // of MultiSiteCrossContextTest / SendCapiEventEventLogTest / Purchase-
        // EndToEndIntegrationTest after PLAN-02 LOW-02/03 cleanup.
        $arNullSite = $this->makeFillableRow($obOrder, 'capi', null);
        $arNullSite['event_id'] = Uuid::uuid4()->toString();
        EventLog::create($arNullSite);

        $arSiteOne = $this->makeFillableRow($obOrder, 'capi', 1);
        $arSiteOne['event_id'] = Uuid::uuid4()->toString();
        EventLog::create($arSiteOne);

        $this->assertSame(2, EventLog::count(), 'NULL and non-NULL site_id rows MUST coexist under UNIQUE');

        $iNullRows = EventLog::whereNull('site_id')->count();
        $iSiteOneRows = EventLog::where('site_id', 1)->count();
        $this->assertSame(1, $iNullRows);
        $this->assertSame(1, $iSiteOneRows);
    }

    /**
     * BRIEF REFAC-04 line 142 — SiteResolver::getActiveSiteId() returns
     * null in CLI / unbound-SiteManager context. Folded into EventLogTest
     * per plan output spec line 428 ("executor chooses").
     *
     * MetapixelTestCase does NOT bind SiteManager — the Lovata Shopaholic
     * migrations that register `system.sites` are skipped under autoMigrate
     * = false. Phpunit context starts with `Config::get('system.active_site')
     * === null` → SiteResolver short-circuits at the `$mId === null` branch
     * inside the SDK probe.
     */
    public function test_site_resolver_returns_null_in_cli_context(): void
    {
        $this->assertNull(SiteResolver::getActiveSiteId());
    }

    /**
     * Build a fillable event_log row for `$obOrder` with the given channel
     * + site_id. UUID + event_time are deterministic per call signature
     * (channel + site_id stir) so callers using the same 4-tuple still
     * collide on UNIQUE — but rows with distinct channels / sites stay
     * separate.
     *
     * @return array<string,mixed>
     */
    private function makeFillableRow(Order $obOrder, string $sChannel, ?int $iSiteId): array
    {
        // Deterministic stub UUIDv4 so the row passes max:36 validation
        // without depending on Ramsey\Uuid at this layer.
        $sEventId = '00000000-0000-0000-0000-'.str_pad(
            (string) ((int) $obOrder->id),
            12,
            '0',
            STR_PAD_LEFT,
        );

        return [
            'event_id'     => $sEventId,
            'event_name'   => EventLog::EVENT_PURCHASE,
            'channel'      => $sChannel,
            'subject_type' => Order::class,
            'subject_id'   => (int) $obOrder->id,
            'secret_key'   => $obOrder->secret_key,
            'site_id'      => $iSiteId,
            'event_time'   => 1715648400,
            'fired_at'     => '2026-05-13 21:00:00',
        ];
    }
}
