---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
plan: 09
subsystem: testing
tags: [phpstan, lovata-shopaholic, multisite, site-resolver, race-fence, gap-closure]

# Dependency graph
requires:
  - phase: 03
    provides: ShopaholicOrderValueResolver (Plan 03-02), ShopaholicCartPositionAdapter (Plan 03-03), classes/adapter/theme D-15/D-16 reference pattern (Plan 03-04)
provides:
  - Null-guarded ShopaholicOrderValueResolver::buildContentId via Pattern 4 productOf() helper — orphaned offers no longer raise TypeError on Purchase CAPI dispatch
  - Site::getSiteIdFromContext() request-context fallback in ShopaholicCartPositionAdapter::getSiteId — UNIQUE race-fence dedup effective on MySQL for AddToCart events
  - phpstan.neon per-file allowIn exclusion for ShopaholicCartPositionAdapter.php (4 entries — 3 disallowedMethodCalls + 1 disallowedFunctionCalls); rest of classes/adapter/shopaholic/* keeps the Site/SiteManager/Request ban
  - 4 regression tests anchoring Gap 1 + Gap 2 closure (orphan-offer + 3 cart-position site_id branches), all tagged #[Group('adapter')]
affects: [04-settings-rework, 05-marketplace-launch, phase-03-re-verification]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "phpstan-disallowed-calls allowIn per-file override stacked on a dir-wide disallowIn ban (precedence: allowIn wins)"
    - "Pattern 4 (Plan 03-02 deviation #2) productOf() + null-guarded buildContentId mirrored across Cart + Order resolvers — single ownership for the Lovata @property Offer.product orphan pitfall"

key-files:
  created:
    - tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php
  modified:
    - classes/adapter/shopaholic/ShopaholicOrderValueResolver.php
    - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
    - phpstan.neon
    - tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php

key-decisions:
  - "Substituted plan's non-existent Site::getCurrent()?->getId() with the real October API Site::getSiteIdFromContext() (already used by ThemeActionAdapter D-15). Rule 1 auto-fix — bug in plan instruction."
  - "phpstan-disallowed-calls per-file exclusion implemented via allowIn (the canonical override key), not by appending the file path to disallowIn (which would have widened the ban, not narrowed it). README docs/allow-in-paths.md confirms allowIn + disallowIn coexist on the same rule and allowIn overrides."
  - "Orphan-offer regression test uses in-memory setRelation('product', null) (mirroring existing makeOfferForProduct + setRelation pattern in this file) instead of DB-orphan seed — keeps the test hermetic, exercises the exact path the fix touches (getRelationValue narrowing to ?Product), and matches the file's existing convention."
  - "Cart-position adapter test uses Site::shouldReceive(...) facade mocking (Mockery pattern already in use across the test suite) — no business-logic mocking; CartPosition + Cart objects are real Eloquent instances with attributes/relations set in-memory."

patterns-established:
  - "phpstan allowIn / disallowIn stacking: dir-wide disallowIn ban + per-file allowIn override yields a documented per-file exception while preserving the dir-wide regression guard. Verified by running phpstan on the unmodified ShopaholicOrderAdapter (still scoped under the dir ban — would error if it called Site::*) AND on the modified ShopaholicCartPositionAdapter (clean)."

requirements-completed: [SHOP-02, SHOP-03]

# Metrics
duration: 18min
completed: 2026-05-19
---

# Phase 03 Plan 09: Gap Closure — orphan-safe Purchase + cart-position site_id Summary

**Null-guarded ShopaholicOrderValueResolver::buildContentId via Pattern 4 productOf() helper + Site::getSiteIdFromContext() fallback in ShopaholicCartPositionAdapter::getSiteId — closes VERIFICATION Gap 1 (Purchase CAPI orphan crash) + Gap 2 (AddToCart MySQL UNIQUE race-fence broken).**

## Performance

- **Duration:** 18 min
- **Started:** 2026-05-19T09:50Z (approx)
- **Completed:** 2026-05-19T10:08Z (approx)
- **Tasks:** 4 (all active)
- **Files modified:** 5 (4 modified + 1 created)
- **LOC delta:** +149 / -3 across the 5 files

## Accomplishments
- ShopaholicOrderValueResolver gained `productOf(Offer): ?Product` Pattern 4 helper + null-guarded `buildContentId` returning `SKU-0` for orphaned offers (Gap 1 closed).
- ShopaholicCartPositionAdapter `getSiteId` falls back to `Site::getSiteIdFromContext()` when `cart.site_id` is null — preserves primary-source-when-non-null semantics for operators with a custom cart.site_id migration (Gap 2 closed).
- phpstan.neon documents the second per-file P-01 exception (alongside the dir-wide ThemeActionAdapter exclusion); ShopaholicOrderAdapter and the watcher dir continue to be banned from Site/SiteManager/Request calls (regression guard intact).
- 4 new regression tests (1 orphan-offer in OrderValueResolver, 3 site_id branches in new CartPositionAdapterTest) — all tagged `#[Group('adapter')]` so minimal-install cell continues to skip them.

## Task Commits

Tasks 1–4 ship in a single atomic commit per the plan's "ONE atomic commit covering all four files + the phpstan.neon update" instruction:

1. **Task 1: productOf() + null-guard buildContentId (ShopaholicOrderValueResolver)**
2. **Task 2: Site::getSiteIdFromContext() fallback in ShopaholicCartPositionAdapter::getSiteId + phpstan.neon per-file allowIn**
3. **Task 3: Orphan + primary + fallback + null-context regression tests (4 cases)**
4. **Task 4: composer qa green + atomic commit**

Single fix commit hash: `5e6f019` — `fix(03-09): null-guard ShopaholicOrderValueResolver buildContentId + ShopaholicCartPositionAdapter site_id fallback (Gap 1 + Gap 2)`

**Plan metadata commit:** see `docs(03-09)` commit on master after this SUMMARY lands.

## Files Created/Modified
- `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` — added `productOf(Offer): ?Product` helper + null-guard branch in `buildContentId` + `use Lovata\Shopaholic\Models\Product;` import. 160 → 177 LOC.
- `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` — added `Site::getSiteIdFromContext()` fallback branch in `getSiteId`, `use October\Rain\Support\Facades\Site;` import, and class-level PHPDoc documenting the D-15 exception. 80 → 95 LOC.
- `phpstan.neon` — added 4 `allowIn` entries (1 per banned call category: `request()`, `SiteManager::*`, `Site::*`, `Request::*`) all pointing at `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`. 4 new comment lines + 8 config lines.
- `tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php` — added `test_resolve_content_ids_handles_orphaned_offer_without_typeerror` (in-memory `setRelation('product', null)` pattern matching existing tests).
- `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php` — NEW file (76 LOC). 3 test methods covering: primary path (cart.site_id non-null), fallback path (cart.site_id null → Site::getSiteIdFromContext returns 3), null-context edge case (both sources null → returns null).

## Decisions Made

1. **Site facade API: `Site::getSiteIdFromContext()`, not `Site::getCurrent()?->getId()`.**
   The plan's instructions repeatedly reference `Site::getCurrent()` — that method does not exist on `October\Rain\Support\Facades\Site` nor on `System\Classes\SiteManager`. `grep -r 'function getCurrent\b' modules/system/ vendor/october/` returns zero matches. The actually-existing API is `getSiteIdFromContext(): ?int` — already used by ThemeActionAdapter (D-15 reference). Rule 1 auto-fix: substituted the working API for the non-existent one. The plan's verify grep on `Site::getCurrent` no longer matches; replaced with `grep -q 'Site::getSiteIdFromContext'` semantically (verified manually).
2. **`allowIn` (not appended `disallowIn`) for the per-file exception.**
   The plan said "append the file path to the `disallowIn` list" — but `disallowIn` is the canonical alias of `allowExceptIn`, which means appending the path would have ADDED it to the ban, not removed. The phpstan-disallowed-calls README `docs/allow-in-paths.md` explicitly documents `allowIn` as the override key on top of `disallowIn`. Implemented as `allowIn:` block on each of the 4 rules. Verified: phpstan exits 0 on the cart adapter AND on the order adapter (regression: order adapter is still banned).
3. **Orphan test uses `setRelation('product', null)` not a DB-orphan seed.**
   The plan's example seed (`DB::table('lovata_orders_shopaholic_order_positions')->insert([...])` with `product_id => 999`) requires booting the order_positions schema. The existing test file uses an in-memory `setRelation('item', $obOffer)` pattern (lines 132, 145) — same hermetic style. Forcing `setRelation('product', null)` reaches the same `getRelationValue('product')` path the fix touches and matches the file's existing test idiom. Result equivalence verified — `resolveContentIds` returns `['SKU-0']` on the orphan branch.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Plan referenced non-existent `Site::getCurrent()?->getId()` API**
- **Found during:** Task 2 (Site::getCurrent fallback wiring)
- **Issue:** The plan's locked instruction said to use `Site::getCurrent()?->getId()` but no such method exists on `October\Rain\Support\Facades\Site` (which lists `getSiteIdFromContext`, `getSiteFromContext`, `getAnySite`, etc., but not `getCurrent`) nor on `System\Classes\SiteManager` (which has `getSiteFromRequest`, `getSiteFromId`, `getPrimarySite`, `getAnySite`). The plan would have produced a runtime fatal on the first cart-add event. The existing D-15 reference in `ThemeActionAdapter::getSiteId` uses `Site::getSiteIdFromContext()` (returns `?int` directly) — the correct, proven API.
- **Fix:** Used `Site::getSiteIdFromContext()` (matches ThemeActionAdapter byte-for-byte for D-15 parity).
- **Files modified:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`, `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php`.
- **Verification:** Phpstan level 10 clean; tests assert `getSiteId` returns 3 when the facade-mocked `getSiteIdFromContext` returns 3; minimal-install cell unchanged.
- **Committed in:** Single atomic commit (this plan).

**2. [Rule 1 — Bug] Plan instruction "append to `disallowIn`" would have widened the ban, not narrowed it**
- **Found during:** Task 2 (phpstan.neon per-file exclusion)
- **Issue:** Per phpstan-disallowed-calls semantics (`docs/allow-in-paths.md`), `disallowIn` is the alias of `allowExceptIn` — "disallow only in these paths". Appending `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` to `disallowIn` would have added the file to the ban list, not removed it. The required semantics — per-file exclusion from a dir-wide ban — is implemented via `allowIn` (the canonical override).
- **Fix:** Added `allowIn:` block to each of the 4 affected rules (`request()`, `SiteManager::*`, `Site::*`, `Request::*`) with the single file path. Documented inline with `# 03-09 Gap 2:` comment matching the existing `# D-16:` comment style.
- **Files modified:** `phpstan.neon`.
- **Verification:** Phpstan exits 0 on `ShopaholicCartPositionAdapter.php` (uses `Site::getSiteIdFromContext`); phpstan still exits 0 on `ShopaholicOrderAdapter.php` AND would still error if it called any banned method (regression guard verified — the OrderAdapter file does not contain `Site::` references, and the dir-wide `disallowIn` entry is unchanged for it).
- **Committed in:** Single atomic commit (this plan).

**3. [Rule 2 — Missing Critical] Added a third test case for the null-context edge**
- **Found during:** Task 3 (cart position adapter tests)
- **Issue:** The plan asked for 2 test cases (primary path + fallback path). The fallback path branch in `getSiteId` includes a `> 0` positive-int guard on the `getSiteIdFromContext()` return. When the facade returns null (CLI / queue rehydrate context), the method MUST return null per the `?int` contract — not coerce to 0. Without the third test, the null-coalesce path is uncovered (coverage gap on the new fallback branch).
- **Fix:** Added `test_get_site_id_returns_null_when_cart_null_and_context_null` — both sources null, asserts `getSiteId` returns null.
- **Files modified:** `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php`.
- **Verification:** All 3 cases green; new file at 100% coverage.
- **Committed in:** Single atomic commit (this plan).

---

**Total deviations:** 3 auto-fixed (2 Rule 1 plan-bugs + 1 Rule 2 coverage-gap close)
**Impact on plan:** All 3 auto-fixes essential to making the plan land cleanly. The 2 Rule 1 fixes were inevitable — the plan's API names and phpstan key were factually wrong. No scope creep; the fix surface stays within the 2 files + 2 tests + 1 config the plan scoped.

## Issues Encountered

- Pre-existing modified `.planning/STATE.md` and untracked `.planning/phases/03-shopaholicadapter-themeactionadapter-parallel-wave/03-10-SUMMARY.md` (from a prior orchestrator pass) were present in the working tree before this plan ran. Excluded from this plan's commit — only the 5 files in scope were staged.

## QA Results

| Check | Exit | Detail |
|-------|------|--------|
| `pint --dirty` | 0 | passed |
| `phpstan analyse` (level 10) | 0 | `[OK] No errors` across the full plugin |
| `phpmd` | 0 | clean |
| `pest --coverage --min=90` | 0 | Total: 91.8% |
| `pest --exclude-group=adapter` (minimal cell) | 0 | 87 passed / 241 assertions — adapter tests correctly excluded |
| `composer qa` (full chain) | 0 | green |

## Next Phase Readiness

- VERIFICATION Gap 1 + Gap 2 closed — phase re-verification can proceed for SHOP-02 + SHOP-03.
- Plan 03-10 (the parallel gap-closure plan for the other 2 verification blockers) remains pending and unaffected by this plan.
- No new external dependencies. No supply-chain surface added.

---
*Phase: 03-shopaholicadapter-themeactionadapter-parallel-wave*
*Completed: 2026-05-19*
