---
phase: 05-documentation-marketplace-launch
reviewed: 2026-07-03T14:05:00Z
depth: standard
files_reviewed: 34
files_reviewed_list:
  - classes/adapter/shopaholic/ShopaholicCartPositionAdapter.php
  - classes/adapter/shopaholic/ShopaholicSettingsOptions.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - classes/adapter/theme/ThemeAjaxRequestReader.php
  - classes/event/adapter/shopaholic/CartPositionWatcher.php
  - classes/event/adapter/shopaholic/ProductPageWatcher.php
  - classes/helper/EventLogWriter.php
  - classes/meta/AddToCartPixelResult.php
  - classes/meta/PayloadBuilder.php
  - classes/queue/SendCapiEvent.php
  - classes/testing/EventSubjectAdapterContractTestCase.php
  - components/PixelHead.php
  - docs/CUSTOM-ADAPTERS.md
  - tests/Feature/Adapter/BackboneIntegrationTest.php
  - tests/Feature/Adapter/EventLogWriterRaceFenceTest.php
  - tests/Feature/Adapter/Shopaholic/CartPositionWatcherBrowserPixelTest.php
  - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerAllowlistTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerFuzzingTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerMarkAddToCartTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerRateLimitTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerServerUserDataTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php
  - tests/Feature/Components/PixelHeadDeferredFlushTest.php
  - tests/Feature/Docs/AssetsExistTest.php
  - tests/Feature/Docs/CustomAdaptersStructureTest.php
  - tests/Feature/Docs/NoV1xReferencesTest.php
  - tests/Feature/Docs/ReadmeStructureTest.php
  - tests/Feature/Plugin/PluginYamlSanityTest.php
  - tests/Feature/Queue/SendCapiEventTransientRetryTest.php
  - tests/Unit/Event/Adapter/Shopaholic/CartPositionWatcherTest.php
  - tests/Unit/Meta/PayloadBuilderTest.php
  - ../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js
findings:
  critical: 0
  warning: 2
  info: 9
  total: 11
status: issues_found
---

# Phase 05: Code Review Report (post-fix re-review)

**Reviewed:** 2026-07-03T14:05:00Z
**Depth:** standard
**Files Reviewed:** 34
**Status:** issues_found

## Summary

Re-review after the gsd-code-fixer applied 14 commits (`742753d..c793172`,
diff base `18cf535`) against the previous 2-Critical / 9-Warning / 8-Info
report. Every claimed fix was verified against the code, not the fix report.

**Both Criticals are genuinely resolved.** CR-01's retry-aware fence is
race-safe: `SendCapiEvent::handle()` now falls through to
`EventLogWriter::ownsRow()` on fence collision, which claims ownership ONLY
when the existing fence row carries the job's own `event_id` (retry of self);
a peer row with a different `event_id` still fences, empty `event_id` never
claims ownership, and both DB-failure branches stay fail-safe
(`return false` = peer wins). The residual double-send window (two workers
executing the same redelivered job) is an explicit at-least-once trade-off
absorbed by Meta's same-event_id dedup and is documented in
`BackboneIntegrationTest`. `SendCapiEventTransientRetryTest` +
`EventLogWriterRaceFenceTest` exercise the retry-proceeds, peer-fenced,
empty-id, and DB-failure branches. CR-02's identity firewall is correct:
`handleFireEvent()` whitelists `name` + `action_key` from the client and
merges server-captured ip/UA/`_fbp`/`_fbc`/site_id via
`ThemeAjaxRequestReader::collectServerUserData()`;
`ThemeAjaxHandlerServerUserDataTest` proves both the strip and the capture.

WR-01…WR-07 and WR-09 verified fixed (WR-01 via the docblock correction the
prior review sanctioned as minimum — the docblock now honestly documents the
worker-context fallback and its single-site deployment assumption). WR-08 was
deliberately skipped as a product decision and is carried forward unchanged.

One new Warning: the fix wave added server user_data capture to the
theme-action branch and the offer-switch branch already injects request
user_data, but the **generic hybrid branch** (`dispatchGenericAdapter`) still
builds its payload from adapter `getUserData` alone — anonymous-subject
adapters produce empty-user_data CAPI events that Meta rejects permanently.
Of the 8 prior Info findings, IN-03 and IN-04 are resolved/moot; six carry
forward; three new Infos cover doc-drift introduced by the fixes and one
input-validation inconsistency on the untrusted AJAX boundary.

Fix verification matrix:

| ID | Status | Evidence |
|----|--------|----------|
| CR-01 | Fixed | `EventLogWriter::ownsRow` (classes/helper/EventLogWriter.php:105-146), guard at classes/queue/SendCapiEvent.php:119-121; tests green |
| CR-02 | Fixed | Firewall at classes/adapter/theme/ThemeAjaxHandler.php:116-122; capture at classes/adapter/theme/ThemeAjaxRequestReader.php:28-43 |
| WR-01 | Fixed (docblock option) | ShopaholicCartPositionAdapter.php:21-33 CAVEAT block documents worker-context execution |
| WR-02 | Fixed | action_key now routed through builder extras into custom_data (ThemeAjaxHandler.php:313-321); asserted in ThemeAjaxHandlerSubjectTypeTest:374-377 |
| WR-03 | Fixed | getSupportedEvents gate (ThemeAjaxHandler.php:253-255) + ViewContent pin (278-280); 422 tests present |
| WR-04 | Fixed | resolveOfferContentData throws on foreign offer (ProductPageWatcher.php:253-258); test asserts no dispatch |
| WR-05 | Fixed | Collector push removed (ProductPageWatcher.php:208-213 comment); test asserts empty collector |
| WR-06 | Fixed | In-request `channel='pixel'` reservation (CartPositionWatcher.php:102-111); resolver reads it (143); dedup keys on it (53-59) |
| WR-07 | Fixed | PluginGuard soft-empty at top of handleFireEvent (ThemeAjaxHandler.php:96-98); test asserts no dispatch, no script |
| WR-08 | NOT fixed (deliberate skip) | carried forward below |
| WR-09 | Fixed | Phantom `buildPayload()` replaced with runnable PayloadBuilder call + new "Build the payload" section (docs/CUSTOM-ADAPTERS.md:114-133, 236-258) |

## Warnings

### WR-08: Google AddToCart tracked before the cart add succeeds (carried forward — deliberately skipped)

**File:** `../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:44-45`
**Issue:** `onAddToCartClick()` still calls `trackGoogleAddToCart(selectedOption)` synchronously right after kicking off the unawaited `addOfferToCart(...)` promise. Google AddToCart fires on every click — including failed adds (out of stock, validation error, network failure) — while the Meta pixel (`fireMetaAddToCartPixel`) correctly fires only inside the `response.status` success branch. Google and Meta AddToCart counts diverge; Google conversions include adds that never happened. Skipped by the fixer as a product decision — carried forward so the decision stays visible.
**Fix:** Move the call into the success branch of `addOfferToCart`:
```js
if (response && response.status) {
  showButtonPopover(button, 'Item added to cart');
  refreshCartHeader();
  fireMetaAddToCartPixel(selectedOption.value);
  trackGoogleAddToCart(selectedOption);
}
```

### WR-10: Generic hybrid AJAX dispatch never injects request user_data — anonymous-subject events ship empty user_data and permanently dead-letter

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:307-327` (contrast: `classes/adapter/theme/ThemeAjaxHandler.php:122`, `classes/event/adapter/shopaholic/ProductPageWatcher.php:201`)
**Issue:** After the CR-02 fix, all three AJAX dispatch branches EXCEPT one carry request-derived user_data: the theme-action branch merges `collectServerUserData()` (line 122), and the shopaholic offer-switch branch delegates to `dispatchForOfferSwitch`, which calls `injectRequestUserData()` (ProductPageWatcher.php:201). `dispatchGenericAdapter()` builds its payload solely from the adapter's `getUserData($obSubject)` and dispatches as-is. A third-party hybrid adapter whose subject carries no PII (the documented pattern — see `ShopaholicCartPositionAdapter::getUserData` returning 13 nulls, and the CUSTOM-ADAPTERS.md Mall example returning null `fbp`/`fbc`/`client_ip_address`/`client_user_agent` for guest orders) yields a CAPI event with empty `user_data`. Per the plugin's own contract documentation (PixelHead.php:155-157, ThemeAjaxRequestReader.php:22-24), Meta rejects such events with HTTP 400 subcode 2804050 — a `MetaApiPermanentException` that dead-letters every guest-originated generic-hybrid event (one `FailedEvent` row per call). The request context is available at this exact point and is already used two lines up in the same class.
**Fix:** Merge the server capture into the built payload's `user_data` before dispatch (ThemeAjaxHandler lives in `classes/adapter/theme/`, the documented D-16 exclusion zone, so the reader call is permitted):
```php
$arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(/* … */);
$arServerData = $this->obRequestReader->collectServerUserData();
unset($arServerData['site_id']);
foreach ($arServerData as $sKey => $mValue) {
    if ($mValue !== null && ($arPayload['data'][0]['user_data'][$sKey] ?? null) === null) {
        $arPayload['data'][0]['user_data'][$sKey] = $mValue;
    }
}
SendCapiEvent::dispatch($sName, $arPayload, $obSubject, $sAdapterClass);
```
(Adapter-supplied non-null values must win, mirroring `CapturesRequestUserData::injectRequestUserData` semantics.)

## Info

### IN-01: `$tries` docblock contradicts the code — third backoff entry unreachable (carried forward)

**File:** `classes/queue/SendCapiEvent.php:62-66`
**Issue:** The comment says "1 initial + 3 backoffs = 4 total tries", but `$tries = 3` means 3 total attempts in Laravel; `backoff[2] = 16` is never used (only two delays occur before `failed()` runs). Note this now also interacts with the CR-01 fix: the retry contract the fence protects allows only 2 actual re-sends, not 3.
**Fix:** Either set `public int $tries = 4;` to match the comment, or fix the comment to "3 total attempts" and trim `$backoff` to `[1, 4]`.

### IN-02: Orphaned docblock stacked above withTestEventCode; writeFailedEvent left undocumented (carried forward)

**File:** `classes/queue/SendCapiEvent.php:253-267,282`
**Issue:** The "Persist a FailedEvent row…" docblock (lines 253-258) is immediately followed by a second docblock (259-267) and then `withTestEventCode()` — the first block is dangling and belongs to `writeFailedEvent()` (line 282), which has no docblock at all.
**Fix:** Move the FailedEvent docblock down to directly precede `writeFailedEvent()`.

### IN-05: Planning-artifact references in shipped docblocks (carried forward — locations updated)

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:213-214`; `classes/adapter/shopaholic/ShopaholicProductAdapter.php:18`; `components/ProductPixel.php:98`
**Issue:** Docblocks on public marketplace surface still reference internal planning artifacts ("per CONTEXT.md Claude's Discretion", "CONTEXT.md D-15"). `.planning/` does not ship with the marketplace package, making these references dangling for third parties, and the plugin's no-comment-pollution rule bans workflow markers in source. (The `ShopaholicCartPositionAdapter` occurrences from the prior review were cleaned up by the WR-01 docblock rewrite; these three remain. The `NoV1xReferencesTest` gate scans for "Phase N" but not "CONTEXT.md", so nothing catches these.)
**Fix:** Restate the decision content inline and drop the CONTEXT.md pointers; optionally extend the D-23 gate regex to cover `CONTEXT\.md`.

### IN-06: Dead ReflectionProperty setup in offer-switch test (carried forward)

**File:** `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php:392-395`
**Issue:** `$obReflect = new ReflectionProperty(Offer::class, 'fSavedPrice'); $obReflect->setAccessible(true);` is never used — the actual pre-seeding happens inside `makeOffer()`. Dead code with a misleading comment above it.
**Fix:** Delete lines 392-395 (comment + reflection setup).

### IN-07: Contract-test invariants 03/04 assert less than their names claim (carried forward)

**File:** `classes/testing/EventSubjectAdapterContractTestCase.php:81-102`
**Issue:** `test_invariant_03_site_id_deterministic_across_set_site_context` never varies any site context — it calls `getSiteId` twice back-to-back. `test_invariant_04_get_site_id_reads_no_request_or_site_manager` asserts only the return type; it cannot detect Request/SiteManager reads. Third-party adapters — the harness's marketplace audience — are not covered by this repo's phpstan config, so for them the invariant is unenforced.
**Fix:** In invariant 03, flip the active site between the two calls before asserting equality; rename invariant 04 to reflect what it asserts, or strengthen it (swap the bound request instance and assert the result is unchanged).

### IN-08: Status dropdown pluck bypasses translation accessors (carried forward)

**File:** `classes/adapter/shopaholic/ShopaholicSettingsOptions.php:30`
**Issue:** `Status::orderBy('sort_order')->pluck('name', 'code')` reads the raw column via the query builder, bypassing Lovata's multilingual (RainLab.Translate) accessor on `name` — backend operators on non-default locales see untranslated status names; duplicate `code` values silently collapse to the last row.
**Fix:** Hydrate models so pluck goes through the accessor: `Status::orderBy('sort_order')->get()->pluck('name', 'code')->all();`

### IN-09: AddToCartPixelResult docblock stale after WR-06 fix — still says "capi EventLog row"

**File:** `classes/meta/AddToCartPixelResult.php:5-10`
**Issue:** The class docblock reads "the server-generated capi AddToCart event_id plus the browser-facing custom_data copied from the capi EventLog row". Since commit 4d67e3b (WR-06 fix), `resolveBrowserPixel` reads the in-request `channel='pixel'` reservation row, never the worker-written capi row — the CartPositionWatcher docblocks were updated but this value object's was not. A maintainer trusting it would reintroduce the queue-latency race the fix removed.
**Fix:** Reword to "…the server-generated AddToCart event_id plus the browser-facing custom_data copied from the in-request `channel='pixel'` reservation row written by `dispatchAddToCart`".

### IN-10: CUSTOM-ADAPTERS.md minimal example missing imports — `App` and `PluginBase` unresolved in a namespaced Plugin.php

**File:** `docs/CUSTOM-ADAPTERS.md:100-133`
**Issue:** The WR-09 fix made the payload build runnable, but the minimal Plugin.php snippet's `use` block imports only `AdapterRegistry`, `PayloadBuilder`, `UserDataHasher`, `SendCapiEvent`, and `Uuid`. The code calls `App::make(...)` and declares `class Plugin extends PluginBase` — in a namespaced plugin file (October plugins are always namespaced; the snippet omits the namespace line) both resolve to `Acme\CustomCart\App` / `Acme\CustomCart\PluginBase` and fatal on copy-paste.
**Fix:** Add the namespace line plus `use Illuminate\Support\Facades\App;` and `use System\Classes\PluginBase;` to the snippet's import block.

### IN-11: Client-controlled `action_key` accepted unbounded on the unauthenticated AJAX boundary

**File:** `classes/adapter/theme/ThemeAjaxHandler.php:121,311-312` (with `classes/adapter/theme/ThemeActionEvent.php:38-44`)
**Issue:** After CR-02, `action_key` is one of only two client-controlled fields surviving the identity firewall, yet it has no length or charset validation — `ThemeActionEvent::fromArray` requires only "non-empty string" (its no-length-cap note targets operator-supplied Twig keys, not anonymous visitors). Every other untrusted input on this boundary is tightly bounded (event_name fuzzing matrix, `offer_id` int coercion, subject_type registry binding, fbclid charset lock). A multi-megabyte `action_key` is serialized into the queue job payload (theme branch) or shipped inside `custom_data` to Graph (generic hybrid branch, where oversize params trigger permanent errors → FailedEvent rows), throttled only by the 30/min rate limit.
**Fix:** Cap client-supplied `action_key` at the AJAX boundary (e.g. reject `strlen > 255` with 422 in `handleFireEvent`/`dispatchGenericAdapter`), leaving the Twig/server-side push path uncapped as documented.

## Resolved since previous review

- **CR-01** — retry-aware race fence (`ownsRow`); transient failures now actually retry and deliver; peer duplicates still fenced; fail-safe direction preserved on all failure branches.
- **CR-02** — identity firewall (client controls only `name` + `action_key`) + server-side user_data/site capture on the theme-action path.
- **WR-01** — docblock now documents the worker-context fallback honestly (the review's sanctioned minimum); WR-06's redesign additionally made the qty-bump dedup compare like-for-like request-context values.
- **WR-02** — hybrid wire `action_key` carried inside `custom_data`, no top-level Graph parameter.
- **WR-03** — `getSupportedEvents()` gate + offer-switch pinned to ViewContent (422 otherwise).
- **WR-04** — foreign `offer_id` throws instead of fabricating a catalog SKU.
- **WR-05** — orphaned collector push removed from the AJAX offer-switch path (test now asserts the collector stays empty).
- **WR-06** — browser AddToCart pixel reads the in-request `channel='pixel'` reservation; no queue-worker race on async drivers.
- **WR-07** — `handleFireEvent` PluginGuard soft-empty response; disabled installs queue nothing and emit no fbq.
- **WR-09** — adapter guide's phantom `buildPayload()` replaced with a runnable `PayloadBuilder` example plus a "Build the payload" section.
- **IN-03** — `resolveBrowserPixel` docblock reworded to "returns null on every resolution miss; infrastructure failures propagate to the AJAX boundary's catch".
- **IN-04** — moot: WR-05 removed the only collector push that included `offer_id`, so the strip-list omission no longer has a producer.

---

_Reviewed: 2026-07-03T14:05:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
