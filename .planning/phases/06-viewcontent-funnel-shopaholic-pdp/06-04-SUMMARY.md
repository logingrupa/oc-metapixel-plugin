---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 04
subsystem: adapter/shopaholic
tags: [adapter, viewcontent, shopaholic, phpstan-allowlist, contract-test, hybrid-ajax]
requires:
  - 06-01 (RED stubs for ProductValueResolverTest + ProductAdapterContractTest)
  - 06-03 (SupportsHybridAjax marker subinterface)
provides:
  - ShopaholicProductAdapter — opaque alias 'shopaholic.product', SupportsHybridAjax (loadSubject re-enforces Product::active + site-match)
  - ShopaholicProductValueResolver — D-5 SKU shape, D-10 default offer, CurrencyHelper → Settings → throw chain
  - phpstan.neon allowlist coverage for ShopaholicProductAdapter D-15 site fallback (4 deny-list rules)
  - 10 GREEN resolver test cases (1 DataProvider × 4 + 6 standalone)
  - 10 GREEN contract test invariants (Phase 2 EventSubjectAdapterContractTestCase proof)
affects:
  - 06-05 (ProductPageWatcher GREEN can now dispatch via ShopaholicProductAdapter)
  - 06-06 (ThemeAjaxHandler hybrid AJAX path can now resolve subject_type='shopaholic.product')
tech-stack:
  added: []
  patterns:
    - "Reflection-stubbed Singleton (CurrencyHelper) via newInstanceWithoutConstructor + obActiveCurrency stdClass pin — sidesteps final protected __construct + init() DB read"
    - "fSavedPrice Reflection injection on Offer — bypasses getPriceValueAttribute's lovata_shopaholic_prices DB read in hermetic tests"
    - "MultisiteHelperTrait pivot fallback (D-15 third documented exception) — ShopaholicProductAdapter joins ThemeActionAdapter + ShopaholicCartPositionAdapter as PHPStan-allowlisted site-context readers"
key-files:
  created:
    - "classes/adapter/shopaholic/ShopaholicProductAdapter.php"
    - "classes/adapter/shopaholic/ShopaholicProductValueResolver.php"
  modified:
    - "phpstan.neon"
    - "tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php"
    - "tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php"
decisions:
  - "D-15 third documented exception applied — ShopaholicProductAdapter.php added to all 4 PHPStan deny-list allowIn blocks alongside ShopaholicCartPositionAdapter.php (request(), SiteManager::*, Site::*, Request::*)"
  - "OrderHasNoCurrencyException reused per D-discretion — no caller branches on exception type; minting ProductHasNoCurrencyException would add a class with zero behavioral delta"
  - "Site-match check in loadSubject reads $obProduct->site_list as the authoritative trust boundary (T-6-05 mitigation against cross-site subject_id spoofing)"
  - "Contract test stubs CurrencyHelper singleton at setUp time (not at invariant runtime) so resolveCurrency in invariant 10 stays hermetic without provisioning lovata_shopaholic_currency or wiring auth.helper container binding"
metrics:
  duration_seconds: 737
  duration_minutes: 12
  tasks_completed: 4
  tasks_total: 4
  files_created: 2
  files_modified: 3
  lines_added: 625
  lines_removed: 26
  tests_passing: 22  # 10 resolver + 10 contract + 2 PurchaseFlow regression cell
  completed: 2026-05-28
---

# Phase 06 Plan 04: ShopaholicProductAdapter + ValueResolver Summary

ShopaholicProductAdapter + ShopaholicProductValueResolver shipped under `classes/adapter/shopaholic/`. Adapter implements `SupportsHybridAjax` with subject_type alias `'shopaholic.product'`, D-15 site fallback (third documented PHPStan deny-list exception alongside ThemeActionAdapter + ShopaholicCartPositionAdapter), and `loadSubject` guards re-enforcing `Product::active()->find` + site-match (T-6-05 mitigation against cross-site subject_id spoofing). Resolver enforces D-5 SKU format + D-10 default-offer logic. Resolver test 10 cases GREEN; contract test 10 invariants GREEN — every Shopaholic adapter in the plugin (Order + CartPosition + Product) now ships with its Phase 2 contract proof.

## Tasks Completed

| # | Task | Commit | Files | Verification |
|---|------|--------|-------|--------------|
| 1 | ShopaholicProductAdapter with SupportsHybridAjax + D-15 site fallback | `90540cc` | `classes/adapter/shopaholic/ShopaholicProductAdapter.php` | `php -l` clean; phpstan deferred to Task 3 (expected RED until allowlist) |
| 2 | ShopaholicProductValueResolver with D-5 SKU + D-10 default offer + currency chain | `6feae45` | `classes/adapter/shopaholic/ShopaholicProductValueResolver.php` | phpstan L10 clean |
| 3 | Extend phpstan.neon allowlist (4 deny-list rules) + GREEN ShopaholicProductValueResolverTest | `17addee` | `phpstan.neon`, `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php`, `classes/adapter/shopaholic/ShopaholicProductAdapter.php` (PHPStan safety tweak in `getSiteId`) | phpstan L10 clean on both adapter files; 10 resolver test cases GREEN |
| 4 | GREEN ShopaholicProductAdapterContractTest (10 invariants) | `d420cc3` | `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` | 10 inherited invariants GREEN; all 50 Phase 2/3 contract tests across all adapters still GREEN; `--exclude-group=adapter` correctly skips |

## Requirements Closed

- **VIEW-02** — ShopaholicProductAdapter contract proof shipped (10 inherited invariants GREEN)
- **VIEW-03** — ShopaholicProductValueResolver D-5 SKU + D-10 default-offer + currency chain shipped, 10 test cases GREEN
- **VIEW-08** — phpstan.neon allowlist extended on 4 deny-list rules for ShopaholicProductAdapter.php (D-15 third documented exception)

## Threats Mitigated

| ID | Disposition | Outcome |
|----|-------------|---------|
| **T-6-02** Information Disclosure: getSiteId reading from Request/SiteManager outside disallowed scope | mitigated | D-15 pattern — read `$obProduct->site_list` pivot first; fallback to `Site::getSiteIdFromContext()` only when multi-site. Adapter file added to PHPStan allowIn lists in Task 3. Invariant 04 (`get_site_id_reads_no_request_or_site_manager`) is the runtime gate proving the adapter respects D-15. |
| **T-6-05** Spoofing / Information Disclosure: offer-switch JS posts a forged `subject_id` for cross-site product | mitigated | `loadSubject` re-enforces `Product::active()->find($iSubjectId)` (active scope + SoftDelete excluded) AND site-relation match against `Site::getSiteIdFromContext()`. Adapter test in plan 06-06 (ThemeAjaxHandlerSubjectTypeTest item 5 — loadSubject returns null → 404) is the integration gate. |
| **T-6-W4-T** Tampering: `Settings::get('default_currency_code')` returning non-string injected value | mitigated | Runtime `is_string($mValue) ? ... : ''` guard applied per PHPStan level-10 mixed-cast pattern; no `@phpstan-ignore` (project ban). Same idiom mirrored from `PluginGuard::isDisabled`. |
| **T-6-W4-C** Tampering: future regression breaks one of the 10 Phase 2 invariants on the Product adapter | mitigated | Task 4 ships `ShopaholicProductAdapterContractTest` — `pest --group=adapter` runs the inherited 10 invariants. Any regression fails CI before merge. Same gate that protects ShopaholicOrderAdapter + ShopaholicCartPositionAdapter today. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — PHPStan L10 mixed-cast safety in ShopaholicProductAdapter::getSiteId]**
- **Found during:** Task 3 (after phpstan allowlist extension)
- **Issue:** Original Task 1 implementation cast `$mSiteList[0]` directly to `(int)`; PHPStan L10 flagged `cast.int` because the array entry is `mixed`.
- **Fix:** Narrowed via `reset($mSiteList)` then `is_numeric($mFirst)` runtime guard before the cast — matches the plugin's established mixed-cast pattern.
- **Files modified:** `classes/adapter/shopaholic/ShopaholicProductAdapter.php`
- **Commit:** `17addee`

**2. [Rule 3 — Hermetic-test stubbing of CurrencyHelper singleton]**
- **Found during:** Task 3 (resolver test) + Task 4 (contract test)
- **Issue:** `CurrencyHelper::instance()` triggers `init()` on first call, which reads `lovata_shopaholic_currency` AND `CookieUserStorage` (requires `auth.helper` container binding not provided by `MetapixelTestCase`).
- **Fix:** Stub the singleton via `ReflectionClass::newInstanceWithoutConstructor` + `ReflectionProperty` pin of `$obActiveCurrency` to a stdClass with a `code` property. Avoids both the DB read and the container binding. Resets via `CurrencyHelper::forgetInstance()` in tearDown.
- **Why not via App container binding:** `Singleton::instance()` reads the static property directly — `App::singleton()` does not intercept the call.
- **Why not via subclass + override:** `Singleton` declares `final protected __construct()` — anonymous classes cannot redeclare it.
- **Files modified:** `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php`, `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php`
- **Commits:** `17addee` (resolver test), `d420cc3` (contract test)

**3. [Rule 3 — Reflection injection of `Offer::$fSavedPrice` in hermetic test]**
- **Found during:** Task 3
- **Issue:** `Offer::getPriceValueAttribute()` queries `lovata_shopaholic_prices` when `fSavedPrice` is null; setting `'price_value'` via `setAttribute()` does NOT populate `fSavedPrice` (no public setter — only the documented `setPriceAttribute(...)` mutator sets it).
- **Fix:** Inject the fixture price directly into the protected `fSavedPrice` field via `ReflectionProperty`.
- **Files modified:** `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php`
- **Commit:** `17addee`

**4. [Rule 3 — Contract test schema bootstrap completeness]**
- **Found during:** Task 4
- **Issue:** Initial bootstrap missed `softDeletes` on `lovata_shopaholic_offers` (Offer's SoftDelete trait queries `deleted_at IS NULL`) AND missed `system_site_definitions` (Product's `site` belongsToMany joins through it for `$site_list` accessor).
- **Fix:** Added `$obTable->softDeletes()` to `bootOffersTable`; added `bootSystemSiteDefinitionsTable` helper with a single site row (id=1, primary, enabled).
- **Files modified:** `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php`
- **Commit:** `d420cc3`

### Deferred (out of scope)

- **`composer deps` runtime verification** — composer-dependency-analyser binary not installed in this environment. Files live under `classes/adapter/shopaholic/*` which IS in the Lovata-import allowlist (composer-dependency-analyser.php lines 31-34); only `Lovata\Shopaholic\Models\Product`, `Lovata\Shopaholic\Models\Offer`, `Lovata\Shopaholic\Classes\Helper\CurrencyHelper` are imported — all standard Shopaholic-scoped types. CI will exercise this gate.

## Verification Evidence

```
# Task 1 + Task 2 + Task 3
$ vendor/bin/phpstan analyse classes/adapter/shopaholic/ShopaholicProductAdapter.php \
    classes/adapter/shopaholic/ShopaholicProductValueResolver.php --level=10 --no-progress
 [OK] No errors

# Task 3 resolver test
$ vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php --no-coverage
  Tests:    10 passed (13 assertions)
  Duration: 0.49s

# Task 4 contract test
$ vendor/bin/pest tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php --no-coverage
  Tests:    10 passed (50 assertions)
  Duration: 0.54s

# Full regression: all contract tests (Order + CartPosition + Product + Theme + Fake)
$ vendor/bin/pest tests/Contract/Adapter --no-coverage
  Tests:    50 passed (318 assertions)
  Duration: 1.92s

# Minimal-install isolation: --exclude-group=adapter SKIPS the file
$ vendor/bin/pest tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php \
    --exclude-group=adapter --no-coverage
  No tests found.

# Existing PurchaseFlow + resolver regression
$ vendor/bin/pest tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php \
    tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php --no-coverage
  Tests:    12 passed (42 assertions)
  Duration: 0.76s

# phpstan.neon allowlist entry count
$ grep -c "ShopaholicProductAdapter.php" phpstan.neon
8   # 4 allowIn entries + 4 explanatory comments (matches existing CartPosition pattern)
```

## Unblocks

- **Plan 06-05** (ProductPageWatcher) — GREEN tests in plan 06-05 can now drive the dispatch path via `ShopaholicProductAdapter` (subject_type='shopaholic.product') and the `ShopaholicProductValueResolver` price/SKU chain.
- **Plan 06-06** (ThemeAjaxHandler hybrid AJAX) — `subject_type='shopaholic.product'` registry lookup → `ShopaholicProductAdapter::loadSubject(int, array)` is now wireable.

## Self-Check: PASSED

- All 5 files found at expected paths.
- All 4 task commits present in `git log --all`: `90540cc`, `6feae45`, `17addee`, `d420cc3`.
- 22 directly-verified tests GREEN (10 resolver + 10 contract + 2 PurchaseFlow regression).
- Full Phase 2/3 contract suite (50 tests, 318 assertions) GREEN — no regression.
- Live plugin dir restored to a clean state (sync artifacts removed).
