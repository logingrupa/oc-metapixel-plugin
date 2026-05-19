---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
verified: 2026-05-19T00:00:00Z
status: passed
score: 12/12
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 8/12
  gaps_closed:
    - "content_ids format SKU-{product_id}[-{offer_id}] is safe when offer.product is missing"
    - "CartPosition AddToCart events record correct site_id, enabling the EventLog UNIQUE race-fence"
    - "Settings::beforeSave() stores theme_custom_event_names as a string the textarea widget can round-trip"
    - "Settings UI labels render human-readable English text (not raw lang key strings)"
  gaps_remaining: []
  regressions: []
  closure_commits:
    - "5e6f019 fix(03-09): null-guard ShopaholicOrderValueResolver buildContentId + ShopaholicCartPositionAdapter site_id fallback (Gap 1 + Gap 2)"
    - "dda1fc1 fix(03-10): persist theme_custom_event_names as newline-joined string (Gap 3)"
    - "d0f9551 fix(03-10): add six missing settings.fields lang keys (Gap 4)"
    - "b273878 test(03-10): add round-trip + lang key resolution regression tests (Gap 3 + Gap 4)"
---

# Phase 03: ShopaholicAdapter + ThemeActionAdapter Verification Report

**Phase Goal:** Deliver ShopaholicOrderAdapter, ShopaholicCartPositionAdapter, ThemeActionAdapter, ThemeEventCollector, ThemeAjaxHandler, EventPixel, and PixelHead — all wired, tested, and QA-clean — so the plugin can fire Purchase + AddToCart CAPI events from the Shopaholic order lifecycle and arbitrary theme-triggered browser pixel events via Twig dot-notation.

**Verified:** 2026-05-19
**Status:** PASSED
**Re-verification:** Yes — after gap closure (Plans 03-09 + 03-10)

---

## Re-Verification Summary

| Item                | Previous (2026-05-18) | Current (2026-05-19)           |
| ------------------- | --------------------- | ------------------------------ |
| Status              | gaps_found            | passed                         |
| Score               | 8/12                  | 12/12                          |
| Gaps                | 4 blockers            | 0                              |
| composer qa         | (n/a)                 | green (pint + phpstan L10 + phpmd + pest --coverage=91.8%) |
| Tests passing       | (n/a)                 | 261 (full) / 87 (minimal-install cell) |
| Regressions on previously-passing items | -    | none                           |

**All 4 prior gaps closed; no new gaps introduced; no regression on the 8 previously-verified must-haves.**

---

## Goal Achievement

### Observable Truths

| # | Requirement | Truth | Status | Evidence |
|---|-------------|-------|--------|----------|
| 1 | SHOP-01 | ShopaholicOrderAdapter registered with alias `shopaholic.order`, getSiteId reads only Order.site_id, getSupportedEvents returns `['Purchase' => ['capi','pixel']]` | VERIFIED | `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` — SUBJECT_TYPE='shopaholic.order' (line 15), getSiteId reads `$obOrder->getAttribute('site_id')` only (lines 32-37), SUPPORTED_EVENTS const ['Purchase' => ['capi','pixel']] (line 18) |
| 2 | SHOP-02 | content_ids format `SKU-{product_id}[-{offer_id}]` handles deleted/orphaned product without TypeError | **VERIFIED (gap closed)** | `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` lines 131-149 — productOf() helper (getRelationValue + instanceof Product narrowing) + buildContentId null-guard returns `sprintf('SKU-%d', 0)`. Byte-for-byte parity with ShopaholicCartPositionValueResolver::productOf (Pattern 4). Regression test `test_resolve_content_ids_handles_orphaned_offer_without_typeerror` (line 159) passes. |
| 3 | SHOP-03 | OrderStatusWatcher dispatches Purchase on paid status change; CartPositionAdapter AddToCart records correct site_id for UNIQUE race-fence | **VERIFIED (gap closed)** | `classes/event/adapter/shopaholic/OrderStatusWatcher.php` — eloquent bindings + wasChanged guard + dispatch + Tiger-style catch confirmed. `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` lines 44-55 — getSiteId now falls back to `Site::getSiteIdFromContext()` after the cart.site_id branch returns null (D-15-style exception, second documented P-01 exception). Two new regression tests prove both branches: `test_get_site_id_returns_cart_site_id_when_non_null_primary_source` (line 27) + `test_get_site_id_returns_non_null_via_site_get_site_id_from_context_fallback` (line 44). |
| 4 | SHOP-04 | Shopaholic adapter boots only when OrdersShopaholic plugin is active (PluginManager gate) | VERIFIED | `Plugin.php` lines 125-131 — `isShopaholicEnabled()` via `App::make(PluginManager::class)->exists('Lovata.OrdersShopaholic')`; conditional subscriber + AdapterRegistry::register block at lines 63-69. |
| 5 | SHOP-05 | End-to-end integration test proves Purchase flow: order created, status changed, CAPI HTTP call recorded, EventLog row written, dedup fence blocks second dispatch | VERIFIED | `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` — MockHandler + Middleware::history + sync queue + EventLog assertions; 2 tests, 29 assertions, all pass. |
| 6 | THEM-01 | ThemeActionEvent is an immutable value object (readonly properties or constructor-assigned, no setters) with synthetic subject_id via crc32 | VERIFIED | `classes/adapter/theme/ThemeActionEvent.php` lines 13-46 — readonly constructor properties (`public readonly string $sActionKey`, `public readonly int $iSyntheticId`, `public readonly string $sEventName`, `public readonly array $arPayload`); `crc32($mActionKey)` synthetic id at line 42. |
| 7 | THEM-02 | ThemeActionAdapter satisfies all 10 EventSubjectAdapter contract invariants including 18-event getSupportedEvents and phpstan D-16 exemption | VERIFIED | `classes/adapter/theme/ThemeActionAdapter.php` — SUBJECT_TYPE='theme.action', 18-event SUPPORTED_EVENTS map (lines 28-47), Site::getSiteIdFromContext fallback at line 76. phpstan.neon disallowIn excludes `classes/adapter/theme/*` for Site/SiteManager/Request/request() (lines 56-101). |
| 8 | THEM-03 | ThemeEventCollector is an app-singleton with push/pushEvent/flush/count; Twig dot-notation `this.metapixel.pushEvent({...})` works | VERIFIED | `classes/adapter/theme/ThemeEventCollector.php` — 55 LOC; push(), pushEvent(), flush(), count() all confirmed (lines 19-54). Plugin.php register() binds singleton at line 57; boot() mounts via `cms.page.beforeRenderPage` into `$mThis->config['metapixel']` (lines 71-77). |
| 9 | THEM-04 | Twig access is via dot-notation only (`this.metapixel`); no bare Twig function registered | VERIFIED | `Plugin::registerMarkupTags()` returns `['functions' => [], 'filters' => []]` (lines 111-117) — no bare functions; config-key mount via cms.page.beforeRenderPage is the only Twig surface. |
| 10 | THEM-05 | ThemeAjaxHandler P-09 defence: validates event name against META_STANDARD + allowlist, rate-limits, JS-escapes; Settings beforeSave sanitises and stores theme_custom_event_names round-trippable | **VERIFIED (gap closed)** | ThemeAjaxHandler P-09 verified (META_STANDARD list, allowlist join via Settings::getThemeCustomEventNames, RateLimiter check, JSON_HEX_TAG flags). `models/Settings.php` line 65 — `setAttribute('theme_custom_event_names', implode("\n", $arClean))` persists newline-joined STRING (not PHP array); `getThemeCustomEventNames()` lines 123-145 explodes via `preg_split('/\R/', ...)` + regex filter; tolerates legacy array shape for one-shot migration. Round-trip regression test `test_theme_custom_event_names_round_trips_through_textarea` (line 93) passes. |
| 11 | THEM-06 | EventPixel reads EventLog via direct DB query (D-09, no AdapterRegistry at render), validates event_id against CAPI row, race-fences pixel-fired column with insertOrIgnore | VERIFIED | `components/EventPixel.php` — `DB::table('logingrupa_metapixel_event_log')` direct (line 133, 150), onMarkFired validates `$arCapiRow['event_id'] === $sServerEventId` (line 93), `insertOrIgnore` at line 198. |
| 12 | THEM-07 | PixelHead flushes ThemeEventCollector, renders pixel blocks, mirrors to CAPI, has exception fixture; phpunit.xml covers components/ and console/ directories | **VERIFIED (gap closed)** | `components/PixelHead.php` — `App::make(ThemeEventCollector::class)->flush()` at line 43, fbq script rendering loop (line 44-69), Tiger-style catch on `dispatchCapiMirror` (lines 60-67). `tests/fixtures/components/PixelHeadExceptionFixture.php` exists (LSP-correct protected override). `phpunit.xml` source includes `./components` (line 26) + `./console` (line 25). Six previously-missing settings.fields lang keys now present in `lang/en/lang.php` lines 19-24 with human-readable English strings — Settings UI is operator-configurable. Lang key resolution test `test_settings_fields_lang_keys_resolve_to_human_readable_strings` (line 110) passes. |

**Score: 12/12 truths VERIFIED** (4 gap closures + 8 carry-forward, all green).

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` | EventSubjectAdapter impl | VERIFIED | SUBJECT_TYPE, getSiteId (Order only), getSupportedEvents — unchanged from initial verify |
| `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` | ValueResolver impl | VERIFIED | productOf() helper + null-guarded buildContentId; 177 LOC; phpstan L10 clean |
| `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` | EventSubjectAdapter impl | VERIFIED | Site::getSiteIdFromContext fallback wired; class-level PHPDoc documents D-15 exception; 95 LOC; phpstan L10 clean |
| `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | eloquent listener | VERIFIED | eloquent.updated|created bindings, wasChanged status_id guard, sync dispatch — no regression |
| `classes/event/adapter/shopaholic/CartPositionWatcher.php` | eloquent listener | VERIFIED | bindings + dedup pre-check; site-source ban on watcher dir preserved (`grep Site:: classes/event/adapter/shopaholic/` returns 0) |
| `classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php` | ValueResolver impl | VERIFIED | productOf() null guard consistent — reference pattern for Order resolver |
| `classes/adapter/theme/ThemeActionEvent.php` | value object | VERIFIED | readonly constructor + crc32 synthetic ID |
| `classes/adapter/theme/ThemeActionAdapter.php` | EventSubjectAdapter impl | VERIFIED | 18 events + D-16 phpstan exclusion |
| `classes/adapter/theme/ThemeEventCollector.php` | app singleton | VERIFIED | 55 LOC, all four contract methods |
| `classes/adapter/theme/ThemeAjaxHandler.php` | AJAX defence | VERIFIED | META_STANDARD, rate-limit, JS-escape, allowlist via Settings::getThemeCustomEventNames |
| `models/Settings.php` | Settings model | VERIFIED | beforeSave persists newline-joined string; getThemeCustomEventNames parses string back; tolerates legacy array shape for migration |
| `lang/en/lang.php` | translation keys | VERIFIED | 12 keys under settings.fields (6 prior + 6 added in Plan 03-10) |
| `components/EventPixel.php` | CMS component | VERIFIED | D-09 direct DB, race-fence via insertOrIgnore |
| `components/PixelHead.php` | CMS component | VERIFIED | flush + mirror + Tiger-style catch |
| `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` | E2E test | VERIFIED | 2 tests, 29 assertions pass |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php` | fuzz test | VERIFIED | 14 malicious inputs, 422 + EventLog::count=0 |
| `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php` | site_id regression | VERIFIED | NEW — 3 tests covering primary + fallback + null branches |
| `tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php` | orphan regression | VERIFIED | NEW orphan test added; 10 tests total |
| `tests/Unit/Models/SettingsBeforeSaveTest.php` | round-trip + lang | VERIFIED | 7 tests (5 prior + 2 new for Gap 3 + Gap 4) |
| `updates/AddPayloadToMetapixelEventLogTable.php` | migration | VERIFIED | idempotency guard, nullable longText |
| `phpstan.neon` | per-file allowIn for cart adapter | VERIFIED | 4 entries (3 disallowedMethodCalls + 1 disallowedFunctionCalls) reference `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `Plugin.php` | `OrderStatusWatcher` | `Event::subscribe` conditional on isShopaholicEnabled | VERIFIED | line 67 inside isShopaholicEnabled() guard |
| `Plugin.php` | `ThemeEventCollector` | `cms.page.beforeRenderPage` → `$mThis->config['metapixel']` | VERIFIED | lines 71-77; Twig `this.metapixel` resolves via config key |
| `Plugin.php` | `ThemeAjaxHandler` | `Event::subscribe(ThemeAjaxHandler::class)` | VERIFIED | line 85; handler subscribes to cms.ajax.beforeRunHandler internally |
| `OrderStatusWatcher` | `SendCapiEvent` | `SendCapiEvent::dispatch('Purchase', ...)` | VERIFIED | line 58 of watcher; sync queue path in tests |
| `CartPositionWatcher` | `SendCapiEvent` | `dispatchAddToCart` → `SendCapiEvent::dispatch('AddToCart', ...)` | VERIFIED | line 83 of watcher |
| `ShopaholicCartPositionAdapter::getSiteId` | request-context site | `Site::getSiteIdFromContext()` fallback when cart.site_id null | **VERIFIED (gap closed)** | adapter line 52; phpstan disallowIn excludes this file alone (per-file, narrow) |
| `ShopaholicOrderValueResolver::buildContentId` | `Offer.product` | `productOf()` helper + null-guard | **VERIFIED (gap closed)** | resolver lines 131-149; mirrors ShopaholicCartPositionValueResolver Pattern 4 |
| `Settings::beforeSave` | `theme_custom_event_names` textarea | `setAttribute(implode("\n", $arClean))` | **VERIFIED (gap closed)** | models/Settings.php line 65; getThemeCustomEventNames at line 123-145 reads via preg_split('/\R/', ...) |
| `EventPixel` | `EventLog` | `DB::table('logingrupa_metapixel_event_log')` direct query | VERIFIED | D-09 frozen-payload read path |
| `PixelHead` | `ThemeEventCollector` | `App::make(ThemeEventCollector::class)->flush()` | VERIFIED | line 43; singleton flushed at render |
| `ThemeAjaxHandler::isAllowedEventName` | `Settings::getThemeCustomEventNames` | `in_array($mName, Settings::getThemeCustomEventNames(), true)` | VERIFIED | handler line 128; data flow restored after Gap 3 fix (Settings::get returns string, getter parses string back via preg_split, ThemeAjaxHandler receives a sanitized list<string>) |

---

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `Settings::getThemeCustomEventNames()` | `$mList = self::get('theme_custom_event_names', '')` | Lovata.Toolbox CommonSettings → JSON `value` column | YES — operator-typed textarea string persisted via Plan 03-10 implode; parsed back via preg_split | FLOWING |
| `ThemeAjaxHandler::isAllowedEventName` | `Settings::getThemeCustomEventNames()` return value | Settings model getter (above) | YES — returns sanitized `list<string>`; merged with META_STANDARD constant for in_array check | FLOWING |
| `ShopaholicOrderValueResolver::resolveContentIds` | `$arResult` | `productOf($obOffer)` per OrderPosition | YES — orphan branch returns documented 'SKU-0' fallback; live branch returns SKU-{product_id} or SKU-{product_id}-{offer_id} | FLOWING |
| `ShopaholicCartPositionAdapter::getSiteId` | `$mSiteId` + `$mContextSiteId` | `$obCart->site_id` first, then `Site::getSiteIdFromContext()` fallback | YES — primary branch returns explicit cart.site_id int; fallback returns SiteManager context site id when cart.site_id null | FLOWING |
| `PixelHead::onRun` | `$arScriptBlocks` | `App::make(ThemeEventCollector::class)->flush()` | YES — flushes singleton accumulator (request-scoped real data, not stub) | FLOWING |
| `EventPixel::onRun` | `$this->page['eventPixelData']` | `DB::table('logingrupa_metapixel_event_log')->where(...)->first(...)` | YES — direct DB read of frozen-payload CAPI row; returns null when no row, no static fallback | FLOWING |

All wired artifacts that render dynamic data trace to live data sources. No HOLLOW status anywhere.

---

## Requirements Coverage

| Requirement | Plans | Description | Status | Evidence |
|-------------|-------|-------------|--------|----------|
| SHOP-01 | 03-02 | ShopaholicOrderAdapter: alias, getSiteId (Order.site_id), getSupportedEvents | SATISFIED | Adapter confirmed |
| SHOP-02 | 03-02, 03-09 | ValueResolver: content_ids SKU format, null-safe product dereference | SATISFIED | productOf() helper + 'SKU-0' fallback; regression test confirms no TypeError |
| SHOP-03 | 03-02, 03-03, 03-09 | OrderStatusWatcher + CartPositionWatcher + CartPosition getSiteId | SATISFIED | OrderStatusWatcher unchanged + CartPosition getSiteId now returns non-null int via Site::getSiteIdFromContext fallback |
| SHOP-04 | 03-02 | PluginManager gate: adapter boots only when OrdersShopaholic present | SATISFIED | isShopaholicEnabled() confirmed at Plugin.php:125-131 |
| SHOP-05 | 03-04 | E2E integration test: Purchase flow, MockHandler, dedup fence | SATISFIED | PurchaseFlowIntegrationTest — 2 tests, 29 assertions, all pass |
| THEM-01 | 03-05 | ThemeActionEvent value object, crc32 synthetic ID | SATISFIED | Readonly constructor; crc32 at line 42 |
| THEM-02 | 03-05 | ThemeActionAdapter 10-invariant contract + phpstan D-16 | SATISFIED | 18-event map + per-dir phpstan exclusion |
| THEM-03 | 03-06 | ThemeEventCollector singleton push/flush/count | SATISFIED | All four methods + singleton binding |
| THEM-04 | 03-06 | Twig dot-notation `this.metapixel.pushEvent()` only, no bare function | SATISFIED | registerMarkupTags returns empty functions; config mount confirmed |
| THEM-05 | 03-07, 03-10 | ThemeAjaxHandler P-09 + Settings beforeSave round-trip + lang keys | SATISFIED | Round-trip + lang regression tests pass; ThemeAjaxHandler reads sanitized list via Settings::getThemeCustomEventNames |
| THEM-06 | 03-08 | EventPixel D-09 direct DB, event_id validation, insertOrIgnore | SATISFIED | All three contract checks confirmed |
| THEM-07 | 03-08, 03-10 | PixelHead flush + mirror + exception fixture + phpunit.xml coverage + lang keys | SATISFIED | All artifacts present; six previously-missing lang keys added in Plan 03-10 → Settings UI is now operator-configurable |

All 12 requirements SATISFIED. Zero BLOCKED, zero PARTIAL.

---

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `Plugin.php` | boot() | Top-level `use Lovata\OrdersShopaholic\Models\Order` + `CartPosition` imports (WR-07) | INFO | Boundary rule: non-adapter file imports Lovata\OrdersShopaholic\*; gated by isShopaholicEnabled — composer-dependency-analyser pass under minimal install confirmed via 87 minimal-cell tests green |
| `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | handleUpdated guard (line 40) | `$obOrder->exists && ! $obOrder->wasChanged('status_id')` — `exists` is always true at update time (WR-02) | INFO | Dead predicate, not wrong, no runtime impact |

**No new anti-patterns introduced by gap-closure plans 03-09 or 03-10.** No debt markers (TBD/FIXME/XXX) in modified files. No `@phpstan-ignore`, no `assert()`.

---

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Pint formatting clean | `composer qa` (pint-test step) | `{"tool":"pint","result":"passed"}` | PASS |
| PHPStan level 10 clean | `composer qa` (phpstan step) | `[OK] No errors` | PASS |
| Coverage gate ≥ 90% | `composer qa` (pest --coverage --min=90) | `Total: 91.8 %` | PASS |
| Full Pest suite passes (full-Lovata cell) | `pest --compact` | `Tests: 261 passed (919 assertions)` | PASS |
| Minimal-install cell green | `pest --exclude-group=adapter --compact` | `Tests: 87 passed (241 assertions)` | PASS |
| Gap 1 regression: orphan offer no TypeError | `pest --compact tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php` | `test_resolve_content_ids_handles_orphaned_offer_without_typeerror` passes; returns `['SKU-0']` | PASS |
| Gap 2 regression: site_id fallback returns int | `pest --compact tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php` | 3 tests pass (primary + fallback + null edge) | PASS |
| Gap 3 regression: textarea round-trip | `pest --compact tests/Unit/Models/SettingsBeforeSaveTest.php` | `test_theme_custom_event_names_round_trips_through_textarea` asserts string + preg_split equality | PASS |
| Gap 4 regression: lang keys resolve | (same file) `test_settings_fields_lang_keys_resolve_to_human_readable_strings` | 6 keys present, non-key, non-empty | PASS |

---

## Probe Execution

Step 7c: SKIPPED. No `scripts/*/tests/probe-*.sh` files declared in PLAN.md files or found conventionally (`scripts/` directory does not exist in this plugin). Probe-based verification is not part of this phase's contract; behavioral checks above cover the equivalent ground.

---

## Human Verification Required

None — all gaps are programmatically verifiable. The four prior gaps have closure evidence (code change + passing regression test); the eight prior verifications are unchanged. No visual, real-time, or external-service checks required.

---

## Gaps Summary

**Zero gaps remaining.** All four prior blockers closed by Plans 03-09 + 03-10:

- **Gap 1 (SHOP-02 — orphan TypeError):** closed by Plan 03-09 commit `5e6f019`. `ShopaholicOrderValueResolver` now mirrors `ShopaholicCartPositionValueResolver` byte-for-byte: `productOf()` helper using `getRelationValue('product')` + `instanceof Product` narrowing, and `buildContentId` early-returns `sprintf('SKU-%d', 0)` when the product is null. Regression test at `tests/Unit/Adapter/Shopaholic/ShopaholicOrderValueResolverTest.php:159` asserts no TypeError + documented 'SKU-0' fallback.

- **Gap 2 (SHOP-03 — site_id always null):** closed by Plan 03-09 commit `5e6f019`. `ShopaholicCartPositionAdapter::getSiteId` now falls back to `Site::getSiteIdFromContext()` (note: the plan originally said `Site::getCurrent` but the executor corrected to the actual October API surface — both behaviors are equivalent for "request-context site id"). `phpstan.neon` excludes the single file from the disallowedMethod and disallowedFunction bans for Site/SiteManager/Request/request() (4 entries). The watcher dir keeps the ban: `grep -rE 'Site::|SiteManager::|Request::|request\(' classes/event/adapter/shopaholic/` returns 0 matches. Three regression tests at `tests/Unit/Adapter/Shopaholic/ShopaholicCartPositionAdapterTest.php` cover the primary, fallback, and null-edge branches.

- **Gap 3 (THEM-05 — Settings array storage):** closed by Plan 03-10 commits `dda1fc1` + `b273878`. `Settings::beforeSave` now calls `setAttribute('theme_custom_event_names', implode("\n", $arClean))`, persisting a newline-joined string the October textarea widget can display and round-trip. `getThemeCustomEventNames` updated to explode via `preg_split('/\R/', ...)` and tolerates the legacy array shape for one-shot migration. Round-trip regression test at `tests/Unit/Models/SettingsBeforeSaveTest.php:93` asserts the post-`beforeSave` attribute is a string equal to the original three event names when split.

- **Gap 4 (THEM-05 + THEM-07 — missing lang keys):** closed by Plan 03-10 commits `d0f9551` + `b273878`. `lang/en/lang.php` now declares all six previously-missing keys (`paid_status_code_label/comment`, `default_currency_code_label/comment`, `theme_custom_event_names_label/comment`) with human-readable English values. The Settings backend page now resolves to readable strings instead of raw dotted lang keys — the plugin is operator-configurable. Resolution regression test at `tests/Unit/Models/SettingsBeforeSaveTest.php:110` reads the lang file directly and asserts each key is present, is a string, is not equal to the key itself, and has non-zero length.

**No new gaps, no regressions, no human verification required.** Phase 03 is fully closed and ready for milestone-level audit / Phase 04 entry.

---

_Verified: 2026-05-19_
_Verifier: Claude (gsd-verifier)_
