---
id: 03.1-07
phase_name: multi-site-site-id-symmetry
parent_milestone: v1.2.0
authors: rolands
created: 2026-05-14
type: bug-hotfix
severity: production-blocking
plugin_version_bump: 1.1.0 → 1.1.1
sites_affected:
  - new.nailscosmetics.lv (confirmed broken)
  - staging.nailscosmetics.lt (suspected, same code)
  - staging.nailscosmetics.no (suspected, same code)
---

# Phase 3.1-07 — Multi-Site `site_id` Symmetry

## Problem

Phase 3.1 (v1.1.0) shipped server-deduplicated Purchase pairing via plugin-owned
`logingrupa_metapixel_event_log`. UNIQUE constraint is scoped by `site_id`.
`SiteResolver::getActiveSiteId()` reads `SiteManager::instance()->getActiveSiteId()`
which is **request-context-dependent**:

| Context | Active site | Outcome |
|---|---|---|
| Frontend `/lv/checkout/...` (PurchasePixel render) | `1` | reader queries `where site_id=1` |
| Admin `/back` flip (SendCapiEvent dispatch via OrderStatusWatcher) | `null` | writer persists `site_id=NULL` |
| Sync queue inside admin request | `null` | same NULL |

Result: writer + reader disagree on `site_id` for the same Order. `PurchasePixel::findEventLogRow(CAPI)`
returns null → gate fails → no Pixel block renders. Browser never tracks Purchase.
CAPI server-side fires fine (Test Events tab confirms HTTP 2xx with test_event_code).

Production evidence (collected 2026-05-14 on `new.nailscosmetics.lv`):

```
-- event_log rows (post-migration, post-flip):
id=1 channel=capi subject_id=29802 site_id=NULL   ← written from admin context
id=2 channel=capi subject_id=29803 site_id=NULL   ← written from admin context

-- 10× curl /lv/checkout/{secret_key} → 0/10 rendered the Purchase block.
-- CLI tinker probe with SiteManager NULL → arMetaEvent fully populated (correct).
-- Frontend FPM with SiteManager=1 → silent gate exit, no log line, no render.
```

## Root Cause (locked)

`SiteResolver::getActiveSiteId()` is the **wrong primitive** for any code path that
operates on a known Order. Active site at the time of write is not guaranteed to
equal active site at the time of read. The Order itself already carries the
authoritative answer in **`lovata_orders_shopaholic_orders.site_id`** (column
exists in stock Lovata schema, populated at order create by upstream `MakeOrder`).

Production check:
```sql
SHOW COLUMNS FROM lovata_orders_shopaholic_orders LIKE 'site_id';
-- site_id  int  YES (nullable)  NULL
SELECT id, site_id FROM lovata_orders_shopaholic_orders WHERE id IN (29802, 29803);
-- 29802 | 1
-- 29803 | 1
```

Both rows already have `site_id=1` from t=0 (order create on `/lv/checkout`).
Plugin must consult that column for any Order-scoped event_log write or read.

## Fix Contract (LOCKED — no replanning during execute)

### REFAC-12 — `SiteResolver::forOrder` becomes the canonical Order-scoped resolver

```php
final class SiteResolver
{
    /**
     * Read the site_id captured by Lovata's MakeOrder at order-create time.
     * Returns null on single-site installs (column nullable) or when the Order
     * predates the multi-site rollout. Unlike getActiveSiteId(), this is
     * deterministic across request contexts — writer (admin/queue) and reader
     * (frontend) resolve to the same value for the same Order.
     */
    public static function forOrder(Order $obOrder): ?int
    {
        $mId = $obOrder->getAttribute('site_id');
        return is_numeric($mId) ? (int) $mId : null;
    }

    // getActiveSiteId() stays — used by future non-Order subjects (Lead form, etc.)
    public static function getActiveSiteId(): ?int { /* unchanged */ }
}
```

### REFAC-13 — Rewire every Order-scoped SiteResolver call site

| File | Method | Before | After |
|---|---|---|---|
| `classes/event/OrderStatusWatcher.php` | `alreadyDispatched(Order $obOrder)` | `SiteResolver::getActiveSiteId()` | `SiteResolver::forOrder($obOrder)` |
| `classes/queue/SendCapiEvent.php` | `raceFenceWon()` → indirect via writer | `EventLogWriter::record` reads SiteResolver internally | EventLogWriter signature gains `?int $iSiteId` from caller; SendCapiEvent passes `SiteResolver::forOrder($this->obSubject)` |
| `classes/helper/EventLogWriter.php` | `record(...)` | reads `SiteResolver::getActiveSiteId()` inside | accepts `?int $iSiteId` parameter, no internal SiteResolver call (DRY — caller decides) |
| `components/PurchasePixel.php` | `findEventLogRow(Order $obOrder, string $sChannel)` | `SiteResolver::getActiveSiteId()` | `SiteResolver::forOrder($obOrder)` |

**DRY constraint:** `EventLogWriter::record` no longer reads SiteResolver itself.
Caller passes resolved `site_id`. This makes the writer a pure I/O primitive
(SRP: persistence only; resolution policy lives at call sites).

**SRP constraint:** `SiteResolver` does NOT know how Orders persist their site.
It calls `$obOrder->getAttribute('site_id')` as a black-box accessor. If Lovata
changes the storage location, only `SiteResolver::forOrder` changes — every
caller stays put.

### REFAC-14 — Backfill the 2 stranded production rows (one-shot SQL)

```sql
-- Run on each affected site BEFORE v1.1.1 deploys; safe pre-deploy because
-- the column-fence is the only consumer and it was already missing the rows.
UPDATE logingrupa_metapixel_event_log el
JOIN lovata_orders_shopaholic_orders o ON o.id = el.subject_id
   AND el.subject_type = 'Lovata\\OrdersShopaholic\\Models\\Order'
SET el.site_id = o.site_id
WHERE el.site_id IS NULL
  AND o.site_id IS NOT NULL;
```

After this, the 2 staging orders' Pixel onRun gate passes immediately
(no code deploy needed for the band-aid). Code rewire ships in v1.1.1.

## Out of Scope (do NOT touch in this plan)

- Lovata's `lovata_orders_shopaholic_orders.site_id` column (READ-only — upstream owns it)
- `system_site_definitions` table or SiteManager wiring
- Non-Order subjects (Lead, AddToCart, ViewContent) — Phase 4 problem
- `EventLog` model schema — no new columns
- Migrations to plugin-owned tables — none needed
- Phase 4 funnel events

## Test Surface (TDD-mandated — RED commits before GREEN)

| Test class | Methods | Asserts |
|---|---|---|
| `tests/Unit/SiteResolverTest.php` (new) | `test_for_order_returns_int_when_order_has_site_id` | Order fixture `site_id=2` → `SiteResolver::forOrder` returns int `2` |
|  | `test_for_order_returns_null_when_order_site_id_null` | Order fixture `site_id=null` → `SiteResolver::forOrder` returns `null` |
|  | `test_for_order_returns_null_when_attribute_non_numeric` | force string `'banana'` → `null` (defensive narrow) |
| `tests/Feature/MultiSiteCrossContextTest.php` (new) | `test_admin_flip_with_null_active_site_writes_capi_using_order_site_id` | `Config::set('system.active_site', null)`; `$obOrder->site_id = 1`; dispatch via Watcher; assert event_log row `site_id === 1` |
|  | `test_frontend_pixel_reads_capi_row_via_order_site_id_when_active_site_diverges` | seed CAPI row with `site_id=1`; `Config::set('system.active_site', 1)`; PurchasePixel onRun → arMetaEvent populated |
|  | `test_cross_context_pair_round_trips_for_single_site_install` | Order `site_id=null`; SiteManager null; full Watcher → Writer → Pixel chain; both rows have `site_id=null`; UNIQUE NULL-distinct preserved |
| `tests/Feature/SendCapiEventEventLogTest.php` (extend) | `test_writer_called_with_resolved_site_id_from_caller` | mock EventLogWriter::record signature change; assert SendCapiEvent passes `SiteResolver::forOrder` value not `getActiveSiteId` value |
| `tests/Feature/OrderStatusWatcherEventLogTest.php` (extend) | same shape | Watcher's `alreadyDispatched` queries via `forOrder` |
| `tests/Feature/PurchasePixelEventLogGateTest.php` (extend) | `test_findEventLogRow_uses_order_site_id_not_active_site` | seed row site_id=2; force `Config::set('system.active_site', 1)`; component onRun finds row via `forOrder` → arMetaEvent populated |
| `tests/Feature/MultiSiteEventLogTest.php` (existing — adjust) | existing methods | swap `Config::set('system.active_site', $i)` → set `$obOrder->site_id = $i` so the test exercises the new contract |

**RED commit gate (MVP-TDD discipline from Phase 3.1):**
Each behavior-adding task MUST land its failing test first as
`test(03.1-07): RED — {method name}` BEFORE the production-code commit that
turns it green. Plan-checker will trip if RED commits are missing.

## Success Criteria

- [ ] `vendor/bin/pest` — all new + existing tests green (zero skip, zero error)
- [ ] `composer qa` — pint-test, analyse (phpstan level 10), phpmd, pest all exit 0
- [ ] Zero `SiteResolver::getActiveSiteId()` call sites that operate on a known `Order` (grep gate: `! grep -rE 'SiteResolver::getActiveSiteId\(\)' classes/ components/ | grep -v helper/SiteResolver.php`)
- [ ] `EventLogWriter::record` signature carries `?int $iSiteId` as explicit parameter — no internal SiteResolver call (grep gate: `! grep -E 'SiteResolver::' classes/helper/EventLogWriter.php`)
- [ ] One backfill SQL block committed at `.planning/phases/03.1-07-multi-site-site-id-symmetry/BACKFILL.sql` with a header docblock explaining preconditions
- [ ] `STAGING-RUNBOOK.md` addendum: Scenario 5 = "admin flip on /back then customer revisits /lv/checkout/{slug} — Browser + Server pair appears in Test Events"
- [ ] Plugin version bumps `1.1.0 → 1.1.1` in `updates/version.yaml` (patch — no schema, no public-API break: EventLogWriter signature gains an OPTIONAL param to stay backwards compatible during the transition; old callers fall back to `getActiveSiteId()` for one release window)
- [ ] STATE.md status advances `phase-3.1-runtime-verified` → `phase-3.1-cross-context-verified`
- [ ] ROADMAP.md Phase 3.1 plan list appends `03.1-07-PLAN.md`
- [ ] Each task has at least one atomic commit; conventional-commits prefix mandatory

## Commit Style (match Phase 3.1)

```
test(03.1-07): RED — MultiSiteCrossContextTest admin-flip cross-context
fix(03.1-07): SiteResolver::forOrder reads Order.site_id (REFAC-12)
refactor(03.1-07): EventLogWriter::record accepts explicit ?int site_id (DRY)
refactor(03.1-07): rewire Watcher + SendCapiEvent + PurchasePixel via forOrder (REFAC-13)
docs(03.1-07): BACKFILL.sql + STAGING-RUNBOOK addendum (REFAC-14)
chore(03.1-07): bump version.yaml 1.1.0 → 1.1.1
docs(03.1-07): STATE + ROADMAP closure
docs(03.1-07): SUMMARY.md — cross-context bug closed
```

## Constraints (Tiger-Style — non-negotiable)

- Hungarian notation on every local variable (`$obOrder`, `$iSiteId`, `$bWon`, `$mxValue`)
- Methods < 70 LOC; split if larger
- Explicit return types everywhere (`: ?int`, `: bool`, `: void`)
- Zero `assert(...)` — spaze plugin invariant
- Every catch logs + rethrows OR carries explicit `// silent: <reason>` comment
- Integration tests over unit (existing MetapixelTestCase + OrderFixtures + MockHandler patterns)
- Deterministic time freeze: `Carbon::setTestNow(Carbon::createFromTimestamp(1715000000))` in setUp
- No production-code touch outside the listed 4 files + version.yaml
- Backwards compatibility: `EventLogWriter::record` signature gains the new `?int $iSiteId` as OPTIONAL parameter with default `null`; old call sites unaffected during the wave; rewire tasks update them in lock-step within the same plan

## Threat Model Delta

| Threat | Mitigation |
|---|---|
| Order.site_id tampered post-create | Lovata's column is admin-protected; plugin treats as read-only authoritative source |
| Single-site → multi-site migration mid-flight | `SiteResolver::forOrder` returns null for orders with null site_id; downstream UNIQUE NULL-distinct semantics still hold; backfill SQL runs once per site post-migration if needed |
| Mock Order in tests omits site_id attribute | `getAttribute('site_id')` returns null → `forOrder` returns null → behavior is the single-site path; tests pass without explicit set |

## References

- `.planning/phases/03.1-event-log-refactor/BRIEF.md` — REFAC-04 multi-site contract (original)
- `.planning/phases/03.1-event-log-refactor/03.1-04-SUMMARY.md` — PurchasePixel rewrite (Phase 3.1 Wave 3)
- `.planning/phases/03.1-event-log-refactor/03.1-05-SUMMARY.md` — multi-site test (Phase 3.1 Wave 4)
- `.planning/phases/03.1-event-log-refactor/03.1-06-SUMMARY.md` — staging-checkpoint automation (Phase 3.1 Wave 5)
- Production incident 2026-05-14: orders 29802 + 29803 on `new.nailscosmetics.lv` — Pixel never rendered on `/lv/checkout/{secret_key}`; CAPI sent to Meta under `test_event_code=TEST6581` but browser side silent.
