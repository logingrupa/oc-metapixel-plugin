---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 06
subsystem: api
tags: [meta-pixel, viewcontent, hybrid-ajax, adapter-pattern, t-6-04, t-6-05, t-6-06, soft-gate]

requires:
  - phase: 06-02
    provides: PixelHead deferred-flush + ThemeEventCollector singleton
  - phase: 06-03
    provides: AdapterRegistry::resolveByAlias + SupportsHybridAjax + UnknownSubjectTypeException
  - phase: 06-04
    provides: ShopaholicProductAdapter (SupportsHybridAjax) with active/site-match loadSubject
  - phase: 06-05
    provides: ProductPageWatcher::dispatchForOfferSwitch entry point (canonical viewcontent:{pid}:{oid}:{eid} action_key owner)

provides:
  - ProductPixel component [productPixel] — PDP-level browser pixel + offer-switch JS injector
  - ThemeEventCollector::peek() — non-mutating accumulator read
  - ThemeAjaxHandler hybrid subject_type branch — registry-allowlisted FQN-injection-safe AJAX path
  - Wire-format action_key canonicalization contract — JS sends two-segment, server stores four-segment
  - Plugin.php registerComponents() 3-entry surface (EventPixel + PixelHead + ProductPixel)

affects: [phase-07-docs, phase-05-readme, phase-05-changelog, v2.1-extensibility-marketplace-adapters]

tech-stack:
  added: []
  patterns:
    - "Wire-format action_key canonicalization — JS posts two-segment; server appends event_id before EventLog insert"
    - "Hybrid AJAX alias-allowlist — register-time alias index gates untrusted JS subject_type strings (T-6-04 mitigation)"
    - "Soft-gate via server-injected window.__metapixelProduct global — runtime no-op outside PDP (T-6-06 mitigation)"
    - "Adapter test-double via App::instance + register(Class, Class) — bypasses Lovata Product::active()->find boot dependency"
    - "Mockery test-double for ProductPageWatcher dispatchForOfferSwitch — final keyword dropped to enable extension"

key-files:
  created:
    - components/ProductPixel.php
    - components/productpixel/default.htm
  modified:
    - classes/adapter/theme/ThemeAjaxHandler.php
    - classes/adapter/theme/ThemeEventCollector.php
    - classes/event/adapter/shopaholic/ProductPageWatcher.php
    - Plugin.php
    - tests/Feature/Components/ProductPixelTest.php
    - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php

key-decisions:
  - "Wire-format action_key is two-segment 'viewcontent:{pid}:{oid}' — server appends event_id to produce canonical four-segment 'viewcontent:{pid}:{oid}:{eid}' before EventLog insert (CONTEXT.md Claude's Discretion). Direction is server → frontend only for event_id (locked carry-forward decision)."
  - "T-6-04 mitigation via AdapterRegistry::resolveByAlias allowlist — JS-supplied subject_type strings are byte-for-byte matched against the register-time alias index. No FQN deserialization from untrusted input. UnknownSubjectTypeException → 422 typed JsonResponse."
  - "T-6-05 mitigation enforced at handler boundary via instanceof SupportsHybridAjax + loadSubject domain re-enforcement (active/site/SoftDelete). loadSubject returning null → 404."
  - "T-6-06 mitigation via JS soft-gate on window.__metapixelProduct — global only set on PDP renders (ProductPixel peeks ThemeEventCollector for product_id push). Cart-modal [name='offer_id'] outside PDP cannot trigger ViewContent."
  - "ThemeEventCollector::peek() returns the live array without consuming — distinguishes 'I want to look' (ProductPixel) from 'I want to consume' (PixelHead deferred-flush). No defensive copy; callers iterate read-only."
  - "Shopaholic-product alias delegates to ProductPageWatcher::dispatchForOfferSwitch (plan 06-05 entry point). Generic hybrid aliases (future mall.product etc.) fall through to PayloadBuilder + SendCapiEvent + server-side action_key append. Single owner per canonical-action_key shape."
  - "Rule-1 deviation: dropped 'final' keyword from ProductPageWatcher to enable Mockery test-double for dispatchForOfferSwitch delegation. Same pattern as plan 02-06's MetaClient final-drop fix. Production behavior unchanged; only extension surface opens for tests."

patterns-established:
  - "Hybrid AJAX alias routing: ThemeAjaxHandler::dispatchViaAdapter(array, string) → JsonResponse. Branch on AdapterRegistry::resolveByAlias result; check SupportsHybridAjax opt-in; validate subject_id; loadSubject; per-alias dispatch (delegate or generic)."
  - "buildHybridContext narrowing helper — phpstan level 10 string-keyed array contract for SupportsHybridAjax::loadSubject(int, array<string, mixed>)."
  - "Test-double adapter binding: $this->app->instance(ShopaholicProductAdapter::class, $obStub) + register(Class, Class) — AdapterRegistry's register-time getSubjectType call resolves to the stub's alias."

requirements-completed:
  - VIEW-05
  - VIEW-06
  - VIEW-09

duration: ~15min
completed: 2026-05-28
---

# Phase 06 Plan 06: ProductPixel + Hybrid AJAX subject_type routing Summary

**Ships the offer-switch ViewContent loop end-to-end: ProductPixel component emits window.__metapixelProduct global + delegated `[name="offer_id"]` change listener; ThemeAjaxHandler hybrid branch routes JS subject_type aliases through AdapterRegistry to ProductPageWatcher::dispatchForOfferSwitch with full T-6-04 / T-6-05 / T-6-06 mitigation.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-28T13:54:00Z
- **Completed:** 2026-05-28T14:09:45Z
- **Tasks:** 3/3
- **Files created:** 2 (ProductPixel.php + default.htm)
- **Files modified:** 6 (ThemeAjaxHandler.php, ThemeEventCollector.php, ProductPageWatcher.php, Plugin.php, ProductPixelTest.php, ThemeAjaxHandlerSubjectTypeTest.php)

## Accomplishments

- Closed the offer-switch ViewContent loop: DOM `[name="offer_id"]` change → soft-gated JS → AJAX with two-segment wire-format action_key → AdapterRegistry alias resolve → ProductPageWatcher delegation → CAPI dispatch + EventLog with canonical four-segment action_key → response script injection.
- T-6-04 + T-6-05 + T-6-06 mitigations enforced and gate-tested. No FQN deserialization from JS. Adapter must opt in via SupportsHybridAjax. loadSubject re-enforces domain guards.
- ProductPixel ships with zero-property operator surface (`[productPixel]`). PluginGuard-gated. Idempotent JS (window.__metapixelProductPixelInit guard).
- 9 new GREEN tests (4 ProductPixelTest + 5 ThemeAjaxHandlerSubjectTypeTest) plus 21 pre-existing AJAX handler tests still green plus 11 pre-existing ProductPageWatcher tests still green. 30 + 11 = 41 tests verified end-to-end.

## Task Commits

1. **Task 1: ProductPixel component + offer-switch JS + ThemeEventCollector::peek** — `9c995f5` (feat)
2. **Task 2: ThemeAjaxHandler hybrid subject_type branch + ProductPixel boot wiring** — `b396f8e` (feat)
3. **Task 3: GREEN 4 ProductPixelTest + 5 ThemeAjaxHandlerSubjectTypeTest cases** — `d0a940c` (test) — also carries the Rule-1 `final`-drop on ProductPageWatcher

## Files Created/Modified

### Created

- `components/ProductPixel.php` — final ComponentBase subclass alias `[productPixel]`. PluginGuard-gated. `onRun()` peeks ThemeEventCollector for product_id; emits `<script>window.__metapixelProduct={id:N};</script>` via sprintf (XSS-safe — integer cast) when found. Always emits delegated offer-switch JS (self-no-ops via soft-gate at runtime).
- `components/productpixel/default.htm` — Twig partial; two conditional `|raw` blocks for `productPixelProductGlobalJs` + `productPixelOfferSwitchJs`.

### Modified

- `classes/adapter/theme/ThemeAjaxHandler.php` — added 6 imports (AdapterRegistry, EventSubjectAdapter via SupportsHybridAjax, ShopaholicProductAdapter, ProductPageWatcher, UnknownSubjectTypeException) and `dispatchViaAdapter(array, string): JsonResponse` private method (~80 LOC) plus `buildHybridContext(array): array<string, mixed>` phpstan-narrowing helper. Hybrid branch inserted in `onBeforeRun` between rate-limit check and ThemeActionEvent::fromArray path — existing path untouched.
- `classes/adapter/theme/ThemeEventCollector.php` — added `peek(): array` non-mutating accessor.
- `classes/event/adapter/shopaholic/ProductPageWatcher.php` — dropped `final` keyword to enable Mockery test-double for `dispatchForOfferSwitch` delegation testing. No behavior change.
- `Plugin.php` — added ProductPixel import + `registerComponents()` returns 3 entries (EventPixel + PixelHead + ProductPixel).
- `tests/Feature/Components/ProductPixelTest.php` — 4 GREEN tests (idempotency flag + soft-gate + subject_type literal + wire-format action_key + product-global render + disabled-state null vars + collector-empty null product-global).
- `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` — 5 GREEN tests (unknown-alias-422 + valid-routes-delegate-to-watcher + lacks-hybrid-support-422 + invalid-subject-id-422 + loadSubject-null-404).

## Status Code Matrix (Hybrid AJAX path)

| Condition                                              | Response | Body                                                       | Mitigation |
| ------------------------------------------------------ | -------- | ---------------------------------------------------------- | ---------- |
| Unknown alias (not in register-time index)             | 422      | `{error: "unknown subject_type"}`                          | T-6-04     |
| Adapter does not implement SupportsHybridAjax          | 422      | `{error: "subject_type does not support hybrid AJAX"}`     | T-6-04     |
| subject_id <= 0 / non-numeric                          | 422      | `{error: "invalid subject_id"}`                            | Tiger-Style positive-space |
| loadSubject returns null (inactive / cross-site / 404) | 404      | `{error: "subject not found"}`                             | T-6-05     |
| event_name not in allowlist                            | 422      | `{error: "event_name not allowed"}`                        | THEM-05 (pre-existing) |
| shopaholic.product + invalid offer_id                  | 422      | `{error: "invalid offer_id"}`                              | input validation |
| Happy path (shopaholic.product)                        | 200      | `{event_id, script}` (event_id from ProductPageWatcher)    | — |
| Happy path (generic hybrid alias)                      | 200      | `{event_id, script}` (server-generated UUIDv4)             | — |
| Throwable not caught inside                            | 500      | `{error: "internal"}` (existing outer try/catch)           | Tiger-Style boundary |

## Threat Mitigation

| Threat | Status | Anchor |
| ------ | ------ | ------ |
| T-6-04 (subject_type FQN injection) | Mitigated | AdapterRegistry::resolveByAlias allowlist + SupportsHybridAjax instanceof check. Tested by `test_unknown_subject_type_alias_returns_422` + `test_adapter_lacking_supports_hybrid_ajax_returns_422`. |
| T-6-05 (cross-site subject_id spoofing) | Mitigated | Adapter::loadSubject re-enforces active/site-match (anchored by plan 06-04 ShopaholicProductAdapter); handler converts null return → 404. Tested by `test_loadSubject_returning_null_returns_404`. |
| T-6-06 (cart-modal [name='offer_id'] spurious fire) | Mitigated | Soft-gate via server-injected window.__metapixelProduct global. JS no-ops when global absent. Tested by `test_offer_switch_js_rendered_with_idempotency_flag_and_soft_gate` (asserts presence of `!window.__metapixelProduct` in JS body). |
| T-6-W6-T (`</script>` payload in response) | Mitigated | JSON_HEX_* mask on event_name + event_id; script body uses server-derived strings only. Existing pattern from ThemeAjaxHandler line 102. |
| T-6-W6-D (storm-flood DoS) | Accept | Existing RateLimiter (30 req / 60s / IP+session) in onBeforeRun; verified by ThemeAjaxHandlerRateLimitTest. |

## Wire-Format Action_Key Canonicalization Contract

| Stage | Shape | Owner |
| ----- | ----- | ----- |
| JS sends (offer-switch) | `viewcontent:{pid}:{oid}` (two segments) | ProductPixel inline JS |
| Server appends (shopaholic.product) | `viewcontent:{pid}:{oid}:{eid}` (four segments) | ProductPageWatcher::dispatchForOfferSwitch (plan 06-05) |
| Server appends (generic hybrid alias) | `{wire}:{eid}` (server-side string concat) | ThemeAjaxHandler::dispatchViaAdapter |
| EventLog stores | Four-segment canonical | EventLogWriter::record |

The `event_id` is server-generated UUIDv4 — the dedup-contract anchor. Direction is server → frontend only (locked carry-forward decision from v1.x). JS half of the action_key is informational/observability only; the appended event_id is the per-request uniqueness anchor and the dedup pivot Meta matches on within ±10s of event_time.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] ProductPageWatcher `final` keyword prevented Mockery test-double**

- **Found during:** Task 3 — `test_valid_alias_routes_through_registered_adapter_and_dispatches_send_capi_event` failed with `Mockery\Exception: The class \Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher is marked final and its methods cannot be replaced.`
- **Issue:** The plan's Task 3 acceptance criteria require asserting that `ProductPageWatcher::dispatchForOfferSwitch` is called exactly once with `(42, 100)` from the ThemeAjaxHandler delegation path. The cleanest test-double for that delegation is a Mockery mock — but Mockery cannot mock `final` classes, and using an anonymous subclass triggers the same restriction at PHP level.
- **Fix:** Dropped `final` keyword from `ProductPageWatcher`. Production behavior unchanged — only extension surface opens for tests. Same fix pattern as plan 02-06's MetaClient `final`-drop (recorded in STATE.md Phase 2 decisions).
- **Files modified:** `classes/event/adapter/shopaholic/ProductPageWatcher.php` (line 32).
- **Commit:** `d0a940c`.

**2. [Rule 3 — Blocking] PHPStan level 10 `array<string, mixed>` narrowing on `SupportsHybridAjax::loadSubject` context parameter**

- **Found during:** Task 2 — phpstan reported `Parameter #2 $arContext of method SupportsHybridAjax::loadSubject() expects array<string, mixed>, array<mixed> given.`
- **Issue:** The naive `is_array($mContext) ? $mContext : []` narrowing produces `array<mixed>` because `$arData['context']` was indexed by `mixed`. PHPStan level 10 requires explicit string-key narrowing.
- **Fix:** Extracted `buildHybridContext(array): array<string, mixed>` private helper that walks the optional `context` sub-array with an `is_string($mKey)` guard plus overlays top-level `offer_id` when present. Same helper-narrowing idiom as `Settings::lookupForSite`'s `is_string($mValue)` guard and `MetaClient::decodeBody` (3rd repo use of the pattern).
- **Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php` (added `buildHybridContext`).
- **Commit:** `b396f8e`.

**3. [Rule 1 — Test bug] `?? 'unset'` fallback in test assertions converted null to non-null string**

- **Found during:** Task 3 — `test_plugin_guard_disabled_yields_null_twig_vars` + `test_collector_empty_yields_null_product_global` failed with `Failed asserting that 'unset' is null.`
- **Issue:** I initially wrote `$arPage['productPixelOfferSwitchJs'] ?? 'unset'` to defend against missing keys, then asserted null on the result — but `?? 'unset'` masks the null we want to verify.
- **Fix:** Removed the `?? 'unset'` fallback; the runComponent helper guarantees the key exists with either a string value or null (via is_string check). Direct assertion on the value catches the actual null contract.
- **Files modified:** `tests/Feature/Components/ProductPixelTest.php`.
- **Commit:** `d0a940c`.

### Authentication Gates

None.

### Architectural Deviations (Rule 4)

None — all auto-fixes were Rule 1 / Rule 3 scope.

## Deferred Issues

### Out-of-scope pre-existing failures (documented in deferred-items.md)

- `ThemeMarkupTagsTwigTest::test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires` — pre-existing failure unrelated to plan 06-06. Verified by stash-and-rerun (same 1 failure with or without my peek change). Owned by Phase 5 cleanup or Phase 6 verification triage.

## Unblock List

- **Plan 06-07 (docs):** ViewContent walkthrough section in README.md. The four-segment action_key canonicalization contract + the operator-zero-config `[productPixel]` placement guidance are docs-ready as of this plan.
- **Plan 05-09 (README):** Now safe to ship ViewContent funnel docs once 06-07 closes.
- **Plan 05-12 (CHANGELOG):** Now safe to list ProductPixel + ProductPageWatcher + ShopaholicProductAdapter under `### Added`.

## Self-Check: PASSED

- [x] `components/ProductPixel.php` exists (verified `ls`)
- [x] `components/productpixel/default.htm` exists (verified `ls`)
- [x] `classes/adapter/theme/ThemeEventCollector.php` has `peek()` (verified `grep -q 'public function peek'`)
- [x] `classes/adapter/theme/ThemeAjaxHandler.php` has `dispatchViaAdapter` (verified `grep -q`)
- [x] `Plugin.php` registers `ProductPixel::class => 'productPixel'` (verified `grep -q`)
- [x] `tests/Feature/Components/ProductPixelTest.php` 4 test methods (verified `grep -c`)
- [x] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` 5 test methods (verified `grep -c`)
- [x] All 9 new tests GREEN (verified pest run)
- [x] All 21 pre-existing AJAX handler tests still GREEN (verified pest run)
- [x] All 11 pre-existing ProductPageWatcher tests still GREEN (verified pest run)
- [x] PHPStan level 10 clean across all 5 touched production files (verified)
- [x] Commits 9c995f5, b396f8e, d0a940c present (verified `git log`)
