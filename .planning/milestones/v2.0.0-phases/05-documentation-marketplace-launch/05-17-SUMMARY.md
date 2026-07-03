---
phase: 05-documentation-marketplace-launch
plan: 17
subsystem: api
tags: [meta-capi, viewcontent, eventlog-race-fence, theme-action-adapter, payload-builder, dedup]

# Dependency graph
requires:
  - phase: 05-documentation-marketplace-launch
    provides: "05-15 AddToCart browser event_id wire; ThemeActionAdapter per-view PageView idiom (PixelHead)"
  - phase: 06-viewcontent-funnel-shopaholic-pdp
    provides: "ProductPageWatcher ViewContent dispatch + ThemeEventCollector deferred flush"
provides:
  - "Per-view ViewContent server CAPI dispatch (fires on every PDP view, not once-per-product-ever)"
  - "PayloadBuilder zero-junk strip for contentless CAPI events (clean PageView custom_data)"
affects: [viewcontent, capi-dedup, eventlog-race-fence, meta-test-events]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Route a content-bearing subject's CAPI dispatch through a per-view ThemeActionEvent subject + ThemeActionAdapter so the EventLog UNIQUE race-fence keys on crc32(action_key) — decouples fence granularity from the domain subject id without widening EventSubjectAdapter or touching the fence columns"
    - "Contentless-subject discriminator (empty content_ids) gates zero-value custom_data stripping in PayloadBuilder"

key-files:
  created: []
  modified:
    - classes/event/adapter/shopaholic/ProductPageWatcher.php
    - classes/meta/PayloadBuilder.php
    - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
    - tests/Unit/Meta/PayloadBuilderTest.php

key-decisions:
  - "ViewContent CAPI dispatch subject switched from Product/ShopaholicProductAdapter to a per-view ThemeActionEvent/ThemeActionAdapter; payload still built by ShopaholicProductAdapter + resolver (unchanged)"
  - "site_id baked into the ThemeActionEvent from the product subject at dispatch time (via adapter->getSiteId) so queue-side resolution never touches request/SiteManager (P-01)"
  - "PayloadBuilder zero-strip gated on empty content_ids (contentless = zero-value subject) instead of the literal per-key value===0.0 drop, to keep value-bearing events byte-identical and the full suite green"

patterns-established:
  - "Per-view fence routing: wrap a per-view action_key in ThemeActionEvent so getSubjectId hashes the unique view, not the product"
  - "Zero-junk custom_data strip: only when content_ids is empty"

requirements-completed: [D-07]

coverage:
  - id: D1
    description: "Two consecutive PDP views of the same product each dispatch a ViewContent CAPI event whose fence subject is distinct — a new view of a previously-viewed product is never silently fenced"
    requirement: "D-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php#test_event_log_race_fence_does_not_block_per_pageload_duplicates"
        status: pass
    human_judgment: false
  - id: D2
    description: "handle() ViewContent dispatch routes via ThemeActionAdapter with a per-view ThemeActionEvent subject (action_key viewcontent:{pid}:{eid}); browser/server event_id pairing preserved"
    requirement: "D-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php#test_viewcontent_dispatches_capi_and_pushes_collector_on_shopaholic_product_open"
        status: pass
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php#test_capi_payload_event_id_matches_collector_pushed_event_id"
        status: pass
    human_judgment: false
  - id: D3
    description: "Offer-switch ViewContent dispatch is per-switch unique (action_key viewcontent:{pid}:{oid}:{eid}) via ThemeActionAdapter routing"
    requirement: "D-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php#test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku"
        status: pass
    human_judgment: false
  - id: D4
    description: "Contentless CAPI events (PageView / any zero-value subject) carry no junk custom_data — no value:0, num_items:0, or empty contents; value-bearing events unchanged"
    verification:
      - kind: unit
        ref: "tests/Unit/Meta/PayloadBuilderTest.php#test_zero_value_contentless_event_drops_value_currency_num_items_contents"
        status: pass
      - kind: unit
        ref: "tests/Unit/Meta/PayloadBuilderTest.php#test_envelope_subject_agnostic_same_adapter_different_events"
        status: pass
    human_judgment: false
  - id: D5
    description: "Live PDP + offer-switch produce browser/server ViewContent dedup pairs on every view, and Meta Test Events shows clean PageView custom_data"
    verification: []
    human_judgment: true
    rationale: "Requires real browser + live Meta Events Manager Test Events panel + php-fpm reload; no automated harness for Meta ingestion or fbq browser execution in this repo"

# Metrics
duration: ~14min
completed: 2026-07-02
status: complete
---

# Phase 05 Plan 17: ViewContent Per-View Fence + PageView Payload Cleanup Summary

**ViewContent server CAPI now fires on every PDP view (via a per-view ThemeActionEvent subject that re-keys the EventLog race-fence) and PageView CAPI stops shipping junk value:0/num_items:0 custom_data to Meta.**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-07-02T18:55Z (approx)
- **Completed:** 2026-07-02T19:05Z
- **Tasks:** 2 (both TDD)
- **Files modified:** 4

## Accomplishments
- Fixed the UAT-discovered gap where server ViewContent fired only ONCE PER PRODUCT EVER: `ProductPageWatcher::handle` + `dispatchForOfferSwitch` now dispatch `SendCapiEvent` with a per-view `ThemeActionEvent` subject + `ThemeActionAdapter` routing (mirroring the proven `PixelHead` PageView idiom), so the EventLog UNIQUE race-fence keys on `crc32(action_key)` (per-view) instead of the product id.
- Preserved the browser/server `event_id` dedup pair and the prebuilt `ShopaholicProductAdapter` + resolver payload — only the dispatch subject/adapter routing changed.
- Baked `site_id` into the `ThemeActionEvent` from the product subject at dispatch time so queue-side resolution stays request-independent (P-01), never calling `SiteManager`/`request()` from `classes/event/`.
- Removed junk zero-value `custom_data` from contentless CAPI events (PageView): `PayloadBuilder` drops `value:0.0` / `num_items:0` / empty `contents` (and `currency`, meaningless without value) when the subject resolves no `content_ids`.

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1 (RED): ViewContent per-view fence test** - `af9951e` (test)
2. **Task 1 (GREEN): ViewContent per-view CAPI dispatch via ThemeActionEvent** - `e094fa3` (fix)
3. **Task 2 (RED): PayloadBuilder zero-drop test** - `05a5efb` (test)
4. **Task 2 (GREEN): drop zero/empty custom_data for contentless events** - `da61799` (fix)

**Plan metadata:** see final docs commit (SUMMARY + STATE).

## Files Created/Modified
- `classes/event/adapter/shopaholic/ProductPageWatcher.php` - ViewContent + offer-switch CAPI dispatch now wraps a per-view action_key in a `ThemeActionEvent` (new `makeDispatchEvent` helper) routed via `ThemeActionAdapter`; site_id baked from the product subject.
- `classes/meta/PayloadBuilder.php` - `buildEventPayload` strips zero/empty custom_data (value/currency/num_items/contents) when `content_ids` is empty.
- `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` - Asserts ThemeActionAdapter routing + per-view fence distinctness (real `EventLogWriter::record` → 2 rows for 2 views); booted `AddPayloadToMetapixelEventLogTable` for the payload column; enhanced offer-switch assertion.
- `tests/Unit/Meta/PayloadBuilderTest.php` - New contentless zero-value test asserting empty custom_data.

## Decisions Made
- **Fence routing via ThemeActionEvent, not a new EventSubjectAdapter surface.** The smallest change that makes the fence per-view is reusing the existing `ThemeActionAdapter` (whose `getSubjectId` hashes `action_key`). No new interface methods, no fence-column changes, no fence removal — same-view retry dedup survives (subject_id is identical for a retried view).
- **site_id resolved from the product subject at dispatch time.** `$obAdapter->getSiteId($obProduct)` (a plain adapter method call, not a banned `SiteManager`/`Site`/`request` call) yields the same site the product subject would have resolved, baked into the `ThemeActionEvent` payload so queue-side resolution is deterministic.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Plan self-contradiction resolved] PayloadBuilder zero-strip gated on empty content_ids rather than literal per-key `value===0.0` drop**
- **Found during:** Task 2 (PayloadBuilder)
- **Issue:** The plan action text says "drop 'value' when 0.0" unconditionally, but the same task's `done` criterion requires value-bearing events (AddToCart/Purchase/ViewContent) to stay byte-identical and the full suite to stay green. `PurchaseFlowIntegrationTest` asserts `custom_data.value` present + `currency` = EUR, yet its hermetic SQLite fixture makes the Purchase's Lovata `total_price_value` accessor return **0.0** (documented in that test). An unconditional value===0.0 drop would strip value+currency from the Purchase and break two assertions — contradicting the `done` criterion.
- **Fix:** Gated the whole zero-strip block on `content_ids === []` (a genuinely contentless / zero-value subject = PageView, per must_have truth "PageView (and any zero-value subject)"). Content-bearing subjects (non-empty content_ids: Purchase/ViewContent/AddToCart) skip the strip entirely and stay byte-identical, including the 0.0 Purchase fixture.
- **Files modified:** classes/meta/PayloadBuilder.php
- **Verification:** New `test_zero_value_contentless_event_drops_value_currency_num_items_contents` green; existing `PurchaseFlowIntegrationTest` + all 6 PayloadBuilder tests green; phpstan L10 clean.
- **Committed in:** da61799 (Task 2 GREEN commit)

---

**Total deviations:** 1 auto-fixed (1 plan self-contradiction resolution).
**Impact on plan:** Necessary to satisfy the must_haves + keep the suite green. No scope creep — the fix is a strictly more precise reading of "PageView (and any zero-value subject)".

## Issues Encountered
None beyond the deviation above. The two live-production hotfixes from earlier today (36b7244 autoConfig, 867bb3c top-level offer_id) were left untouched.

## User Setup Required
None - no external service configuration required. Note: production deploy requires `sudo systemctl reload php8.4-fpm` (OPcache `validate_timestamps=0`) for the PHP changes to take effect, and live Meta Test Events verification (coverage D5) is a human UAT step.

## Next Phase Readiness
- Server ViewContent now fires per-view with browser/server `event_id` pairing intact; the fence still dedups same-view retries. AddToCart (05-15) and Purchase (OrderStatusWatcher) dispatch subjects were deliberately NOT touched.
- Live UAT (coverage D5): confirm on a real PDP + offer switch that each view yields a browser/server ViewContent dedup pair in Meta Events Manager, and that PageView custom_data is clean of value:0/num_items:0.
- Pre-existing out-of-scope suite failures: 5 docs-artifact tests (`AssetsExistTest` screenshots + `ReadmeStructureTest` README) remain red — owned by docs plans 05-08/05-09, not this plan.

---
*Phase: 05-documentation-marketplace-launch*
*Completed: 2026-07-02*

## Self-Check: PASSED

All modified files present; all 5 commits (af9951e, e094fa3, 05a5efb, da61799, c392d92) verified in git history.
