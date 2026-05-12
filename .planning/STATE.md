---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: in-progress
stopped_at: "Plan 03-01 complete (PAY-04 + PAY-05 — migrations + FailedEvent model + bootOrdersTable harness). Next: plan 03-02 (PAY-09 — Exception hierarchy: MetaPixelException abstract base + 7 concrete subclasses)."
last_updated: "2026-05-12T21:34:26Z"
last_activity: 2026-05-12 -- Plan 03-01 shipped (composer qa green, 40 tests / 124 assertions / 3 skipped / 76.1 % coverage). Phase 3 1/6 plans done.
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 6
  completed_plans: 1
  percent: 16
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 03 (purchase-end-to-end) — in progress
Plan: 1 of 6 (03-01 shipped — PAY-04 + PAY-05 done)
Status: Phase 03 wave 1 partial — plan 03-01 complete, plan 03-02 next
Last activity: 2026-05-12 -- Plan 03-01 shipped: two reversible migrations (orders meta_purchase_event_id + meta_purchase_event_time + failed_events table), FailedEvent plain Model + Validation + createFromPayloadAndException factory (5 private helpers under phpmd CC ≤ 10), bootOrdersTable hermetic helper in MetapixelTestCase, 13 new tests (6 MigrationsBootTest + 7 FailedEventModelTest). 4 task commits + summary commit. composer qa green (40 tests / 124 assertions / 3 skipped — skips auto-resolve when plan 03-02 ships MetaPixelException). 3 deviations: Rule 1 down() index-before-column drop, Rule 3 phpmd complexity refactor, Rule 3 require_once for snake_case migration class autoload.

## Performance Metrics

**Velocity:**

- Total plans completed: 6 (Phase 1 + Plans 02-01..04 + Plan 03-01)
- Average duration: ~23 min (Plans 02-01 + 02-02 + 02-03 + 02-04 + 03-01: ~94+9+10 = ~113 min / 5 plans = ~23 min); Phase 1 not timed
- Total execution time: ~1.9 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |
| 3. Purchase end-to-end | 1/6 | ~10 min | 10 min |

**Recent Trend:**

- Last 6 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01..04 (all passed), 03-purchase/03-01-PLAN (passed).
- Trend: Plan 03-01 = 4 task commits + 1 summary commit, 3 deviations (Rule 1 SQLite drop-index-before-column, Rule 3 phpmd helper-extraction refactor, Rule 3 snake_case migration require_once). composer qa green / 40 tests / 124 assertions / 3 skip-guarded / 76.1 % coverage. **Phase 3 underway — PAY-04 + PAY-05 done. 5 plans / 9 requirements (PAY-01..03, PAY-06..11) still pending.**

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.

Carried forward from v3 plan synthesis (2026-04-22):

- `event_id` direction is server → frontend only. Never reverse.
- `content_ids` format locked to `SKU-{product_id}[-{offer_id}]` to match Facebook Catalog feed exporter.
- Paid-status trigger default = `new-payment-received` (Status ID=5), configurable dropdown.
- Idempotency via DB column `meta_purchase_event_id VARCHAR(36) NULL INDEX` on `lovata_orders_shopaholic_orders`.
- Boot-time missing `pixel_id` = log + disabled flag (NOT throw).
- No `assert()` anywhere — enforced by `spaze/phpstan-disallowed-calls`.
- Lead event wiring hooks salon application-form `onSend` (only functional lead form on site).
- v1 dead-letter sink = log + backend `FailedEvents` list + `onReplay`. External alerting deferred to v1.1.
- Folder layout = Lovata singular (`classes/{event,queue,helper,meta,exception}/` + `middleware/` at plugin root).
- Settings extends `Lovata\Toolbox\Models\CommonSettings`, NOT plain `Model`.

### Pending Todos

Deferred from Phase 1 code review (decide before Phase 2 starts):

- **BR-01** CI auth — `.github/workflows/metapixel-qa.yml` runs `composer install` at repo root which needs auth for private logingrupa/* deps. Recommended: composer GH OAuth secret. Will fail on first push without it. _(Still pending — Phase 5 launch.)_
- **LR-01** Namespace casing — `Logingrupa\Metapixelshopaholic` (current, lowercase) vs `Logingrupa\MetaPixelShopaholic` (PascalCase, matches sibling plugins). _(CLOSED — keep current; Plan 02-01 confirms via CONTEXT Area 4 Q1.)_
- **MR-02** phpmd script path widen — currently only scans `Plugin.php`; reopens at every phase. _(CLOSED — Plan 02-01 Task 6 widened to `Plugin.php,classes,middleware,models,components,controllers,updates` + .gitkeep dir placeholders.)_

New from Plan 02-01 execution:

- **HR-02** Pre-existing test-harness leak: Laravel's dotenv loader overrides `phpunit.xml <env force=true>` directives, silently routing tests to production MySQL. Worked around in Plan 02-01 via `createApplication()` programmatic config override. A repo-level fix (root-level `.env.testing` file, or a `Tests\BootsTestEnvironment` trait shared across all Logingrupa plugins) should land in Phase 5. Plugin-side workaround is acceptable for v1.

New from Plan 02-02 execution:

- **PG-01** PluginGuard's Throwable-catch in `prime()` is structural, not a workaround: it materially strengthens SKEL-05 by extending the "boot never throws" guarantee from "empty pixel_id only" to "any Settings read failure" (covers DB outage, missing system_settings table on fresh install, dotenv-leak misroutes). The catch is reason-documented and logs a structured context array distinguishing settings_read_failed from the empty-pixel_id path. No further action — accepted as the canonical PluginGuard contract.
- **PG-02** Container-singleton bridge `App::make('metapixel.disabled')` is now the canonical handler short-circuit contract for Phases 3-5. Documented in PluginGuard class-level PHPDoc + the Plan 02-02 SUMMARY's "API Surface" section. Every Phase 3+ event handler MUST start with `if (App::make('metapixel.disabled')) { return; }`.

New from Plan 02-03 execution:

- **MW-01** Phase 5 README HARD-05 MUST document `Cache-Control: private` requirement on routes hitting `EnsureFbpFbcCookies` middleware. T-02-16: shared-cache cookie leakage on CDN/Varnish if header omitted. TODO surfaced in middleware class-level PHPDoc. No code change needed in Phase 2-4 — operator documentation only.
- **MW-02** Defense-in-depth via `App::bound('metapixel.disabled') && App::make(...)` is the canonical pattern for any future storefront-only Logingrupa.Metapixelshopaholic middleware. Bound-guard handles requests arriving before Plugin::boot() primes PluginGuard.

New from Plan 03-01 execution:

- **MIG-01** Migration class files are snake_case (October Updates Manager convention) and are NOT PSR-4 discoverable from the plugin's `"Logingrupa\\Metapixelshopaholic\\": ""` autoload map. Tests that instantiate migration classes directly must `require_once __DIR__.'/../../updates/<filename>.php';`. Applied in `tests/Feature/MigrationsBootTest.php` + `tests/Feature/FailedEventModelTest.php`. Same pattern applies to plan 03-06 OrderStatusWatcherTest when it boots the orders schema.
- **MIG-02** SQLite-cannot-drop-indexed-columns — confirmed regression in `down()` migrations. Fix is `Schema::table(..., function (Blueprint $obTable) { $obTable->dropIndex($sIndexName); $obTable->dropColumn([...]); })` — drop the index FIRST. Applied in `updates/add_meta_purchase_event_id_to_orders_table.php`. Any future Phase 3+ migration that adds an indexed column must mirror the pattern in its `down()`.
- **MOD-01** phpmd CyclomaticComplexity threshold = 10 + NPathComplexity = 200. A static factory that branches on multiple `is_array/is_scalar/is_numeric/isset` guards quickly exceeds both. Solution: extract per-precondition private static helpers (e.g. `extractFirstEvent`, `extractStringField`, `encodePayload`, `extractHttpStatus`, `extractAttempts`). Pattern locked in for the rest of Phase 3 + 4 builders.
- **FE-01** MetaPixelException forward-reference suppressions in `models/FailedEvent.php` MUST be removed during plan 03-02 qa: 4 sites tagged `@phpstan-ignore-next-line class.notFound`. Plan 03-02's `composer analyse` will surface them as `ignore.unmatchedIdentifier` warnings — that's the removal cue.
- **FE-02** 3 createFromPayloadAndException tests in `tests/Feature/FailedEventModelTest.php` skip via `class_exists(MetaPixelException::class)`. They auto-run on the next `composer test` after plan 03-02 ships the abstract base. The anonymous-class double `extends \Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException` — runtime-bound, only reachable past the skip-guard.

Carried forward from Plan 02-04 execution:

- **PH-01** Plan 02-01 retro-fit (HIGH priority for Phase 5 launch OR Phase 3 pre-PAY-01): add `regex:/^\d{6,20}$/` validator to the `pixel_id` field in `models/settings/fields.yaml` per T-04-01. Without it a compromised admin could set pixel_id to `'); alert(1)//` and break out of the inlined `<script>` string in `components/pixelhead/default.htm`. Backend Settings authenticated trust boundary mitigates partially, but stored XSS surface remains.
- **PH-02** Phase 4 FUN-01 prerequisite: when `custom_data` becomes non-empty (`content_ids`, `value`, `currency`), the `arMetaEvent.custom_data|json_encode|raw` Twig chain MUST be paired with an explicit allowlist in `PixelHead::onRun()`. T-04-02 + T-04-05 are mitigated by `[]` in Phase 2 but reopen the moment Phase 4 lands.
- **PH-03** Phase 5 README HARD-04 + HARD-05: document the theme partial migration step — once `{% component 'pixelHead' %}` is included in a layout, the theme owner removes the legacy `fbq('track', 'PageView')` line from `themes/logingrupa-naisstore/partials/facebook_pixel.htm`. Until that step is executed, both partials fire and Meta counts the theme partial's no-eventID call as a separate event (T-04-04).
- **PH-04** Test-harness reflection-priming pattern (PluginGuard state via ReflectionClass instead of Settings::set→get round-trip) is the canonical Singleton+memoized test-double for Phases 3-5. Reusable for MetaClient (capi_access_token), OrderStatusWatcher (paid_status_code), etc. Documented in `tests/Feature/PixelHeadTest.php::primePluginGuardEnabled` + class PHPDoc.
- **PH-05** PluginGuard.php has `@method static self instance()` class-level PHPDoc to surface the October Singleton trait's actual return contract for phpstan level 10. Same pattern must be applied to ANY future Singleton-trait consumer in this plugin that wants to chain instance methods under phpstan scan.

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-05-12 — Plan 03-01 (Migrations + FailedEvent model — PAY-04 + PAY-05) shipped end-to-end. 4 task commits + 1 summary commit. composer qa green: 40 tests / 124 assertions / 3 skipped / 76.1 % coverage (PixelHead 94.4 % / middleware 96.1 % / PluginGuard 93.5 % / Settings 92.9 % / Plugin 52.0 % / FailedEvent 0.0 % — the static factory is exercised by the 3 currently-skipped tests). PAY-04 + PAY-05 complete. **Phase 3: 1 / 6 plans done.**
Last session: 2026-05-12
Stopped at: Plan 03-01 complete. Next: plan 03-02 (PAY-09 — exception hierarchy: MetaPixelException abstract base + 7 concrete subclasses). Plan 03-02 closes the forward-reference loop opened by 03-01.
Resume file: `.planning/phases/03-purchase-end-to-end/03-02-PLAN.md`
