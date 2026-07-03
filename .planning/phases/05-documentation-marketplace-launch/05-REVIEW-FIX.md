---
phase: 05-documentation-marketplace-launch
fixed_at: 2026-07-03T14:55:00Z
review_path: .planning/phases/05-documentation-marketplace-launch/05-REVIEW.md
iteration: 1
findings_in_scope: 11
fixed: 10
skipped: 1
status: partial
---

# Phase 05: Code Review Fix Report

**Fixed at:** 2026-07-03T14:55:00Z
**Source review:** .planning/phases/05-documentation-marketplace-launch/05-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 11 (2 Critical + 9 Warning; fix_scope `critical_warning` excludes the 8 Info findings)
- Fixed: 10
- Skipped: 1 (WR-08)

**Quality gates (run after all fixes):**

| Gate | Result | Notes |
|------|--------|-------|
| `pint --test` | PASS | host Pint 1.29.1 |
| `phpstan analyse` (level 10) | PASS — 0 errors | Verified in the plugin tree after merge. (Full-tree phpstan runs from an out-of-tree worktree produce mass false positives — reflection/source-locator environment issue, reproduced at the base commit too; per-changed-file analysis and the final in-tree run are both clean.) |
| `phpmd` (Plugin.php,classes,models,console,components,middleware,controllers) | PASS — 0 violations | CR-02/WR-07 pushed ThemeAjaxHandler class complexity to 55 > 50; resolved by extracting `collectServerUserData` into ThemeAjaxRequestReader (commit 93edcad) |
| `pest` (full suite) | PASS — 583 tests, 2228 assertions | includes 12 new tests covering the fixes |
| `pest --coverage --min=90` | 87.0 % — below the 90 % CI gate | **Pre-existing:** the base commit (18cf535) measures 86.7 % in the identical local environment (Plugin.php 0 %, MetaClient 52.7 % dominate the gap). The ≥90 % gate is anchored to the full-Lovata CI matrix cell per plugin CLAUDE.md. Net effect of this fix session: **+0.3 pp** (86.7 → 87.0). |

## Fixed Issues

### CR-01: Transient CAPI failures permanently and silently drop events — retry defeated by race fence

**Files modified:** `classes/helper/EventLogWriter.php`, `classes/queue/SendCapiEvent.php`, `tests/Feature/Queue/SendCapiEventTransientRetryTest.php`, `tests/Feature/Adapter/BackboneIntegrationTest.php`, `tests/Feature/Adapter/EventLogWriterRaceFenceTest.php`
**Commits:** 742753d (fix), 37c3d8d (test alignment), c793172 (guard-branch coverage)
**Applied fix:** Added `EventLogWriter::ownsRow()` — a fence-key lookup comparing the stored `event_id` against the job's own. `SendCapiEvent::handle()` now proceeds past a fence collision when the existing row carries the SAME event_id (retry of self after a transient failure); different-event_id peers stay fenced. Fail-safe preserved: empty event_id, missing adapter, missing row, or DB read failure all return false (peer-wins). `BackboneIntegrationTest` dedup test updated to use a distinct peer event_id — a same-event_id re-dispatch now intentionally proceeds (Meta-side event_id dedup absorbs it). New tests: retry-after-503 delivers on attempt 2 with no FailedEvent; different-event_id peer never reaches Meta; 5 ownsRow guard/fail-safe branch tests.

### CR-02: onFireEvent theme path accepts client-controlled CAPI identity, secret_key, and site_id; never captures server-side user_data

**Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php`, `classes/adapter/theme/ThemeAjaxRequestReader.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerServerUserDataTest.php` (new), setUp mocks in 4 existing theme handler test files
**Commits:** d8b3050 (fix), 93edcad (complexity extraction)
**Applied fix:** Identity firewall on the plain theme-action branch — the client payload is stripped to `name` + `action_key` only (`array_intersect_key`), then merged with server-derived values: `client_ip_address` (Request::ip), `client_user_agent` (Request::userAgent), `fbp`/`fbc` (middleware-set cookies), and `site_id` baked in-request from `Site::getSiteIdFromContext()` so queue-side resolution never reads worker CLI context. Capture lives in `ThemeAjaxRequestReader::collectServerUserData()` (classes/adapter/theme/ is the documented D-16 exclusion zone). New tests prove injected `em`/`ph`/`external_id`/`fbp`/`fbc`/`client_ip_address`/`secret_key`/`site_id` are all discarded and server-captured user_data reaches the CAPI payload.

### WR-01: Cart adapter's request-context site fallback actually executes in the queue worker

**Files modified:** `classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php`
**Commit:** eceea1d
**Applied fix:** Reviewer's minimum option — the docblock no longer claims the false "never from queue worker rehydration" invariant. It now documents that the fallback also executes in the worker (CLI default site), why that is correct on the supported single-site-per-server deployment, the multisite single-DB caveat, and the path to a full fix (subject-carried site_id mirroring `ProductPageWatcher::makeDispatchEvent`). Consequence (b) of the finding — dedup-query site drift — is materially fixed by WR-06: `handleUpdated` now dedups against the pixel reservation row written in-request with the same request-context resolution. The full subject-carried rearchitecture is left as a product decision (would change fence partitioning for existing rows). Dangling CONTEXT.md references dropped from the rewritten docblock.

### WR-02: Generic hybrid dispatch injects top-level `action_key` into the outgoing Graph API payload

**Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php`
**Commit:** 76185eb
**Applied fix:** `dispatchGenericAdapter()` now passes `['action_key' => {wire}:{event_id}]` through PayloadBuilder's extras so it lands inside `custom_data`; nothing is appended at the Graph envelope top level. Test asserts no top-level `action_key` and the correct `custom_data.action_key` value.

### WR-03: Hybrid AJAX path ignores `getSupportedEvents()`; shopaholic branch echoes client-chosen event name over hard-coded ViewContent

**Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php`
**Commit:** 50ac69f
**Applied fix:** `dispatchViaAdapter()` rejects (422) any event name absent from the adapter's declared `getSupportedEvents()` matrix; `dispatchShopaholicOfferSwitch()` rejects (422) any name other than `ViewContent` — the delegate always dispatches CAPI ViewContent, so echoing another name would mint an unmatched server-blessed browser event. Two new tests cover both rejections.

### WR-04: dispatchForOfferSwitch fabricates content_ids for offer ids that do not belong to the product

**Files modified:** `classes/event/adapter/shopaholic/ProductPageWatcher.php`, `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php`
**Commit:** 08d2039
**Applied fix:** Tiger-Style hard failure — `resolveOfferContentData()` throws `RuntimeException` when `findOffer()` misses, instead of emitting a fabricated `SKU-{pid}-{oid}` that does not exist in the Facebook Catalog feed. The AJAX boundary surfaces the failure. New test: `dispatchForOfferSwitch(42, 999)` throws and dispatches nothing.

### WR-05: Offer-switch collector push is orphaned — dead write with latent double-emission risk

**Files modified:** `classes/event/adapter/shopaholic/ProductPageWatcher.php`, `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php`
**Commit:** 209a3b2
**Applied fix:** Removed the `ThemeEventCollector::push()` from `dispatchForOfferSwitch()` (the JsonResponse short-circuits before `cms.page.beforeRenderPage`, so the push could never flush; OfferSwitchResult already carries the browser custom_data). The test assertion cementing the orphan was inverted to assert the collector stays empty.

### WR-06: Browser AddToCart pixel races the queue worker — silently dropped on async queue drivers

**Files modified:** `classes/event/adapter/shopaholic/CartPositionWatcher.php`, `tests/Unit/Event/Adapter/Shopaholic/CartPositionWatcherTest.php`, `tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php`
**Commit:** 4d67e3b
**Applied fix:** `dispatchAddToCart()` now writes the `channel='pixel'` EventLog reservation row IN-REQUEST (via `EventLogWriter::record`) before dispatching the CAPI job — the browser wire never depends on worker completion. `resolveBrowserPixel()` reads that pixel row (renamed `findPixelAddToCartRow`) and is now read-only; `writePixelTwin()` removed. `handleUpdated()`'s qty-bump dedup now keys on the pixel row, which is visible immediately and written with the same request-context site resolution (also closing WR-01's dedup-drift consequence). A `Log::info` on the miss path gives operators drop-rate visibility. Tests reworked to seed/assert the reservation row; new test asserts the reservation event_id matches the dispatched CAPI payload's event_id.

### WR-07: handleFireEvent has no PluginGuard — disabled plugin still queues CAPI jobs and returns fbq scripts

**Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php`, + PluginGuard::reset() hygiene in 5 other theme handler test files
**Commit:** c3c1fbd
**Applied fix:** `handleFireEvent()` returns a soft-empty `{event_id: null, script: ''}` 200 when `PluginGuard::isDisabled()` — no dead-letter job queued, no fbq script injected into a page whose base pixel never rendered. `dispatchViaAdapter` inherits the guard. New test asserts the empty response and `Bus::assertNotDispatched`.

### WR-09: CUSTOM-ADAPTERS.md minimal example calls an undefined `$this->buildPayload()` — copy-paste fatal

**Files modified:** `docs/CUSTOM-ADAPTERS.md`
**Commit:** f472383
**Applied fix:** The AcmeCart minimal example now builds the payload with a runnable `(new PayloadBuilder(new UserDataHasher))->buildEventPayload(...)` call (imports included, `$this` no longer referenced inside the bound closure). Added a new "Build the payload" section before *Trigger dispatch* introducing PayloadBuilder + UserDataHasher, annotating every argument, and restating the event_id dedup-anchor contract. All doc-gate tests (CustomAdaptersStructureTest et al.) remain green.

### Supporting commits

- `93edcad` — extracted `collectServerUserData` into ThemeAjaxRequestReader to keep the phpmd `ExcessiveClassComplexity` gate green (CR-02/WR-07 had pushed ThemeAjaxHandler to 55 > 50).
- `0e25da8` — pint import-ordering normalization of test files touched by CR-01/WR-06.
- `c793172` — coverage tests for `ownsRow` guard/fail-safe branches.

## Skipped Issues

### WR-08: Google AddToCart tracked before the cart add succeeds

**File:** `themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:44-46`
**Reason:** Skipped — three compounding blockers for automated fixing:
1. The file lives in a **separate git repository** (`themes/logingrupa-naisstore`) whose working tree is currently dirty (uncommitted `layouts/main.htm`); committing there from this isolated background session risks racing foreground work.
2. The change only takes effect after a **webpack rebuild** (`pnpm run prod`) and a compiled `assets/js/common.js` bundle commit — rebuilding the theme bundle would sweep in unrelated uncommitted source state.
3. Moving Google AddToCart from click-time to success-time changes Google Ads **conversion-counting semantics** — the orchestrator flagged the current ordering as possibly intentional UX; needs a product decision.

**Original issue:** `onAddToCartClick()` fires `trackGoogleAddToCart(selectedOption)` synchronously on every click — including failed adds — while the Meta pixel correctly fires only in the success branch, so Google and Meta AddToCart counts diverge.
**Ready-to-apply fix for the developer** (move the call into the success branch of `addOfferToCart`, then rebuild + commit source and bundle together):
```js
if (response && response.status) {
  showButtonPopover(button, 'Item added to cart');
  refreshCartHeader();
  fireMetaAddToCartPixel(selectedOption.value);
  trackGoogleAddToCart(selectedOption);
}
```
and delete the `trackGoogleAddToCart(selectedOption);` line from `onAddToCartClick()`.

## Out of scope (fix_scope = critical_warning)

IN-01 through IN-08 were not attempted per the configured scope. Note: IN-03's stale "never throws" docblock was incidentally corrected as part of the WR-06 rewrite of `resolveBrowserPixel`'s docblock, and the CONTEXT.md pointer flagged by IN-05 in `ShopaholicCartPositionAdapter` was dropped during the WR-01 docblock rewrite. IN-01, IN-02, IN-04, IN-06, IN-07, IN-08 remain open.

---

_Fixed: 2026-07-03T14:55:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
