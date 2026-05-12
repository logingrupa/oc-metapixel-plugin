---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: executing
stopped_at: "Plan 02-01 complete (SKEL-01 metadata layer + SKEL-02 + SKEL-06). Next: Plan 02-02 (PluginGuard helper, SKEL-05)."
last_updated: "2026-05-12T16:25:00.000Z"
last_activity: 2026-05-12 -- Plan 02-01 shipped (composer qa green, 6 tests / 45 assertions)
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 5
  completed_plans: 1
  percent: 20
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 02 (skeleton-cookie-fix) — EXECUTING
Plan: 2 of 4 (Plan 02-01 shipped, Plan 02-02 next)
Status: Executing Phase 02
Last activity: 2026-05-12 -- Plan 02-01 shipped: Plugin.php rewrite + Settings model + fields.yaml + lang scaffolding + SettingsRegistrationTest. composer qa green (6 tests / 45 assertions / 73.3 % coverage).

## Performance Metrics

**Velocity:**

- Total plans completed: 2 (Phase 1 plan + Plan 02-01)
- Average duration: ~41 min (Plan 02-01) — Phase 1 not timed
- Total execution time: ~0.7 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 1/4 | ~41 min | 41 min |

**Recent Trend:**

- Last 5 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01-PLAN (passed)
- Trend: Plan 02-01 = 8 tasks, 8 commits, 5 auto-fixed deviations (2 pre-existing harness leaks fixed: dotenv overrides phpunit env, SQLite migration chain too slow). composer qa green / 6 tests passing / 73.3 % coverage.

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

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-05-12 — Plan 02-01 (Phase 2 plugin skeleton + Settings) shipped end-to-end. 8 task commits + final SUMMARY commit. composer qa green: 6 tests / 45 assertions / 73.3 % coverage on Plugin.php + models/Settings.php. SKEL-01 (metadata layer), SKEL-02, SKEL-06 marked complete.
Last session: 2026-05-12
Stopped at: Plan 02-01 complete. Next: Plan 02-02 (PluginGuard helper + boot-time disabled flag — SKEL-05).
Resume file: `.planning/phases/02-skeleton-cookie-fix/02-02-PLAN.md`
