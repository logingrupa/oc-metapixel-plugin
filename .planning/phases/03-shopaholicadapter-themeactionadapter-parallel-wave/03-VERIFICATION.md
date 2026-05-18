---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
verified: 2026-05-18T00:00:00Z
status: gaps_found
score: 8/12
overrides_applied: 0
gaps:
  - truth: "content_ids format SKU-{product_id}[-{offer_id}] is safe when offer.product is missing"
    status: failed
    reason: "ShopaholicOrderValueResolver::buildContentId dereferences $obOffer->product with no null guard; intAttr declares non-nullable Model param; raises TypeError on orphaned offer"
    artifacts:
      - path: "classes/adapter/shopaholic/ShopaholicOrderValueResolver.php"
        issue: "Line ~126: $obProduct = $obOffer->product; — no null guard before passing to intAttr(Model $obModel)"
    missing:
      - "Add productOf() helper (mirroring ShopaholicCartPositionValueResolver) returning ?Product via getRelationValue('product')"
      - "Guard: if ($obProduct === null) { return 'SKU-unknown'; } before intAttr call"
    requirement_ids:
      - SHOP-02

  - truth: "CartPosition AddToCart events record correct site_id, enabling the EventLog UNIQUE race-fence"
    status: failed
    reason: "ShopaholicCartPositionAdapter::getSiteId reads $obPosition->cart->site_id but Lovata Cart model has no site_id column; always returns null; MySQL NULL != NULL in UNIQUE index breaks dedup"
    artifacts:
      - path: "classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php"
        issue: "getSiteId traverses cart relation for site_id which does not exist on the Cart model"
    missing:
      - "Determine canonical site_id source for CartPosition (Order-linked path, or request-context D-15 exception with phpstan disallowIn exclusion like theme adapter)"
      - "If request-context: add classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php to phpstan.neon disallowIn exclusion block alongside theme adapter"
      - "If Order-linked: traverse CartPosition->OrderCartPosition->Order->site_id (if relation exists) or document as known-null with a fixed fallback"
    requirement_ids:
      - SHOP-03

  - truth: "Settings::beforeSave() stores theme_custom_event_names as a string the textarea widget can round-trip"
    status: failed
    reason: "beforeSave stores $arClean (PHP array) via setAttribute; October textarea coerces array to literal string 'Array'; operator saves -> all custom event names are lost"
    artifacts:
      - path: "models/Settings.php"
        issue: "setAttribute('theme_custom_event_names', $arClean) stores PHP array; textarea widget cannot display or preserve array value"
    missing:
      - "Store as implode(\"\\n\", $arClean) in beforeSave"
      - "Update getThemeCustomEventNames() to explode(\"\\n\", ...) on read (or verify it already handles string input)"
    requirement_ids:
      - THEM-05

  - truth: "Settings UI labels render human-readable English text (not raw lang key strings)"
    status: failed
    reason: "Six translation keys referenced in fields.yaml are absent from lang/en/lang.php; October renders raw keys e.g. logingrupa.metapixel::lang.settings.fields.paid_status_code_label"
    artifacts:
      - path: "lang/en/lang.php"
        issue: "Missing: paid_status_code_label, paid_status_code_comment, default_currency_code_label, default_currency_code_comment, theme_custom_event_names_label, theme_custom_event_names_comment"
    missing:
      - "Add all six keys under settings.fields namespace in lang/en/lang.php with English placeholder strings"
      - "Optionally add matching keys to lang/lv/lang.php and lang/no/lang.php if those locales exist"
    requirement_ids:
      - THEM-05
      - THEM-07
---

# Phase 03: ShopaholicAdapter + ThemeActionAdapter Verification Report

**Phase Goal:** Deliver ShopaholicOrderAdapter, ShopaholicCartPositionAdapter, ThemeActionAdapter, ThemeEventCollector, ThemeAjaxHandler, EventPixel, and PixelHead — all wired, tested, and QA-clean — so the plugin can fire Purchase + AddToCart CAPI events from the Shopaholic order lifecycle and arbitrary theme-triggered browser pixel events via Twig dot-notation.

**Verified:** 2026-05-18

**Status:** GAPS FOUND — 4 critical blockers

**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Requirement | Truth | Status | Evidence |
|---|-------------|-------|--------|----------|
| 1 | SHOP-01 | ShopaholicOrderAdapter registered with alias `shopaholic.order`, getSiteId reads only Order.site_id, getSupportedEvents returns `['Purchase' => ['capi','pixel']]` | VERIFIED | `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` — SUBJECT_TYPE const, getSiteId, getSupportedEvents confirmed |
| 2 | SHOP-02 | content_ids format `SKU-{product_id}[-{offer_id}]` handles deleted/orphaned product without TypeError | FAILED | `buildContentId` line ~126: `$obOffer->product` no null guard; `intAttr(Model $obModel)` typed non-nullable — throws TypeError on orphaned offer |
| 3 | SHOP-03 | OrderStatusWatcher dispatches Purchase on paid status change; CartPositionAdapter AddToCart records correct site_id for UNIQUE race-fence | PARTIAL | OrderStatusWatcher verified (eloquent bindings, wasChanged guard, dispatch, Tiger-style catch). CartPosition site_id always null — Cart model has no site_id column |
| 4 | SHOP-04 | Shopaholic adapter boots only when OrdersShopaholic plugin is active (PluginManager gate) | VERIFIED | `Plugin.php isShopaholicEnabled()` via `App::make(PluginManager::class)->hasPlugin(...)` confirmed; conditional subscriber registration confirmed |
| 5 | SHOP-05 | End-to-end integration test proves Purchase flow: order created, status changed, CAPI HTTP call recorded, EventLog row written, dedup fence blocks second dispatch | VERIFIED | `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` — MockHandler + history middleware + sync queue + EventLog assertions confirmed |
| 6 | THEM-01 | ThemeActionEvent is an immutable value object (readonly properties or constructor-assigned, no setters) with synthetic subject_id via crc32 | VERIFIED | `classes/adapter/theme/ThemeActionEvent.php` — crc32 synthetic ID, readonly-style construction confirmed |
| 7 | THEM-02 | ThemeActionAdapter satisfies all 10 EventSubjectAdapter contract invariants including 18-event getSupportedEvents and phpstan D-16 exemption | VERIFIED | Adapter confirmed; phpstan.neon `disallowIn` excludes `classes/adapter/theme/*` not `classes/adapter/*` glob |
| 8 | THEM-03 | ThemeEventCollector is an app-singleton with push/pushEvent/flush/count; Twig dot-notation `this.metapixel.pushEvent({...})` works | VERIFIED | `classes/adapter/theme/ThemeEventCollector.php` ≤60 LOC, all four methods confirmed; Plugin.php mounts via `cms.page.beforeRenderPage` into `$mThis->config['metapixel']` |
| 9 | THEM-04 | Twig access is via dot-notation only (`this.metapixel`); no bare Twig function registered | VERIFIED | `Plugin::registerMarkupTags()` returns `['functions' => [], 'filters' => []]` — no bare functions; mount via config key confirmed |
| 10 | THEM-05 | ThemeAjaxHandler P-09 defence: validates event name against META_STANDARD + allowlist, rate-limits, JS-escapes; Settings beforeSave sanitises and stores theme_custom_event_names round-trippable | PARTIAL | ThemeAjaxHandler P-09 all verified. Settings::beforeSave stores PHP array via setAttribute — textarea widget breaks on re-edit; 6 lang keys missing from lang/en/lang.php |
| 11 | THEM-06 | EventPixel reads EventLog via direct DB query (D-09, no AdapterRegistry at render), validates event_id against CAPI row, race-fences pixel-fired column with insertOrIgnore | VERIFIED | `components/EventPixel.php` — DB::table direct query, onMarkFired validation, insertOrIgnore confirmed |
| 12 | THEM-07 | PixelHead flushes ThemeEventCollector, renders pixel blocks, mirrors to CAPI, has exception fixture; phpunit.xml covers components/ and console/ directories | VERIFIED | `components/PixelHead.php` — flush, mirror, Tiger-style catch confirmed; `tests/fixtures/components/PixelHeadExceptionFixture.php` exists; phpunit.xml source includes confirmed |

**Score: 8/12 truths verified** (SHOP-02 failed, SHOP-03 partial, THEM-05 partial — each partial counts as failed per gap rules)

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `classes/adapter/shopaholic/ShopaholicOrderAdapter.php` | EventSubjectAdapter impl | VERIFIED | SUBJECT_TYPE, getSiteId (Order only), getSupportedEvents |
| `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` | ValueResolver impl | STUB (partial) | buildContentId missing null guard on $obOffer->product |
| `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` | EventSubjectAdapter impl | STUB (partial) | getSiteId returns null always — Cart.site_id does not exist |
| `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | eloquent listener | VERIFIED | bindings, wasChanged, dispatch, Tiger-style |
| `classes/event/adapter/shopaholic/CartPositionWatcher.php` | eloquent listener | VERIFIED | bindings, dedup pre-check, null-guards |
| `classes/adapter/shopaholic/ShopaholicCartPositionValueResolver.php` | ValueResolver impl | VERIFIED | productOf() null guard consistent |
| `classes/adapter/theme/ThemeActionEvent.php` | value object | VERIFIED | crc32 synthetic ID |
| `classes/adapter/theme/ThemeActionAdapter.php` | EventSubjectAdapter impl | VERIFIED | 18 events, phpstan D-16 |
| `classes/adapter/theme/ThemeEventCollector.php` | app singleton | VERIFIED | push/pushEvent/flush/count ≤60 LOC |
| `classes/adapter/theme/ThemeAjaxHandler.php` | AJAX defence | VERIFIED | META_STANDARD, rate-limit, JS-escape |
| `models/Settings.php` | Settings model | STUB (partial) | beforeSave stores PHP array; textarea breaks round-trip |
| `lang/en/lang.php` | translation keys | MISSING (partial) | 6 keys absent from settings.fields namespace |
| `components/EventPixel.php` | CMS component | VERIFIED | D-09 direct DB, race-fence |
| `components/PixelHead.php` | CMS component | VERIFIED | flush, mirror, exception fixture |
| `tests/Feature/Adapter/Shopaholic/PurchaseFlowIntegrationTest.php` | E2E test | VERIFIED | MockHandler, dedup proof |
| `tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php` | fuzz test | VERIFIED | 14 malicious inputs, 422 + EventLog::count=0 |
| `updates/AddPayloadToMetapixelEventLogTable.php` | migration | VERIFIED | idempotency guard, nullable longText |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `Plugin.php` | `OrderStatusWatcher` | `Event::subscribe` conditional on isShopaholicEnabled | VERIFIED | isShopaholicEnabled() uses PluginManager |
| `Plugin.php` | `ThemeEventCollector` | `cms.page.beforeRenderPage` → `$mThis->config['metapixel']` | VERIFIED | Twig `this.metapixel` resolves via config key |
| `Plugin.php` | `ThemeAjaxHandler` | `cms.ajax.beforeRunHandler` listener | VERIFIED | HANDLER_NAME guard at entry |
| `OrderStatusWatcher` | `SendCapiEvent` | `SendCapiEvent::dispatch('Purchase', ...)` | VERIFIED | sync queue in tests, real queue in prod |
| `CartPositionWatcher` | `SendCapiEvent` | `dispatchAddToCart` → `SendCapiEvent::dispatch('AddToCart', ...)` | VERIFIED | dispatch call confirmed |
| `ShopaholicCartPositionAdapter::getSiteId` | `Cart.site_id` | `$obPosition->cart->site_id` | BROKEN | Cart model has no site_id column — always null |
| `ShopaholicOrderValueResolver::buildContentId` | `Offer.product` | `$obOffer->product` | BROKEN | No null guard — TypeError on orphaned offer |
| `Settings::beforeSave` | `theme_custom_event_names` textarea | `setAttribute($arClean)` stores array | BROKEN | Textarea coerces array to "Array" string |
| `EventPixel` | `EventLog` | `DB::table('logingrupa_metapixel_event_log')` direct query | VERIFIED | D-09 frozen-payload read path |
| `PixelHead` | `ThemeEventCollector` | `App::make(ThemeEventCollector::class)->flush()` | VERIFIED | singleton flushed at render |

---

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` | ~126 | `$obOffer->product` no null guard before non-nullable typed method call | BLOCKER | TypeError crash on orphaned offer — crashes Purchase CAPI dispatch entirely |
| `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php` | getSiteId | `$obPosition->cart->site_id` — column does not exist on Cart model | BLOCKER | site_id always null — UNIQUE race-fence broken for all AddToCart events (NULL != NULL in MySQL UNIQUE index) |
| `models/Settings.php` | beforeSave | `setAttribute('theme_custom_event_names', $arClean)` stores PHP array | BLOCKER | Textarea renders "Array"; next save wipes all custom event names |
| `lang/en/lang.php` | settings.fields | 6 keys absent: paid_status_code_{label,comment}, default_currency_code_{label,comment}, theme_custom_event_names_{label,comment} | BLOCKER | Settings UI shows raw key strings; operator cannot configure plugin |
| `Plugin.php` | boot() | Top-level `use Lovata\OrdersShopaholic\Models\Order` + `CartPosition` imports (WR-07) | WARNING | Boundary rule: non-adapter file imports Lovata\OrdersShopaholic\*; fine if isShopaholicEnabled gate is sufficient, but composer-dependency-analyser may flag on minimal install |
| `classes/event/adapter/shopaholic/OrderStatusWatcher.php` | handleUpdated | `$obOrder->exists &&` predicate before `wasChanged` is always true at update time (WR-02) | INFO | Dead predicate, not wrong, no runtime impact |

---

## Requirements Coverage

| Requirement | Plans | Description | Status | Evidence |
|-------------|-------|-------------|--------|----------|
| SHOP-01 | 03-02 | ShopaholicOrderAdapter: alias, getSiteId (Order.site_id), getSupportedEvents | SATISFIED | Adapter confirmed |
| SHOP-02 | 03-02 | ValueResolver: content_ids SKU format, null-safe product dereference | BLOCKED | CR-01: buildContentId missing null guard on $obOffer->product |
| SHOP-03 | 03-02, 03-03 | OrderStatusWatcher + CartPositionWatcher + CartPosition getSiteId | PARTIAL | Order path OK; CartPosition getSiteId broken (Cart.site_id absent) |
| SHOP-04 | 03-02 | PluginManager gate: adapter boots only when OrdersShopaholic present | SATISFIED | isShopaholicEnabled() confirmed |
| SHOP-05 | 03-04 | E2E integration test: Purchase flow, MockHandler, dedup fence | SATISFIED | PurchaseFlowIntegrationTest confirmed |
| THEM-01 | 03-05 | ThemeActionEvent value object, crc32 synthetic ID | SATISFIED | Confirmed |
| THEM-02 | 03-05 | ThemeActionAdapter 10-invariant contract + phpstan D-16 | SATISFIED | Confirmed |
| THEM-03 | 03-06 | ThemeEventCollector singleton push/flush/count | SATISFIED | Confirmed |
| THEM-04 | 03-06 | Twig dot-notation `this.metapixel.pushEvent()` only, no bare function | SATISFIED | registerMarkupTags returns empty functions; config mount confirmed |
| THEM-05 | 03-07 | ThemeAjaxHandler P-09 + Settings beforeSave round-trip + lang keys | BLOCKED | CR-03: array stored in textarea; CR-04: 6 lang keys missing |
| THEM-06 | 03-08 | EventPixel D-09 direct DB, event_id validation, insertOrIgnore | SATISFIED | Confirmed |
| THEM-07 | 03-08 | PixelHead flush + mirror + exception fixture + phpunit.xml coverage | SATISFIED | Confirmed (lang key absence affects Settings UI, not PixelHead render) |

---

## Behavioral Spot-Checks

Step 7b: SKIPPED — no runnable HTTP entry points available for grep-based spot-check; all testable behaviors covered by PHPUnit/Pest test suite assertions already verified above.

---

## Probe Execution

Step 7c: No `scripts/*/tests/probe-*.sh` files declared in PLAN.md files or found conventionally. SKIPPED.

---

## Human Verification Required

None — all gaps are programmatically verifiable from code inspection. No visual, real-time, or external-service checks required for gap determination.

---

## Gaps Summary

Four critical blockers prevent phase goal achievement. They cluster in two areas:

**Shopaholic adapter correctness (SHOP-02, SHOP-03 CartPosition path):**
`ShopaholicOrderValueResolver::buildContentId` will throw TypeError when an offer's product is deleted — the exact failure mode that crashes the Purchase CAPI dispatch before any logging occurs. Separately, `ShopaholicCartPositionAdapter::getSiteId` always returns null because `Lovata\OrdersShopaholic\Models\Cart` has no `site_id` column; this silently breaks the EventLog UNIQUE constraint dedup for every AddToCart event on MySQL (NULL != NULL in UNIQUE index evaluation).

**Settings persistence and UI (THEM-05, partially THEM-07):**
`Settings::beforeSave` stores a PHP array directly into a textarea-backed column. October CMS renders the field as "Array" on re-edit; the next save wipes all operator-configured custom event names. Additionally, six translation keys referenced in `fields.yaml` are absent from `lang/en/lang.php`, causing the Settings backend page to render raw dotted key strings instead of human-readable labels — making the plugin unconfigurable in practice.

The eight verified requirements (SHOP-01, SHOP-04, SHOP-05, THEM-01 through THEM-04, THEM-06, THEM-07) are production-quality with no stub patterns. Remediation scope is narrow: one null guard + helper method, one getSiteId source decision, one `implode`/`explode` fix in Settings, and six lang key additions.

---

_Verified: 2026-05-18_
_Verifier: Claude (gsd-verifier)_
