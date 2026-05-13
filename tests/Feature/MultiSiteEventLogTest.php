<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Logingrupa\Metapixelshopaholic\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;

/**
 * Phase 3.1 REFAC-11 final-slice test — multi-site contract proof.
 *
 * Locks the 3 multi-site invariants from BRIEF.md REFAC-11 lines 271-274
 * + Multi-site contract block (lines 52-74):
 *
 *   1. Two sites bind the SAME Order id → two independent EventLog rows
 *      coexist under UNIQUE because site_id is part of the 5-column
 *      composite key and distinguishes the rows. Validates the
 *      cross-site dispatch contract: same Order on .no and .lv (and .lt)
 *      fires CAPI once per site Pixel.
 *
 *   2. Single-site install (SiteResolver returns null because no active
 *      site is bound in Config) → site_id IS NULL on the inserted row.
 *      Validates the NULL-distinct semantic that single-site installs
 *      depend on (the same Order will never collide with a multi-site
 *      row even on a shared DB).
 *
 *   3. Active-site-scoped read returns ONLY rows for the current
 *      site_id. Validates the read-side branching in OrderStatusWatcher::
 *      alreadyDispatched + PurchasePixel::findEventLogRow.
 *
 * Deterministic event_time literal (1715000000) so the test is
 * time-independent (Tiger-Style determinism). UUID literals likewise
 * deterministic per call so a `git bisect` reproduces identical row
 * shapes byte-for-byte.
 *
 * SDK injection: Config::set('system.active_site', $iSiteId) — October 4
 * SiteManager::instance()->getActiveSiteId() reads through
 * Config::get('system.active_site') per HasActiveSite trait
 * (modules/system/classes/sitemanager/HasActiveSite.php line 84-87).
 * No App::instance('system.sites', ...) swap needed because the SDK
 * reads from Config, not from any singleton state.
 *
 * Tiger-Style boundary: SiteResolver wraps the SDK probe in a Throwable
 * catch — if SiteManager::instance() fails in this test environment
 * (e.g. service-provider not bound), the resolver returns null and the
 * test reduces to the single-site branch. Test 1's two-row expectation
 * still holds because SiteResolver receives different Config values
 * across the two record() calls (different in-flight site bindings),
 * and the UNIQUE NULL-distinct semantic separates non-NULL from NULL.
 *
 * tearDown: Config::set('system.active_site', null) resets the active
 * site so subsequent test files do not inherit a multi-site binding
 * (T-3.1-25 mitigation).
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-11 lines 271-274
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/03.1-PATTERNS.md lines 1195-1259
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/SiteResolver.php
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/EventLogWriter.php
 * @see modules/system/classes/sitemanager/HasActiveSite.php line 84-87 (SDK Config-read)
 */
final class MultiSiteEventLogTest extends MetapixelTestCase
{
    /**
     * Deterministic event_time literal — Tiger-Style "same input → same output".
     * Picked to be a Unix-seconds value Meta's CAPI would accept (May 2024).
     */
    private const int FIXED_EVENT_TIME = 1715000000;

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
        // T-3.1-25 mitigation — reset Config so subsequent test files do
        // not inherit this test's multi-site binding.
        Config::set('system.active_site', null);
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Invariant #1 — two sites, same Order id, independent INSERTs.
     *
     * Both record() calls win their race because the UNIQUE composite
     * `(subject_type, subject_id, event_name, channel, site_id)` includes
     * site_id; switching the active site between calls means the two
     * INSERTs land on different unique-key tuples. Locks the BRIEF.md
     * "two sites bind same Order id → two independent CAPI fires"
     * acceptance criterion (line 293).
     */
    public function test_two_sites_bind_same_order_id_records_independently(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        // Site A — bind active_site=1, record CAPI dispatch.
        Config::set('system.active_site', 1);
        $bWonA = EventLogWriter::record(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_CAPI,
            $obOrder,
            (string) $obOrder->secret_key,
            self::FIXED_EVENT_TIME,
        );

        // Site B — switch active_site=2, record CAPI dispatch for SAME Order id.
        Config::set('system.active_site', 2);
        $bWonB = EventLogWriter::record(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_CAPI,
            $obOrder,
            (string) $obOrder->secret_key,
            self::FIXED_EVENT_TIME,
        );

        $this->assertTrue($bWonA, 'Site A insert must win its race (no prior row).');
        $this->assertTrue($bWonB, 'Site B insert must win its race (site_id differs from Site A row).');
        $this->assertSame(2, EventLog::count(), 'Two sites with same Order id MUST yield exactly 2 EventLog rows.');
    }

    /**
     * Invariant #2 — single-site install (SiteResolver returns null) →
     * row's site_id column IS NULL.
     *
     * SiteManager is NOT bound in MetapixelTestCase boot, so SiteResolver
     * either short-circuits on `!class_exists(SiteManager::class)` OR
     * (if the class is autoloadable) reaches HasActiveSite::getActiveSiteId
     * which reads `Config::get('system.active_site')` — and we set that
     * to null in this test. Either branch returns null, which writes
     * NULL on the site_id column.
     *
     * Locks the UNIQUE NULL-distinct semantic single-site operators
     * depend on (BRIEF.md "Single-site install (`SiteResolver` returns
     * null) → `site_id NULL` on all rows", line 273).
     */
    public function test_single_site_install_writes_null_site_id(): void
    {
        Config::set('system.active_site', null);
        $obOrder = OrderFixtures::makePaidOrder();

        $bWon = EventLogWriter::record(
            'cccccccc-cccc-cccc-cccc-cccccccccccc',
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_CAPI,
            $obOrder,
            (string) $obOrder->secret_key,
            self::FIXED_EVENT_TIME,
        );

        $this->assertTrue($bWon, 'Single-site insert must win its race (no prior row).');

        $obRow = EventLog::first();
        $this->assertNotNull($obRow, 'EventLog row must persist after record().');
        // assertNull narrows to phpstan — site_id must be the SQL NULL,
        // NOT integer 0 (NOT NULL-distinct under MySQL/SQLite UNIQUE).
        $this->assertNull($obRow->site_id, 'site_id must be SQL NULL on single-site (not int 0).');
    }

    /**
     * Invariant #3 — active-site-scoped read excludes other sites' rows.
     *
     * Seeds one row under site 1 + one row under site 2, then switches
     * Config back to site 1 and asserts the SiteResolver-scoped read
     * returns exactly the site-1 row. Locks the read-side branching
     * pattern that OrderStatusWatcher::alreadyDispatched + PurchasePixel::
     * findEventLogRow use to keep cross-site rows invisible to each
     * other's dispatch flow (BRIEF.md "Read scoped to active site only",
     * line 274).
     */
    public function test_active_site_scoped_read_excludes_other_sites_rows(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        // Seed site 1 row.
        Config::set('system.active_site', 1);
        EventLogWriter::record(
            'dddddddd-dddd-dddd-dddd-dddddddddddd',
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_CAPI,
            $obOrder,
            (string) $obOrder->secret_key,
            self::FIXED_EVENT_TIME,
        );

        // Seed site 2 row (different Pixel = different site_id row).
        Config::set('system.active_site', 2);
        EventLogWriter::record(
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            EventLog::EVENT_PURCHASE,
            EventLog::CHANNEL_CAPI,
            $obOrder,
            (string) $obOrder->secret_key,
            self::FIXED_EVENT_TIME,
        );

        // Read scoped to site 1 — site 2 row must NOT appear.
        Config::set('system.active_site', 1);
        $iCount = EventLog::where('site_id', SiteResolver::getActiveSiteId())->count();

        $this->assertSame(1, $iCount, 'Site-scoped read MUST exclude rows from other sites.');
        // Sanity — both rows still exist on the table; only the scoped query filters.
        $this->assertSame(2, EventLog::count(), 'Both seeded rows must persist on the table.');
    }
}
