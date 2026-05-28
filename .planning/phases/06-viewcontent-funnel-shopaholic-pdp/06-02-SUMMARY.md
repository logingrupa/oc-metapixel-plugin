---
phase: 06-viewcontent-funnel-shopaholic-pdp
plan: 02
subsystem: api
tags: [pixelhead, theme-event-collector, cms-lifecycle, deferred-flush, fbq, twig-markup-helper, view-content]

# Dependency graph
requires:
  - phase: 06-01
    provides: PixelHeadDeferredFlushTest RED stubs (4 cases), Plan-06 brief matrix
  - phase: 03
    provides: ThemeEventCollector singleton, ThemeActionAdapter/Event/ValueResolver, PixelHead base-pixel emission, Plugin.boot cms.page.beforeRenderPage first listener
provides:
  - PixelHeadDeferredFlushBuffer singleton — request-scoped buffer bridging the deferred-flush listener and the Twig partial
  - PixelHead::flushDeferredFromController static — drains ThemeEventCollector at cms.page.beforeRenderPage AFTER all page-tier onRun() complete
  - PixelHead::renderDeferredBlocks static — Twig markup helper sourcing deferred fbq blocks from the buffer
  - fbq 4th-arg eventID emission when a pushed event carries an event_id string (RESEARCH §6)
  - Plugin.boot second cms.page.beforeRenderPage listener (pure observer; fires AFTER ThisVariable injector by registration order)
  - LIFECYCLE TIMING CONTRACT PHPDoc on PixelHead (RESEARCH §12 verbatim) — locks the onRun vs beforeRenderPage emission boundary
affects: [06-03 (AdapterRegistry::resolveByAlias may reuse buffer), 06-04 (ShopaholicProductAdapter pushes ViewContent events through the deferred buffer), 06-05 (ProductPageWatcher pushes from page-tier onRun, now flushable), 06-06 (ProductPixel can rely on the deferred buffer), 06-07 (README + CHANGELOG document the new lifecycle contract)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Lifecycle-bound flush: layout-tier onRun emits the immediate work; deferred work moves to cms.page.beforeRenderPage via a static method + request-scoped buffer singleton."
    - "Twig markup helper-as-Closure: register a typed `fn (): string` closure (not a plain `[Class, 'method']` array callable) so phpstan level 10 narrows the return type."
    - "Fail-safe Tiger-Style boundary on every static lifecycle listener body: outer try/catch Throwable → Log::warning + skip; the page MUST render even when the collector is malformed."

key-files:
  created:
    - classes/helper/PixelHeadDeferredFlushBuffer.php
    - .planning/phases/06-viewcontent-funnel-shopaholic-pdp/deferred-items.md
  modified:
    - components/PixelHead.php
    - components/pixelhead/default.htm
    - Plugin.php
    - tests/Feature/Components/PixelHeadBasePixelTest.php
    - tests/Feature/Components/PixelHeadTest.php
    - tests/Feature/Components/PixelHeadDeferredFlushTest.php
    - tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php
  deleted:
    - tests/fixtures/components/PixelHeadExceptionFixture.php

key-decisions:
  - "PixelHead::onRun emits ONLY the base PageView. Collector drain moves to cms.page.beforeRenderPage so page-tier component pushes are flushed AFTER every onRun (per Cms\\Classes\\Controller lifecycle anchor)."
  - "Deferred fbq blocks render via a PixelHead::renderDeferredBlocks markup-helper Closure, sourced from a request-scoped PixelHeadDeferredFlushBuffer singleton. The Twig partial NO LONGER reads $this->page['pixelHeadBlocks']; the buffer is the new SSoT."
  - "Pushed events with a non-empty string event_id render fbq's 4th `{eventID: ...}` argument (RESEARCH §6). Events without event_id keep the 3-arg shape."
  - "array_diff_key exclusion set grew by two: event_id (used as 4th-arg, not custom_data) and product_id (used by ProductPixel for window.__metapixelProduct global)."
  - "dispatchCapiMirror became `private static` so the static flushDeferredFromController can invoke it. The legacy `PixelHeadExceptionFixture` (which subclassed PixelHead to override the protected instance method) is removed; tests force CAPI-mirror failure via an App::singleton swap of ThemeActionAdapter instead."

patterns-established:
  - "Plugin.registerMarkupTags returns typed `fn (): string => Class::staticMethod()` closures, not bare `[Class, 'method']` array callables. PHPStan level 10 narrows the return type via the closure signature; array callables resolve to `callable(): mixed` and break the `callable` docblock type."
  - "Multiple cms.page.beforeRenderPage listeners coexist as pure observers, registered in dependency order. The ThisVariable injector lands first; PixelHead deferred-flush second. Order is documented in a closure-prefix comment but neither listener depends on the other's side effect."

requirements-completed:
  - VIEW-01

# Metrics
duration: ~35min
completed: 2026-05-28
---

# Phase 06 Plan 02: PixelHead Deferred Flush Summary

**PixelHead drains ThemeEventCollector at cms.page.beforeRenderPage via a static flushDeferredFromController + PixelHeadDeferredFlushBuffer singleton, unblocking page-tier ViewContent pushes from Shopaholic ProductPage.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-05-28T13:00Z
- **Completed:** 2026-05-28T13:35Z
- **Tasks:** 3
- **Files modified:** 7 (1 created, 6 modified, 1 deleted)

## Accomplishments

- PixelHead.onRun() now emits ONLY the base PageView block — collector drain moved to a static method invoked at cms.page.beforeRenderPage.
- New PixelHeadDeferredFlushBuffer singleton bridges the deferred listener and the Twig render context (RESEARCH Pitfall 1 anchor closed).
- fbq's 4th `{eventID: <uuid>}` argument now emits when a pushed event carries `event_id` (closes RESEARCH §6 gap for browser-pixel CAPI dedup on theme events).
- Plugin.boot registers a second cms.page.beforeRenderPage listener — pure observer, sibling to the existing ThisVariable injector.
- Four PixelHeadDeferredFlushTest stubs (planted RED by plan 06-01) turn GREEN.
- LIFECYCLE TIMING CONTRACT docblock landed on PixelHead per RESEARCH §12 — future plans (06-03..06-06) have a contract to push against.

## Task Commits

1. **Task 1: add PixelHeadDeferredFlushBuffer singleton** — `d17e313` (feat)
2. **Task 2: refactor PixelHead — defer flush + eventID 4th-arg + Twig markup helper** — `a8bca7a` (refactor)
3. **Task 3: register cms.page.beforeRenderPage deferred-flush listener + GREEN PixelHeadDeferredFlushTest** — `085c49e` (feat)

## Files Created/Modified

- **`classes/helper/PixelHeadDeferredFlushBuffer.php`** (created) — `final class` request-scoped buffer; `setBlocks(list<string>)`, `getBlocks(): list<string>`, `clear()`. Filters non-string entries via `array_values(array_filter(..., 'is_string'))`.
- **`components/PixelHead.php`** (modified) — `onRun()` body is now a single `$this->emitBasePixel()` call. New `public static flushDeferredFromController(CmsController $obController): void` drains the collector and writes script blocks into the buffer with eventID-4th-arg + array_diff_key exclusion of event_id + product_id. New `public static renderDeferredBlocks(): string` concatenates buffered blocks for Twig. `dispatchCapiMirror` is now `private static`. Outer try/catch Throwable in `flushDeferredFromController` guarantees a malformed collector cannot 500 the page render. LIFECYCLE TIMING CONTRACT class-level docblock added verbatim from RESEARCH §12.
- **`components/pixelhead/default.htm`** (modified) — base pixel block unchanged. Deferred section becomes `{% if renderDeferredBlocks() %}{{ renderDeferredBlocks()|raw }}{% endif %}`. The partial no longer references `$this->page['pixelHeadBlocks']` for deferred content.
- **`Plugin.php`** (modified) — `register()` binds `PixelHeadDeferredFlushBuffer::class` as a singleton (alongside the existing AdapterRegistry / ThemeEventCollector / HostIndexResolver singletons). `boot()` adds a second `cms.page.beforeRenderPage` listener invoking `PixelHead::flushDeferredFromController`. `registerMarkupTags()` returns a typed `fn (): string` closure for `renderDeferredBlocks` (NOT a `[Class, 'method']` array callable — phpstan level-10 type narrowing).
- **`tests/Feature/Components/PixelHeadDeferredFlushTest.php`** (modified) — four GREEN tests (was RED stubs): deferred-vs-onRun separation, late-push capture, base PageView action_key shape regex, test_event_code flow. setUp seeds Settings + binds both singletons; tearDown forgets instances + clears caches.
- **`tests/Feature/Components/PixelHeadBasePixelTest.php`** (modified) — setUp/tearDown bind the new buffer singleton; the one assertion that read `pixelHeadBlocks` directly off the onRun page-vars is rewritten to drive `flushDeferredFromController` and read the buffer (matches the new SSoT).
- **`tests/Feature/Components/PixelHeadTest.php`** (modified) — six THEM-07 tests migrated from `$arPage['pixelHeadBlocks']` (the old onRun contract) to `App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks()` (the new SSoT). CAPI-mirror failure forcing now swaps the ThemeActionAdapter singleton instead of subclassing PixelHead.
- **`tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php`** (modified) — the `register_markup_tags_returns_empty_functions_and_filters_arrays` test is renamed/rewritten to `register_markup_tags_exposes_renderDeferredBlocks_function`, asserting the new contract (one functions entry + empty filters).
- **`tests/fixtures/components/PixelHeadExceptionFixture.php`** (deleted) — obsolete: it subclassed PixelHead to throw from a protected instance `dispatchCapiMirror`, but the method is now `private static` and CAPI-mirror failure forcing is exercised via container singleton swaps in `PixelHeadTest`.

## Decisions Made

- **Markup helper registered as a typed closure, not a method-array callable.** `[PixelHead::class, 'renderDeferredBlocks']` triggered a phpstan level-10 `return.type` regression: bare `callable` in the `registerMarkupTags` docblock auto-narrows to `callable(): mixed`, but PHPStan saw the array-callable as `callable(): string` (a subtype) and refused the assignment to the broader docblock type. Wrapping the static call in `fn (): string => PixelHead::renderDeferredBlocks()` lets phpstan narrow via the closure's explicit return type without widening the docblock.
- **Test infrastructure: CAPI-mirror failure exercised via App::singleton swap of ThemeActionAdapter.** This replaces the legacy `PixelHeadExceptionFixture` subclass pattern. Rationale: with `dispatchCapiMirror` now `private static`, subclassing-to-throw is no longer possible; binding a throwing closure to the adapter resolves to the same Tiger-Style boundary catch and is closer to the actual production failure mode (adapter rehydrate failure from queue replay).
- **Task 1 deferred the markup-function registration to Task 2.** Plan Task 1 acceptance asked for the markup helper to be registered alongside the singleton — but with `PixelHead::renderDeferredBlocks` not yet defined, phpstan level-10 flagged `staticMethod.notFound` on the registerMarkupTags closure. Splitting the singleton binding (Task 1) from the markup-helper registration (Task 2) keeps each commit individually phpstan-green and preserves atomic-commit semantics. The plan-level invariant (helper registered after all three tasks land) is satisfied.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Markup-helper registration split between Task 1 and Task 2 for phpstan-clean atomic commits**

- **Found during:** Task 1 (`Plugin::registerMarkupTags` phpstan analysis)
- **Issue:** Plan said Task 1 registers both the `PixelHeadDeferredFlushBuffer` singleton AND the `renderDeferredBlocks` markup helper. But Task 2 is what adds `PixelHead::renderDeferredBlocks`. PHPStan level 10's `staticMethod.notFound` would reject the Task 1 commit because it references a method that does not exist yet.
- **Fix:** Task 1 commits only the singleton binding. Task 2 adds `renderDeferredBlocks` to PixelHead AND wires the markup helper into `Plugin::registerMarkupTags` (as a typed closure — see decision above).
- **Files affected:** `Plugin.php` (split between commits `d17e313` and `a8bca7a`).
- **Verification:** Both commits individually pass `phpstan analyse --level=10` on the touched files.
- **Committed in:** `d17e313` + `a8bca7a`.

**2. [Rule 1 - Bug] Migrated 6 THEM-07 tests in PixelHeadTest from the old onRun→emitCollectedEvents contract to the new flushDeferredFromController contract**

- **Found during:** Task 2 (legacy test suite ran red after onRun no longer calls the collector drain)
- **Issue:** Plan acceptance criteria called only out `PixelHeadBasePixelTest`, but `PixelHeadTest.php` (6 cases) asserted the old contract: `onRun` populates `$arPage['pixelHeadBlocks']`. After Task 2's refactor those assertions all fail.
- **Fix:** Rewrote `PixelHeadTest` to drive `PixelHead::flushDeferredFromController(Mockery::mock(Controller::class))` and read `App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks()`. Updated test method names to reflect the new entry point. The CAPI-mirror-throws case now binds a throwing `ThemeActionAdapter` singleton instead of subclassing PixelHead.
- **Files modified:** `tests/Feature/Components/PixelHeadTest.php`, deleted `tests/fixtures/components/PixelHeadExceptionFixture.php`.
- **Verification:** All 6 PixelHeadTest cases GREEN; full PixelHead-related suite (16 tests) GREEN.
- **Committed in:** `a8bca7a`.

**3. [Rule 1 - Bug] Updated PixelHeadBasePixelTest::test_collector_flush_still_runs_alongside_base_pixel to drive the new deferred path**

- **Found during:** Task 2 (the only base-pixel assertion that read `pixelHeadBlocks` directly off onRun)
- **Issue:** The assertion expected `$arPage['pixelHeadBlocks']` populated by `onRun()`, but onRun no longer touches the collector.
- **Fix:** Test now calls `flushDeferredFromController` after the component pass and reads `App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks()`. setUp/tearDown bind/forget the new buffer singleton.
- **Files modified:** `tests/Feature/Components/PixelHeadBasePixelTest.php`.
- **Verification:** All 6 base-pixel tests GREEN.
- **Committed in:** `a8bca7a`.

**4. [Rule 1 - Bug] Replaced ThemeMarkupTagsTwigTest::test_register_markup_tags_returns_empty_functions_and_filters_arrays with the new contract**

- **Found during:** Task 3 (full-suite pest sweep)
- **Issue:** Pre-existing test asserted `$arTags['functions']` is `[]` — but Task 2 registered `renderDeferredBlocks` there.
- **Fix:** Renamed to `test_register_markup_tags_exposes_renderDeferredBlocks_function`, asserts the new map key + callable shape.
- **Files modified:** `tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php`.
- **Verification:** Test passes; only pre-existing failure remains (logged separately — see deferred-items.md).
- **Committed in:** `085c49e`.

---

**Total deviations:** 4 auto-fixed (1 Rule 3 phpstan-blocker, 3 Rule 1 stale-contract test migrations).
**Impact on plan:** All four were necessary to keep the test suite green after the deferred-flush refactor. No scope creep beyond the plan-listed files plus the legacy THEM-07 test migration (which the plan implicitly required via its "PixelHeadBasePixelTest stays green" acceptance, but did not flag the sibling PixelHeadTest contract).

## Issues Encountered

- **PHPStan + Pest see the LIVE plugin directory, not the worktree checkout.** Composer's PSR-4 autoload + October Rain's class manifest both register `Logingrupa\Metapixel\` against `plugins/logingrupa/metapixel/` (live), so worktree code is invisible to the host vendor tooling. Worked around by syncing worktree files into the live plugin directory for validation, then restoring the live directory to its pre-execution state before this SUMMARY was committed. Worktree branch carries the canonical commits; live directory is back on `a7637d6` master state.
- One pre-existing test failure (`ThemeMarkupTagsTwigTest::test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires`) reproduces on pristine `master` (a7637d6) with no 06-02 changes applied. Out of plan 06-02 scope. Logged to `.planning/phases/06-viewcontent-funnel-shopaholic-pdp/deferred-items.md`.

## User Setup Required

None — refactor is internal to plugin code, no environment variables or external service configuration changed.

## Next Phase Readiness

- **Plan 06-03** (AdapterRegistry::resolveByAlias) — unblocked. No coupling to the deferred-flush surface; reads only from PixelHeadDeferredFlushBuffer if it elects to surface alias-resolution diagnostics in fbq emit. Recommended to start in parallel with 06-04.
- **Plan 06-04** (ShopaholicProductAdapter + ValueResolver + ContractTest) — unblocked. ViewContent payload shape can rely on the new fbq 4th-arg eventID emission.
- **Plan 06-05** (ProductPageWatcher) — unblocked. ProductPage push into ThemeEventCollector during its own `onRun()` is now reliably flushed because `flushDeferredFromController` fires AFTER every page-tier component's onRun per the lifecycle contract.
- **Plan 06-06** (ProductPixel + ThemeAjaxHandler hybrid) — unblocked. The new buffer SSoT + renderDeferredBlocks helper are the integration surface ProductPixel will compose against.
- **Plan 06-07** (README + CHANGELOG) — the LIFECYCLE TIMING CONTRACT docblock is the authoritative source for the README's "Lifecycle" section.

**Blockers / concerns:** None for downstream Phase 6 plans. One pre-existing pristine-master test failure logged to deferred-items.md.

## Self-Check

FOUND: classes/helper/PixelHeadDeferredFlushBuffer.php
FOUND: components/PixelHead.php
FOUND: components/pixelhead/default.htm
FOUND: Plugin.php
FOUND: tests/Feature/Components/PixelHeadDeferredFlushTest.php
FOUND: tests/Feature/Components/PixelHeadBasePixelTest.php
FOUND: tests/Feature/Components/PixelHeadTest.php
FOUND: tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php
FOUND: .planning/phases/06-viewcontent-funnel-shopaholic-pdp/deferred-items.md
FOUND: commit d17e313 (Task 1)
FOUND: commit a8bca7a (Task 2)
FOUND: commit 085c49e (Task 3)

## Self-Check: PASSED

---
*Phase: 06-viewcontent-funnel-shopaholic-pdp*
*Plan: 02*
*Completed: 2026-05-28*
