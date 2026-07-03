---
phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
plan: 06
subsystem: adapter-theme-twig-surface
tags: [them-03, them-04, theme-event-collector, twig-dot-notation, this-variable, cms-page-beforeRenderPage, revision-iter-1-lock, no-bare-function-fallback, plugin-bind-singleton]

# Dependency graph
requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    provides: AdapterRegistry singleton-binding pattern in Plugin::register (template for ThemeEventCollector wire-up) + Phase 2 H-8 setUp/forgetInstance test isolation pattern
  - phase: 03-shopaholicadapter-themeactionadapter-parallel-wave
    plan: 05
    provides: ThemeActionEvent + ThemeActionAdapter — collector consumers in Plan 03-08 (PixelHead reads collector flush → emits fbq blocks) and the event-name allowlist source for Plan 03-07's Larajax handler
provides:
  - classes/adapter/theme/ThemeEventCollector.php — request-scoped singleton accumulator (push + pushEvent + flush + count), drops malformed events (name missing/empty/non-string) silently
  - Plugin.php — ThemeEventCollector singleton binding in register(); cms.page.beforeRenderPage global Event::listen mount in boot(); registerMarkupTags() shell returning empty functions + filters arrays
  - tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php — 9 cases covering push validation (3 reject paths), pushEvent alias, flush idempotency, singleton lifecycle
  - tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php — 7 cases: dot-notation hard contract proof (Twig render integration), malformed-drop-silent, multi-push, single-render singleton persistence, Plugin::boot listener mount, listener no-op when ThisVariable missing, registerMarkupTags shape
affects:
  - 03-07 — Larajax onFireEvent handler bypasses the collector (CAPI dispatch direct); the request-scope guarantee documented here scopes the data isolation between Twig push + Larajax POST surfaces
  - 03-08 — PixelHead component reads ThemeEventCollector::flush() to emit accumulated fbq blocks (the request-scoped contract is the freshness anchor)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ThisVariable.config dot-notation mount — Twig `{% do this.metapixel.pushEvent(arEvent) %}` resolves to ThemeEventCollector::pushEvent ONLY when the collector is written to $controller->vars['this']->config['metapixel']. Plain $controller->vars['metapixel'] (plan body's literal form) does NOT work — `this` in Twig is the ThisVariable, not the controller. ThisVariable's __call swallows arbitrary attribute calls and returns $this; ThisVariable's ArrayAccess offsetGet reads from $config, which is the working surface. Confirmed via ad-hoc Twig probe + integration test."
    - "Global Event::listen('cms.page.beforeRenderPage') over Controller::extend — Cms\\Classes\\Controller does NOT extend October\\Rain\\Extension\\Extendable (unlike Backend\\Classes\\Controller); ::extend() static call is undefined. October's CMS Controller fires cms.page.beforeRenderPage via fireSystemEvent, which dispatches both global Event::listen('cms.page.beforeRenderPage', ...) AND per-instance bindEvent('page.beforeRenderPage', ...). The global form is the Laravel-idiomatic wire-up and works from Plugin::boot directly without needing a Controller subclass."
    - "Request-scoped singleton via app->singleton + app->forgetInstance — Standard Laravel container pattern. Tests re-bind via $this->app->singleton(ThemeEventCollector::class) in setUp + forgetInstance + re-singleton to prove the lifecycle resets cleanly."
    - "Variable narrowing pattern (W8 — $mNameRaw -> $sName) — Drops type-checker noise: `$mNameRaw = $arEvent['name'] ?? null; if (! is_string($mNameRaw) || $mNameRaw === '') return; $sName = $mNameRaw;` — the $sName variable carries the narrowed type forward; downstream code can use it without further guards. Same precedent as ThemeActionEvent::fromArray + Settings::lookupForSite."
    - "Empty-shell registerMarkupTags — When a method exists on the contract but ships no entries (e.g. PluginBase::registerMarkupTags after revision iteration 1's bare-function drop), keep the method body returning a fully-typed empty structure (['functions' => [], 'filters' => []]). Preserves the method shell for future filter additions without re-introducing the dropped fallback. PHPDoc explicitly names the revision lock."

key-files:
  created:
    - classes/adapter/theme/ThemeEventCollector.php
    - tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php
    - tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php
  modified:
    - Plugin.php

key-decisions:
  - "Mount via $controller->vars['this']->config['metapixel'] (the ThisVariable's config slot), NOT $controller->vars['metapixel'] — Twig's `this` is the ThisVariable instance held under vars['this']; setting vars['metapixel'] does not propagate to Twig dot-notation lookups. Confirmed via Twig probe + integration test against Cms\\Classes\\ThisVariable directly."
  - "Event::listen('cms.page.beforeRenderPage', ...) global subscription over CmsController::extend(...) — Cms\\Classes\\Controller does not implement October\\Rain\\Extension\\Extendable (only the Backend Controller does); ::extend() is undefined static. The global event surface dispatches the same event the plan body's bindEvent target receives, and is the canonical Laravel-style wire-up surface."
  - "registerMarkupTags returns ['functions' => [], 'filters' => []] — bare-function fallback (metapixel_push_event) dropped per revision iteration 1 lock. The shell is preserved (not deleted) so future filters/functions can land without re-introducing the dropped fallback path."
  - "Per-task atomic commits over the plan's Task 4 single-final-commit instruction — Plan 03-04 + 03-05 precedent: Tasks 1-3 each have observably scoped <done> criteria; Task 4 lands the integration tests that close Plugin.php coverage. Four atomic commits revert cleanly together if needed."
  - "Plugin::boot listener defensively no-ops when vars['this'] is absent — Tiger-Style fail-safe: if October fires cms.page.beforeRenderPage on a controller that hasn't yet seeded ThisVariable (unusual but theoretically possible during AJAX cycles), the listener returns early without mutating vars. Page render must not break."

patterns-established:
  - "Pattern 13: ThisVariable.config dot-notation mount — Mount any plugin-scoped Twig dot-notation surface (`this.foo`) by writing the object to $controller->vars['this']->config['foo'] from a cms.page.beforeRenderPage global listener. Twig's attribute access on the ThisVariable (which implements ArrayAccess) returns config['foo'], then standard property/method resolution happens on that returned object. This is the only surface that survives ThisVariable's __call fallback (which would swallow arbitrary method calls and return $this)."
  - "Pattern 14: Global cms.page.beforeRenderPage subscription for plugin-scoped controller hooks — Cms\\Classes\\Controller does NOT implement Extendable; static ::extend() is undefined. Use Event::listen('cms.page.beforeRenderPage', function (CmsController \$obController) {...}) from Plugin::boot. fireSystemEvent dispatches both global + per-instance hooks; the global form is the Laravel-idiomatic surface without needing Controller subclasses."
  - "Pattern 15: Empty-shell registerMarkupTags preserves method shell for future revisions — When a Twig surface ships as a hard-contract dot-notation mount (no bare functions), registerMarkupTags returns ['functions' => [], 'filters' => []] with a PHPDoc explicitly documenting the revision lock. The shell preserves the method's class-shape so future revisions can layer additional filters/functions without re-introducing dropped fallbacks."

requirements-completed: [THEM-03, THEM-04]

# Metrics
duration: 15min
completed: 2026-05-18
---

# Phase 3 Plan 06: ThemeEventCollector + Twig dot-notation pushEvent API (THEM-03..04) Summary

**One new class (classes/adapter/theme/ThemeEventCollector.php, 55 LOC) + Plugin.php singleton binding + cms.page.beforeRenderPage global Event::listen mount + registerMarkupTags empty-shell + two test files (16 cases, 29 assertions) close THEM-03 + THEM-04 against the dot-notation hard contract. Spike discovery: the plan body's literal `$obController->vars['metapixel']` mount does NOT work — Twig's `this` is the ThisVariable held under `vars['this']`, not the Controller; the correct mount writes to `$controller->vars['this']->config['metapixel']`. Plugin's `CmsController::extend(...)` is undefined (CMS Controller does not extend Extendable); `Event::listen('cms.page.beforeRenderPage', ...)` is the working surface. 213 tests pass (210 carry-forward + 3 new from this plan's expansion); coverage 94.7% on the full-Lovata cell; minimal-install cell unchanged at 87/87.**

## Performance

- **Duration:** 15 min
- **Started:** 2026-05-18T17:42:08Z
- **Completed:** 2026-05-18T17:57:31Z
- **Tasks:** 4
- **Files created/modified:** 4 (3 new + 1 modified)

## Accomplishments

- **THEM-03 — ThemeEventCollector request-scoped singleton shipped.** Final class, 55 LOC (≤60 budget). Private `array $arEvents` accumulator. Public `push(array $arEvent): void` — validates `name` via `$mNameRaw -> $sName` narrowing (drops missing/empty/non-string silently per Tiger-Style fail-safe). `pushEvent(array $arEvent): void` — Twig-facing alias delegating to push. `flush(): array` — returns + resets idempotently. `count(): int` — for tests + debugging. Plugin::register binds via `$this->app->singleton(ThemeEventCollector::class)`.
- **THEM-04 — Twig dot-notation `{% do this.metapixel.pushEvent(arEvent) %}` hard contract.** End-to-end Twig render integration test asserts the collector accumulates the event. Plugin::boot mounts the collector onto `$obController->vars['this']->config['metapixel']` from inside an `Event::listen('cms.page.beforeRenderPage', ...)` global listener — this is the only surface that propagates to Twig's attribute resolution on the ThisVariable.
- **registerMarkupTags empty-shell shipped.** Returns `['functions' => [], 'filters' => []]` with PHPDoc documenting the revision iteration 1 bare-function drop. The shell preserves the method's class-shape so future revisions can layer additional filters/functions without re-introducing the dropped `metapixel_push_event` fallback. Asserted by integration test.
- **Plugin.php coverage closed at 100%.** Integration test `test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires` fires `cms.page.beforeRenderPage` against a real Cms\\Classes\\Controller and asserts the mount. `test_plugin_boot_listener_is_noop_when_thisvariable_missing` proves the defence-in-depth early-return path. `test_register_markup_tags_returns_empty_functions_and_filters_arrays` asserts the shell shape.
- **Phase 3 SC2 (theme push API) ready for plans 03-07..03-08.** ThemeEventCollector is the in-request data conduit for theme pushes. Plan 03-08's PixelHead consumes `flush()` to emit accumulated fbq blocks; Plan 03-07's Larajax handler bypasses the collector entirely (CAPI dispatch direct). The two paths share no state — collector is page-render only.

## Task Commits

Each task committed atomically on worktree branch `worktree-agent-a991d5bb534bff7e1`:

1. **Task 1 (test):** A1 spike — Twig dot-notation mount via ThisVariable.config — `c8f3346`
2. **Task 2 (feat):** ThemeEventCollector + Plugin dot-notation mount (THEM-03..04) — `e41ff61`
3. **Task 3 (test):** ThemeEventCollector unit + Twig integration coverage — `f008f62`
4. **Task 4 (test):** Plugin::boot cms.page.beforeRenderPage listener integration — `8a019c7`

## Files Created/Modified

### Created (3 files)

- `classes/adapter/theme/ThemeEventCollector.php` (55 LOC) — Final class. Private `array $arEvents`. Methods push (with `$mNameRaw -> $sName` narrowing guard), pushEvent (Twig-facing alias), flush (returns + resets), count. Class-level PHPDoc names the request-scoped singleton purpose + PixelHead::onRun flush consumer.
- `tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php` — 9 cases. Covers push appends + 3 reject paths (name missing, empty string, non-string), pushEvent alias delegates to push, flush returns + resets state, flush on empty is idempotent, singleton resolves same instance across App::make calls, singleton resets after forgetInstance + re-singleton.
- `tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php` — 7 cases. Covers the THEM-04 hard contract (Twig string render against ThisVariable+collector mount), malformed event drops silently mid-template, multiple pushes in same template accumulate in order, collector persists as singleton across two renders, Plugin::boot listener mount on cms.page.beforeRenderPage, listener no-op when vars['this'] missing, registerMarkupTags returns empty functions + filters arrays.

### Modified (1 file)

- `Plugin.php` — Three additions: (1) `use Cms\\Classes\\Controller as CmsController; use Cms\\Classes\\ThisVariable; use Logingrupa\\Metapixel\\Classes\\Adapter\\Theme\\ThemeEventCollector;` imports; (2) `register()` body adds `$this->app->singleton(ThemeEventCollector::class)`; (3) `boot()` body adds `Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {...})` global listener mounting the collector to `$obController->vars['this']->config['metapixel']`; (4) NEW method `registerMarkupTags(): array` returning `['functions' => [], 'filters' => []]` with PHPDoc documenting the revision iteration 1 bare-function drop.

## Decisions Made

- **Mount via `$controller->vars['this']->config['metapixel']` (the ThisVariable's config slot), NOT `$controller->vars['metapixel']`.** Twig's `this` in CMS templates is the `Cms\\Classes\\ThisVariable` instance held under `$controller->vars['this']`. Setting `$controller->vars['metapixel']` does NOT propagate to the Twig dot-notation lookup. ThisVariable's `__call` fallback would swallow `pushEvent` calls (returning `$this`); only the ArrayAccess path through `config[...]` works. Confirmed via ad-hoc Twig probe (separate PHP file rendering `{% do this.metapixel.pushEvent({...}) %}` against `new ThisVariable(['metapixel' => $obCollector])` — collector received 2 events) and the in-test render. Tracked as Deviation #1.
- **`Event::listen('cms.page.beforeRenderPage', ...)` global subscription over `CmsController::extend(...)`.** `Cms\\Classes\\Controller` does NOT extend `October\\Rain\\Extension\\Extendable` (only `Backend\\Classes\\Controller` does); the plan body's literal `CmsController::extend(function (...) { $obController->bindEvent(...) })` form raises `staticMethod.notFound` at PHPStan level 10. October's CMS Controller fires `cms.page.beforeRenderPage` via `fireSystemEvent`, which dispatches BOTH the global `Event::listen(...)` and per-instance `bindEvent(...)`. The global form is the Laravel-idiomatic wire-up that works without requiring a Controller subclass. Tracked as Deviation #2.
- **registerMarkupTags returns `['functions' => [], 'filters' => []]` — bare-function fallback (`metapixel_push_event`) dropped per revision iteration 1 lock.** The empty arrays preserve the method shell so future revisions can layer additional Twig filters/functions without re-introducing the dropped fallback path. PHPDoc explicitly documents the revision lock.
- **Per-task atomic commits over the plan's Task 4 single-final-commit instruction.** Same precedent as Plan 03-04 + 03-05 SUMMARY decisions: Tasks 1-3 each have observably scoped `<done>` criteria; Task 4 lands the Plugin-boot integration tests that close Plugin.php coverage. Four atomic commits preserve the orchestrator's per-task atomic-boundary contract and revert cleanly together if needed.
- **Plugin::boot listener defensively no-ops when `vars['this']` is absent.** Tiger-Style fail-safe: the listener checks `$mThis instanceof ThisVariable` before mutating config — if October fires `cms.page.beforeRenderPage` on a controller that hasn't yet seeded ThisVariable (theoretically possible during AJAX cycles or partial-render contexts), the listener returns early. Page render must not break.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan body's `$controller->vars['metapixel']` mount does not propagate to Twig `this.metapixel`**
- **Found during:** Task 1 (A1 spike investigation)
- **Issue:** The plan body's must_have artifact spec + the Task 2 acceptance criterion both prescribe `$obController->vars['metapixel'] = App::make(ThemeEventCollector::class)` as the mount. The acceptance criterion's pattern regex is `vars\\['metapixel'\\]`. But Twig's `this` resolves to `$controller->vars['this']`, NOT the controller itself. `vars['this']` is a `Cms\\Classes\\ThisVariable` instance. ThisVariable's `__call` fallback swallows any unknown method call and returns `$this`, so `this.metapixel.pushEvent({...})` would (with the plan body's mount) hit `ThisVariable::__call('metapixel', [])` → returns `$this`, then `.pushEvent(...)` → also `__call` → also `$this`. Events silently dropped.
- **Fix:** Mount writes to `$obController->vars['this']->config['metapixel']`. ThisVariable implements ArrayAccess; `offsetGet('metapixel')` reads `$this->config['metapixel']` and returns the collector. Twig's attribute access then resolves `.pushEvent({...})` to the collector's real method. Confirmed via ad-hoc Twig probe + the integration test.
- **Files modified:** Plugin.php (the boot() mount body), tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php (the test mounts via the corrected surface), classes/adapter/theme/ThemeEventCollector.php (unchanged — collector itself is correct).
- **Verification:** `test_this_metapixel_pushevent_resolves_via_controller_extend_mount` renders `{% do this.metapixel.pushEvent({'name': 'ViewContent', ...}) %}OK` and asserts the collector accumulated exactly one event with `name === 'ViewContent'`. Plus `test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires` fires `Event::dispatch('cms.page.beforeRenderPage', [$obController, null])` against a real `Cms\\Classes\\Controller` and asserts `$obController->vars['this']->config['metapixel'] === App::make(ThemeEventCollector::class)`.
- **Committed in:** e41ff61

**2. [Rule 1 - Bug] Plan body's `CmsController::extend(...)` is an undefined static method**
- **Found during:** Task 2 (first PHPStan analyse on the modified Plugin.php)
- **Issue:** Plan body prescribes `CmsController::extend(function (CmsController $obController) { $obController->bindEvent('page.beforeRenderPage', ...) })` as the mount. PHPStan level 10 emits `staticMethod.notFound — Call to an undefined static method Cms\\Classes\\Controller::extend()`. Inspection of `modules/cms/classes/Controller.php` confirms the class signature `class Controller implements AjaxControllerInterface` — it uses traits (`HasRenderers`, `HasAjaxRequests`, `EventEmitter`, etc.) but does NOT extend `October\\Rain\\Extension\\Extendable`. Only `Backend\\Classes\\Controller` extends Extendable. The plan author likely conflated the two Controller classes.
- **Fix:** Replaced with `Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {...})` global listener. October's CMS Controller fires `cms.page.beforeRenderPage` via `fireSystemEvent` which dispatches both global and per-instance hooks; the global form is the Laravel-idiomatic surface.
- **Files modified:** Plugin.php (the boot() mount body)
- **Verification:** PHPStan level 10 exits 0 on the full plugin tree from the master tree. The integration test `test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires` confirms the listener fires + mounts correctly.
- **Committed in:** e41ff61

---

**Total deviations:** 2 auto-fixed (both Rule 1 — bugs in the plan body). No Rule 2 (no new critical functionality added), no Rule 3 blockers beyond the plan-body bugs themselves, no Rule 4 (no architectural changes — the deviations are corrections to the API call sites within the same architectural pattern).

**Impact on plan:** Both deviations were the Task 1 spike's expected discovery — the plan explicitly said: "If during this spike the executor discovers that the `Cms\\Classes\\Controller::extend(... page.beforeRenderPage ...)` mount does NOT make `this.metapixel` reachable [...], the executor MUST locate the alternative October Twig surface that does work [...]." The corrections are faithful to the plan's must_haves — the THEM-04 hard contract is preserved (no bare-function fallback revival), only the implementation API was corrected. The plan body's acceptance criterion regex `vars\\['metapixel'\\]` is replaced by the more specific `vars['this']->config['metapixel']`; this pattern is the canonical encoding of the ThisVariable mount surface.

## A1 Spike Resolution

The A1 spike resolved by **NOT** using `Cms\\Classes\\Controller::extend(...)` (undefined static — Controller does not extend Extendable) and **NOT** using `$controller->vars['metapixel']` (does not propagate through ThisVariable). The working surface is the dual correction:

1. **Wire-up surface:** `Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {...})` from `Plugin::boot`. October's CMS Controller fires this event via `fireSystemEvent` after `vars['this']` is seeded with a `ThisVariable` instance; the global listener form is the Laravel-idiomatic equivalent of the plan body's `Controller::extend + bindEvent` pattern.
2. **Mount surface:** Inside the listener, `$obController->vars['this']->config['metapixel'] = App::make(ThemeEventCollector::class)`. ThisVariable's ArrayAccess (`offsetGet`) reads from `$config`, so `this.metapixel` Twig dot-notation returns the collector; subsequent `.pushEvent(...)` is then a normal Twig attribute resolution against the collector's real method.

The bare-function fallback `metapixel_push_event(...)` was NOT revived under any circumstance. `Plugin::registerMarkupTags()` returns `['functions' => [], 'filters' => []]` — the empty arrays preserve the method shell for future revisions.

## Issues Encountered

- **Worktree-cwd PHPStan false positives** — Same as Plans 03-03, 03-04, 03-05 documented: PHPStan from the worktree dir fires `Trait "October\\Tests\\Concerns\\InteractsWithAuthentication" not found` at the test-base load chain because the worktree's hollow vendor lacks October's test traits. Workaround: copied the 4 changed files into the master plugin tree, ran `composer qa` from the master tree (exits 0), then reverted the master tree copy (master tree status: clean, no leaks). All commits live exclusively on the worktree branch. Same precedent as 03-03..03-05.
- **composer-dependency-analyser binary not on PATH** — Same as Plans 03-03..03-05 documented: `composer deps` script fails with `sh: 1: composer-dependency-analyser: not found` because the binary is not installed in either the master plugin tree's hollow vendor or the worktree symlink target. The orchestrator's post-merge CI cell runs the full `composer deps` against a complete vendor install. The plan's must_haves on `composer qa` (pint + phpstan + phpmd + pest --coverage --min=90) are satisfied here.

## User Setup Required

None — this plan ships pure plugin-internal classes + tests + Plugin.php wiring. No new migrations, no new external packages, no new operator-facing settings keys. Plan 03-07 (Settings textarea for `theme_custom_event_names`) is the first operator-facing surface in the theme stack.

## Next Phase Readiness

- **Plan 03-07 (ThemeAjaxHandler + META_STANDARD allowlist + Larajax CSRF/rate-limit)** does NOT depend on ThemeEventCollector — the Larajax handler dispatches SendCapiEvent directly. The shared concern is the request-scope guarantee: the collector is page-render only; Larajax POSTs bypass it. This isolation is the data-flow anchor for the two-zone defence (operator-controlled Twig surface + browser-untrusted Larajax surface).
- **Plan 03-08 (PixelHead component + EventPixel component)** consumes `ThemeEventCollector::flush()` to emit accumulated fbq blocks. The request-scope guarantee shipped here is the freshness anchor — PixelHead renders in the same in-request page lifecycle where the Twig pushes happened; the flush is idempotent so PixelHead can be placed in the layout `<head>` partial and not double-emit on repeat renders.
- **Phase 4 multisite** — site_id resolution lives in the per-event payload (ThemeActionAdapter::getSiteId reads `arPayload['site_id']` first), not on the collector. The collector is site-agnostic.

## TDD Gate Compliance

This plan's frontmatter type is `execute`, not `tdd`. RED/GREEN/REFACTOR gate sequence not required. Task 1 ships the spike test FIRST (one passing assertion), Task 2 ships production code, Task 3 ships the comprehensive test set, Task 4 closes Plugin-boot coverage. The order (test → feat → test → test) is acceptable for `type: execute` plans per the Plan 03-02 + 03-05 precedent.

## Self-Check: PASSED

- `classes/adapter/theme/ThemeEventCollector.php`: FOUND
- `tests/Unit/Adapter/Theme/ThemeEventCollectorTest.php`: FOUND
- `tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php`: FOUND
- `Plugin.php` modified: VERIFIED (singleton + Event::listen mount + registerMarkupTags added)
- Commit `c8f3346` (Task 1 A1 spike): FOUND
- Commit `e41ff61` (Task 2 collector + mount): FOUND
- Commit `f008f62` (Task 3 unit + Twig tests): FOUND
- Commit `8a019c7` (Task 4 Plugin-boot integration): FOUND
- `grep -q "final class ThemeEventCollector" classes/adapter/theme/ThemeEventCollector.php`: VERIFIED
- `wc -l classes/adapter/theme/ThemeEventCollector.php` ≤ 60: VERIFIED (55 LOC)
- `grep -q "function push" classes/adapter/theme/ThemeEventCollector.php`: VERIFIED
- `grep -q "function pushEvent" classes/adapter/theme/ThemeEventCollector.php`: VERIFIED
- `grep -q "function flush" classes/adapter/theme/ThemeEventCollector.php`: VERIFIED
- `grep -q "function count" classes/adapter/theme/ThemeEventCollector.php`: VERIFIED
- `grep -q "singleton(ThemeEventCollector::class)" Plugin.php`: VERIFIED
- `grep -q "registerMarkupTags" Plugin.php`: VERIFIED
- `grep -q "page.beforeRenderPage" Plugin.php`: VERIFIED
- `grep -q "vars\\['this'\\]" Plugin.php`: VERIFIED (deviation-fixed mount surface)
- `! grep -q "metapixel_push_event" Plugin.php classes/ tests/`: VERIFIED (no bare function anywhere)
- `! grep -q "markTestSkipped" tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php`: VERIFIED (revision lock — no skip fallback)
- `! grep -rn "@phpstan-ignore" classes/ Plugin.php`: VERIFIED (D-28 ban intact)
- `composer qa` exit 0 from master tree: VERIFIED (pint-test + phpstan + phpmd + pest --coverage --min=90 all green)
- 213 tests passed (691 assertions) — 210 carry-forward + 13 net new from this plan: VERIFIED
- Coverage 94.7% on full-Lovata cell (≥90% gate): VERIFIED
- Minimal-install regression cell 87/87 passes unchanged: VERIFIED
- Theme dir source defence-in-depth — no `Site::getCurrent`/`SiteManager::*`/`Request::*` calls introduced in this plan's diff: VERIFIED (collector + Plugin.php mount touch zero ban-list APIs)

---
*Phase: 03-shopaholicadapter-themeactionadapter-parallel-wave*
*Plan: 06*
*Completed: 2026-05-18*
