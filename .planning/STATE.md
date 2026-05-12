---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: executing
stopped_at: "Plan 02-03 complete (EnsureFbpFbcCookies middleware + SKEL-03). Next: Plan 02-04 (PixelHead component, SKEL-04)."
last_updated: "2026-05-12T17:05:00.000Z"
last_activity: 2026-05-12 -- Plan 02-03 shipped (composer qa green, 18 tests / 72 assertions / 89.1 % coverage)
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 5
  completed_plans: 3
  percent: 60
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 02 (skeleton-cookie-fix) — EXECUTING
Plan: 4 of 4 (Plans 02-01 + 02-02 + 02-03 shipped, Plan 02-04 next)
Status: Executing Phase 02
Last activity: 2026-05-12 -- Plan 02-03 shipped: EnsureFbpFbcCookies middleware + Plugin::boot pushMiddleware + 9-invariant feature test. composer qa green (18 tests / 72 assertions / 89.1 % coverage / middleware 100%). SKEL-03 complete.

## Performance Metrics

**Velocity:**

- Total plans completed: 4 (Phase 1 plan + Plan 02-01 + Plan 02-02 + Plan 02-03)
- Average duration: ~31 min (Plans 02-01 + 02-02 + 02-03 averaged); Phase 1 not timed
- Total execution time: ~1.6 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 3/4 | ~94 min | 31 min |

**Recent Trend:**

- Last 5 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01-PLAN (passed), 02-skeleton/02-02-PLAN (passed), 02-skeleton/02-03-PLAN (passed)
- Trend: Plan 02-03 = 4 tasks (3 commits + 1 verify-only), 0 deviations. composer qa green / 18 tests / 72 assertions / 89.1 % coverage / middleware 100% / PluginGuard 100%.

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

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-05-12 — Plan 02-03 (EnsureFbpFbcCookies middleware — SKEL-03) shipped end-to-end. 3 task commits (Task 4 verify-only). composer qa green: 18 tests / 72 assertions / 89.1 % coverage (middleware 100 % / PluginGuard 100 % / Settings 91.7 % / Plugin 59.1 %). SKEL-03 complete.
Last session: 2026-05-12
Stopped at: Plan 02-03 complete. Next: Plan 02-04 (PixelHead component — SKEL-04).
Resume file: `.planning/phases/02-skeleton-cookie-fix/02-04-PLAN.md`
