---
phase: 05-documentation-marketplace-launch
plan: 15
subsystem: api
tags: [meta-pixel, capi, event-id-dedup, shopaholic-cart, add-to-cart, fbq, october-ajax]

# Dependency graph
requires:
  - phase: 05-documentation-marketplace-launch
    provides: "CartPositionWatcher server CAPI AddToCart (eloquent.created), EventPixel twin-row pattern, FbqScriptBuilder, ThemeAjaxHandler hybrid AJAX boundary, ProductPageWatcher offer-switch OfferSwitchResult precedent"
provides:
  - "AddToCartPixelResult value object (server event_id + browser custom_data)"
  - "CartPositionWatcher::resolveBrowserPixel — reads capi AddToCart event_id, writes pixel twin, no second CAPI"
  - "ThemeAjaxHandler Metapixel::onMarkAddToCart PIXEL-ONLY AJAX branch"
  - "Theme add-to-cart-control.js browser fbq AddToCart wire via October $.request + createContextualFragment"
affects: [05-16, 05-UAT-CUTOVER]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Browser-pixel resolver: read already-generated capi EventLog row, copy event_id + custom_data byte-identical, write channel='pixel' twin via insertOrIgnore race-fence (mirrors EventPixel but from a server watcher)"
    - "PIXEL-ONLY AJAX branch alongside the CAPI-dispatching onFireEvent — a second recognized handler name routed before the onFireEvent guard"

key-files:
  created:
    - classes/meta/AddToCartPixelResult.php
    - tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php
    - tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php
  modified:
    - classes/event/adapter/shopaholic/CartPositionWatcher.php
    - classes/adapter/theme/ThemeAjaxHandler.php
    - "../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js (separate theme git repo)"

key-decisions:
  - "getCartObject() null-guard replaced by cart-id guard: Lovata init() always yields a Cart (creates one), and phpstan treatPhpDocTypesAsCertain:true rejects a null check on the @return Cart docblock — the reachable fail-safe is an id-less cart"
  - "De-finalized CartPositionWatcher to enable container-mock at the AJAX boundary, matching the existing non-final ProductPageWatcher precedent"
  - "custom_data copied from the stored capi payload (local extractCustomData helper) — NOT re-derived — guaranteeing browser fbq == server CAPI byte-for-byte"

patterns-established:
  - "Pattern: server watcher exposes a resolveBrowserPixel(int): ?Result reader that reuses the CAPI event_id and writes the pixel twin, keeping the browser wire free of any second CAPI dispatch"

requirements-completed: [D-07]

coverage:
  - id: D1
    description: "resolveBrowserPixel returns the capi AddToCart event_id + byte-identical custom_data and writes exactly one idempotent channel='pixel' twin row"
    requirement: D-07
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "resolveBrowserPixel dispatches NO second SendCapiEvent and null-returns on every fail-safe branch (disabled, offer_id<=0, no cart id, no position, no capi row)"
    requirement: D-07
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php#test_dispatches_no_send_capi_event"
        status: pass
    human_judgment: false
  - id: D3
    description: "Metapixel::onMarkAddToCart returns {event_id, script} with the server event_id; 422 invalid offer_id, 429 rate limit, 200 empty-script on null, 500 on Throwable; onFireEvent unchanged"
    requirement: D-07
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "Theme add-to-cart success fires $.request('Metapixel::onMarkAddToCart') and injects the executable fbq via createContextualFragment; Google tracking + cart-add untouched"
    verification:
      - kind: manual_procedural
        ref: "05-16 real-browser UAT after pnpm run prod + FPM reload"
        status: unknown
    human_judgment: true
    rationale: "Webpack ES-module theme JS has no JS-execution harness in this repo; correctness (fbq fires with matching eventID in the browser) is verifiable only in a real browser after the theme asset rebuild in 05-16 (offer-switch precedent, STATE 260619-osj)"

# Metrics
duration: 16min
completed: 2026-07-02
status: complete
---

# Phase 05 Plan 15: Browser AddToCart event_id dedup wire (D-07) Summary

**Browser fbq AddToCart now reuses the server-generated CAPI event_id + full custom_data (content_ids, contents, num_items, value, currency) via a pixel-only resolver + AJAX branch + theme $.request wire, so Meta deduplicates the browser+server pair by event_id instead of the fragile fbp fallback.**

## Performance

- **Duration:** ~16 min (implementation)
- **Started:** 2026-07-02T16:34:06+03:00
- **Completed:** 2026-07-02T16:49:45+03:00
- **Tasks:** 3
- **Files modified:** 5 created/modified (2 plugin src, 2 plugin tests, 1 theme JS in a separate repo)

## Accomplishments
- `CartPositionWatcher::resolveBrowserPixel` reads the already-generated `channel='capi'` AddToCart EventLog row for the current-session cart position, copies its `event_id` + `custom_data` byte-identical, and writes the `channel='pixel'` twin row via an idempotent `insertOrIgnore` race-fence — dispatching NO second `SendCapiEvent`.
- `Metapixel::onMarkAddToCart` PIXEL-ONLY AJAX branch returns `{event_id, script}` with the server event_id (422 invalid offer_id / 429 rate-limit / 200 empty-script on null / 500 on Throwable), leaving the CAPI-dispatching `onFireEvent` path untouched.
- Theme `add-to-cart-control.js` fires the follow-up `$.request('Metapixel::onMarkAddToCart')` on cart-add success and injects the returned executable `<script>fbq(...)</script>` through `createContextualFragment` (which parses AND runs it); the existing Cart::onAdd + Google tracking calls are unchanged.

## Task Commits

Plugin repo (`plugins/logingrupa/metapixel`):
1. **Task 1 (TDD): resolver + value object** — `2e47e84` (test RED), `28b639e` (feat GREEN)
2. **Task 2 (TDD): onMarkAddToCart AJAX branch** — `c75800a` (test RED), `f0b59ee` (feat GREEN), `6155f46` (test: cover 500 boundary)

Theme repo (`themes/logingrupa-naisstore`, separate git repo):
3. **Task 3: theme browser fbq wire** — `471b980` (feat)

_Task 3's file is tracked by the theme git repo, not the plugin repo — committed there per the plan's cross-repo note. An unrelated pre-existing `layouts/main.htm` working change in that repo was left untouched._

## Files Created/Modified
- `classes/meta/AddToCartPixelResult.php` (new) — readonly value object: server `sEventId` + browser `arCustomData`.
- `classes/event/adapter/shopaholic/CartPositionWatcher.php` — added `resolveBrowserPixel` + `resolveCurrentCartId`/`resolvePositionId`/`findCapiAddToCartRow`/`extractCustomData`/`writePixelTwin`; de-finalized the class.
- `classes/adapter/theme/ThemeAjaxHandler.php` — `HANDLER_MARK_ADD_TO_CART` const + `markAddToCartPixel()` branch delegating to the watcher.
- `tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php` (new) — 8 tests, class 92.0% covered.
- `tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php` (new) — 8 tests.
- `themes/.../partials/shared/controls/add-to-cart-control.js` — `fireMetaAddToCartPixel(offerId)`.

## Decisions Made
- **Cart null-guard → cart-id guard.** Lovata's `CartProcessor::getCartObject()` is documented `@return Cart` and `init()` always yields a Cart (creating one if absent). Under the plugin's `treatPhpDocTypesAsCertain: true`, a `=== null` / `instanceof` guard is flagged as always-true/false. The reachable fail-safe (an id-less session cart) is guarded via `is_numeric(id)` instead — same protective intent, phpstan-clean. The test's "no cart" case now stubs an id-less Cart.
- **De-finalized `CartPositionWatcher`.** `Mockery` cannot mock a `final` class; the sibling `ProductPageWatcher` is already non-final for exactly this container-mock boundary pattern (`App::make(...)` in `ThemeAjaxHandler`). Aligned for consistency.
- **custom_data copied, not re-derived.** A local `extractCustomData` walks the stored capi `payload → data[0] → custom_data`, guaranteeing browser/server byte-parity (mirrors `EventPixel::extractCustomData` without a drive-by refactor of `EventPixel`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] getCartObject() null-guard un-typeable under phpstan L10**
- **Found during:** Task 1 (GREEN)
- **Issue:** The plan's step-2 `getCartObject(); null → null` guard is rejected by `treatPhpDocTypesAsCertain: true` (the upstream `@return Cart` docblock makes a null/instanceof check always-true/false → L10 error).
- **Fix:** Guard on the resolved cart id (`is_numeric` → `<= 0` → null) — the reachable "no established cart" fail-safe. Test updated to stub an id-less Cart.
- **Files modified:** classes/event/adapter/shopaholic/CartPositionWatcher.php, tests/.../CartPositionWatcherBrowserPixelTest.php
- **Verification:** phpstan L10 clean; 8 resolver tests pass.
- **Committed in:** 28b639e

**2. [Rule 3 - Blocking] CartPositionWatcher was final, unmockable at the AJAX boundary**
- **Found during:** Task 2 (GREEN)
- **Issue:** `Mockery::mock(CartPositionWatcher::class)` fails on a `final` class, blocking boundary tests for `markAddToCartPixel`.
- **Fix:** Removed `final` (ProductPageWatcher precedent).
- **Files modified:** classes/event/adapter/shopaholic/CartPositionWatcher.php
- **Verification:** ThemeAjaxHandlerMarkAddToCartTest 8/8 pass.
- **Committed in:** f0b59ee

---

**Total deviations:** 2 auto-fixed (2 blocking). **Impact:** both required to satisfy the repo's own L10 + test tooling; no scope creep.

## Issues Encountered
- **phpmd complexity ceiling on `ThemeAjaxHandler` (pre-existing).** After adding the mark branch, phpmd reports `ExcessiveClassComplexity` (WMC 50) and `onBeforeRun` CyclomaticComplexity 10. Root cause is the pre-existing `dispatchViaAdapter` (CyclomaticComplexity 14 / NPath 1024 — present at HEAD; phpmd already exits 2 on the untouched tree). WMC is the sum of method complexities, so extraction cannot lower it — only simplifying pre-existing `dispatchViaAdapter`, which is out of scope (no drive-by refactor). Logged to `deferred-items.md` with a suggested follow-up split.

## Verification Results
- `pest` targeted + regression: **81 → 42 (theme) all green** (CartPositionWatcherBrowserPixelTest 8, ThemeAjaxHandlerMarkAddToCartTest 8, full Shopaholic + Theme + Unit CartPositionWatcher dirs).
- `phpstan analyse --level=10` (incl. `spaze/phpstan-disallowed-calls`): **no errors** on all three changed PHP files; `CartPositionWatcher` resolver path stays free of `Request`/`Site`/`SiteManager`.
- `pint --test`: **passed** on all changed files.
- Grep gates: `Metapixel::onMarkAddToCart` + `createContextualFragment` present in the theme JS; `SendCapiEvent::dispatch` present ONLY in the pre-existing `dispatchAddToCart` (line 91), NOT in `resolveBrowserPixel` or its helpers.
- Coverage (scoped): `AddToCartPixelResult` 100%, `CartPositionWatcher` 92.0% (new resolver path fully covered), `markAddToCartPixel` all branches incl. 500 boundary exercised.
- `phpmd`: pre-existing exit-2 ceiling (see Issues) — deferred, not a green gate in this repo today.

## User Setup Required
None - no external service configuration. Theme asset rebuild (`pnpm run prod`) + FPM reload + real-browser AddToCart verification are captured in the follow-up plan 05-16, not here.

## Next Phase Readiness
- Server + AJAX + theme wire complete and unit-verified. Ready for **05-16** (theme asset rebuild + real-browser UAT test 9 close-out).
- Browser fbq correctness (matching eventID, no double CAPI) is only observable after the 05-16 webpack build — deliverable D4 is human-judgment pending that build.

## Self-Check: PASSED

All created files exist on disk; all 5 plugin commits + 1 theme-repo commit verified in git history.

---
*Phase: 05-documentation-marketplace-launch*
*Completed: 2026-07-02*
