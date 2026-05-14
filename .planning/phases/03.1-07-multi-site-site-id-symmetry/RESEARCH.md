---
phase: 03.1-07
type: research
created: 2026-05-14
consumed_by: gsd-planner
---

# Phase 3.1-07 — Research

## Goal recap

Reader (frontend `/lv/checkout/{slug}`) and writer (admin `/back` flip → queue) MUST resolve the SAME `site_id` for the same Order. Today they both call `SiteResolver::getActiveSiteId()` which reads `SiteManager::instance()->getActiveSiteId()` — request-context-dependent. Result: writer persists `site_id=NULL` from admin context; reader queries `where site_id=1` on frontend; gate fails; Pixel never renders.

Authoritative truth = `lovata_orders_shopaholic_orders.site_id` (column populated at order-create by Lovata `OrderProcessor`).

## Upstream contract — `lovata_orders_shopaholic_orders.site_id`

- **Column origin:** `plugins/lovata/ordersshopaholic/updates/table_update_orders_add_site_id_field.php` — `$obTable->integer('site_id')->nullable()`. Plugin v1.33 (Phase v172 in `updates/version.yaml`).
- **Write path:** `plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:143` — `$this->arOrderData['site_id'] = Site::getSiteIdFromContext()`. Set ONCE at order-create. Never updated.
- **Model fillable:** `Order::$fillable` includes `site_id` (`plugins/lovata/ordersshopaholic/models/Order.php:145`). Annotated `@property int $site_id` (line 39).
- **Production check (2026-05-14, `new.nailscosmetics.lv`):** orders 29802 + 29803 both have `site_id=1` from t=0. BRIEF section "Production check" lines 56-64.
- **Determinism guarantee:** column is written once at create, persisted, never mutated by Lovata. `$obOrder->getAttribute('site_id')` returns identical value from frontend FPM context, admin FPM context, queue worker, CLI tinker. Eliminates the request-context drift that broke `getActiveSiteId()`.

## Existing plugin primitives

- `Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver::getActiveSiteId(): ?int` (`classes/helper/SiteResolver.php`). Tiger-Style boundary catch — `class_exists(SiteManager::class)` guard, Throwable catch, `Log::warning('Metapixel: SiteResolver SDK probe failed …')`. **Stays untouched** — kept for future non-Order subjects (Lead, AddToCart, ViewContent).
- `Logingrupa\Metapixelshopaholic\Classes\Helper\EventLogWriter::record(...)` (`classes/helper/EventLogWriter.php:72-133`). Reads `SiteResolver::getActiveSiteId()` internally at line 96 — **this internal call must move out**. DRY: caller passes resolved `?int $iSiteId`.
- `Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher::alreadyDispatched(Order $obOrder): bool` (`classes/event/OrderStatusWatcher.php:259-279`). Calls `SiteResolver::getActiveSiteId()` at line 265. Migrate to `SiteResolver::forOrder($obOrder)`.
- `Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent::raceFenceWon()` (`classes/queue/SendCapiEvent.php:201-221`). Calls `EventLogWriter::record(...)`. Must pass `SiteResolver::forOrder($this->obSubject)` as new 7th arg.
- `Logingrupa\Metapixelshopaholic\Components\PurchasePixel::findEventLogRow(Order $obOrder, string $sChannel): ?EventLog` (`components/PurchasePixel.php:302-325`). Calls `SiteResolver::getActiveSiteId()` at line 309. Migrate to `SiteResolver::forOrder($obOrder)`.

## New primitive — `SiteResolver::forOrder(Order): ?int`

```php
public static function forOrder(Order $obOrder): ?int
{
    $mId = $obOrder->getAttribute('site_id');
    return is_numeric($mId) ? (int) $mId : null;
}
```

- **SRP:** treats `$obOrder->getAttribute('site_id')` as a black-box accessor. If Lovata ever migrates the storage location, only `forOrder` changes — every call site untouched.
- **Defensive narrow:** `is_numeric` rejects string `'banana'` / `false` / array. Mirrors `intOrZero` narrowing pattern in `OrderStatusWatcher.php:346-359`.
- **Returns null when:** Order has no `site_id` attribute (mock fixtures), value non-numeric, value SQL NULL (single-site install pre-Lovata-multi-site-migration), single-site install with column never populated.
- **No Throwable catch needed:** `$obOrder->getAttribute()` is a pure October accessor — never throws on missing attribute (returns null). Tiger-Style: don't catch what can't throw.

## Backwards-compat plan for `EventLogWriter::record`

Add 7th OPTIONAL parameter `?int $iSiteId = null` with explicit default. In the SAME plan (lock-step), rewrite both caller sites (`SendCapiEvent::raceFenceWon`, `PurchasePixel::onMarkFired`) to pass the resolved `?int`. Then DROP the internal `SiteResolver::getActiveSiteId()` call inside `record()`.

Why optional: per BRIEF "Constraints" line 192 — "old call sites unaffected during the wave; rewire tasks update them in lock-step within the same plan". Within ONE plan there is no transition window; outside callers do not exist (single plugin, two callers). The OPTIONAL default `null` exists strictly so the signature does not break any in-flight WIP branch.

Final signature:
```php
public static function record(
    string $sEventId,
    string $sEventName,
    string $sChannel,
    object $obSubject,
    ?string $sSecretKey,
    int $iEventTime,
    ?int $iSiteId = null,
): bool
```

Internal `$iSiteId = SiteResolver::getActiveSiteId();` line REMOVED. Insert uses the caller-supplied `$iSiteId` verbatim.

## Test surface — reusable patterns

- **Time-freeze:** `Carbon::setTestNow(Carbon::createFromTimestamp(1715000000))` in setUp. Mirrors `MultiSiteEventLogTest::FIXED_EVENT_TIME` literal pattern.
- **Hermetic boot chain:** `parent::setUp() → bootSystemSettings → bootOrdersStatuses → bootOrdersTable → bootEventLogTable → OrderFixtures::provisionHermeticOfferProductTables`. Existing — already proves the round-trip works for Phase 3.1 Wave 4.
- **`bootOrdersTable` site_id column gap:** current schema (`tests/MetapixelTestCase.php:238-255`) does NOT include `site_id` on the hermetic orders table. The new test surface needs it. Two options:
  1. Add `$obTable->integer('site_id')->nullable();` to `bootOrdersTable()`.
  2. Per-test `Schema::table(..., fn (Blueprint) => $obTable->integer('site_id')->nullable())` patch in setUp (precedent: `OrderFixtures::provisionHermeticOfferProductTables` already patches `one_c_status_id` post-hoc at line 61-66).
  - **Pick option 1** — `site_id` is now a stable Lovata column (v1.33), Phase 3.1 onward all tests need it. One-line additive change, no callers affected (existing tests do not set `site_id` so the value will be NULL — identical to current behavior).
- **Fixture site_id seed:** `OrderFixtures::makePaidOrder()` uses `forceFill` (mass-assignment bypass for `secret_key` etc). Tests for Phase 3.1-07 will append `$obOrder->site_id = 1; $obOrder->save();` after `makePaidOrder()` to force a known value. Avoid adding `site_id` to `makePaidOrder`'s default forceFill block — tests want explicit control to exercise null vs int paths.
- **Config injection still used for `getActiveSiteId` divergence tests:** the new `MultiSiteCrossContextTest` MUST set `Config::set('system.active_site', null)` (writer admin context) and `Config::set('system.active_site', 1)` (reader frontend context) to PROVE the writer-reader-divergence scenario from BRIEF lines 22-30. The new test fails today because `getActiveSiteId()` is the resolver; passes after `forOrder` rewires the callers.
- **MultiSiteEventLogTest adjustment:** BRIEF line 148 — "swap `Config::set('system.active_site', $i)` → set `$obOrder->site_id = $i` so the test exercises the new contract". Methods adjusted: `test_two_sites_bind_same_order_id_records_independently`, `test_active_site_scoped_read_excludes_other_sites_rows`. `test_single_site_install_writes_null_site_id` stays (single-site install = order with `site_id=null` AND `Config` unset).

## Backfill SQL — preconditions + correctness

```sql
UPDATE logingrupa_metapixel_event_log el
JOIN lovata_orders_shopaholic_orders o
   ON o.id = el.subject_id
  AND el.subject_type = 'Lovata\\OrdersShopaholic\\Models\\Order'
SET el.site_id = o.site_id
WHERE el.site_id IS NULL
  AND o.site_id IS NOT NULL;
```

- **Preconditions:** runs on each affected site BEFORE v1.1.1 deploys. Safe because UNIQUE column-fence is the only consumer; the rows being repaired were already invisible to the reader pre-deploy (the bug).
- **Correctness:** WHERE clause restricts to NULL-row repair only (will never overwrite a deliberately-set NULL on a single-site install). JOIN on `subject_id = o.id` is correct because Phase 3.1 EventLog rows for Purchase are polymorphic-typed `'Lovata\\OrdersShopaholic\\Models\\Order'` (see `EventLog::EVENT_PURCHASE` + `OrderStatusWatcher::alreadyDispatched` line 267).
- **Idempotent:** re-running the SQL after a deploy is a no-op (no more NULL+JOIN matches).
- **Lives at:** `.planning/phases/03.1-07-multi-site-site-id-symmetry/BACKFILL.sql` with a header docblock spelling out preconditions + idempotency.

## Threat model — extras the planner should bake in

- **T-3.1-30 (test-mocked Order missing site_id):** `getAttribute('site_id')` returns null → `forOrder` returns null → behavior matches single-site path. Existing tests stay green without modification.
- **T-3.1-31 (Order.site_id tampered post-create):** Lovata's admin-protected column. Plugin treats as read-only authoritative source (no write back). Mirrors BRIEF "Threat Model Delta" row 1.
- **T-3.1-32 (single-site → multi-site migration mid-flight):** `forOrder` returns null for orders predating the migration. UNIQUE NULL-distinct semantics still hold. Backfill SQL runs once per site post-migration. Mirrors BRIEF row 2.

## Version bump rationale (1.1.0 → 1.1.1)

- **Patch (not minor):** no schema change, no public-API break.
- `EventLogWriter::record` signature gains OPTIONAL 7th parameter with default `null` — backwards-compatible for any in-flight caller branch.
- `SiteResolver::forOrder` is purely additive.
- Three call-site rewires (Watcher, SendCapiEvent, PurchasePixel) are internal-only.
- No migration ships in this plan (backfill SQL is operator-side one-shot, not a plugin migration).

## Out of scope (BRIEF lines 128-134)

- Lovata's `lovata_orders_shopaholic_orders.site_id` column (READ-only — upstream owns it).
- `system_site_definitions` table or SiteManager wiring.
- Non-Order subjects (Lead, AddToCart, ViewContent) — Phase 4.
- `EventLog` model schema — no new columns.
- Migrations to plugin-owned tables — none needed.
- Phase 4 funnel events.

## References

- BRIEF: `.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md`
- Prior wave: `.planning/phases/03.1-event-log-refactor/03.1-05-SUMMARY.md` (multi-site test)
- PATTERNS: `.planning/phases/03.1-event-log-refactor/03.1-PATTERNS.md` lines 1195-1259 (MultiSiteEventLogTest pattern)
- Lovata upstream: `plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:143`, `plugins/lovata/ordersshopaholic/updates/table_update_orders_add_site_id_field.php`
- Production incident: 2026-05-14 — orders 29802 + 29803 on `new.nailscosmetics.lv`.
