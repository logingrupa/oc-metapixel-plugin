---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: milestone
status: phase-complete
stopped_at: "Phase 2 complete (all 6 SKEL requirements shipped across 4 plans). Next: Phase 3 planning (PAY-01..11 — Purchase end-to-end + MetaClient + SendCapiEvent queue + OrderStatusWatcher + UserDataHasher)."
last_updated: "2026-05-12T16:54:00.000Z"
last_activity: 2026-05-12 -- Plan 02-04 shipped (composer qa green, 26 tests / 89 assertions / 88.1 % coverage / PixelHead 100%). Phase 2 COMPLETE.
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 5
  completed_plans: 5
  percent: 100
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 02 — skeleton-cookie-fix

## Current Position

Phase: 02 (skeleton-cookie-fix) — COMPLETE
Plan: 4 of 4 (all 4 plans shipped — SKEL-01..06 done)
Status: Phase 02 complete; ready for Phase 03 planning
Last activity: 2026-05-12 -- Plan 02-04 shipped: PixelHead component + Twig partial + registerComponents + 8-case PixelHeadTest. composer qa green (26 tests / 89 assertions / 88.1 % coverage / PixelHead 100% / middleware 100% / PluginGuard 100%). SKEL-04 complete. Phase 2 closed; all 6 SKEL requirements done.

## Performance Metrics

**Velocity:**

- Total plans completed: 5 (Phase 1 plan + Plan 02-01 + Plan 02-02 + Plan 02-03 + Plan 02-04)
- Average duration: ~26 min (Plans 02-01 + 02-02 + 02-03 + 02-04 averaged: ~94+9 = 103 min / 4 plans = ~26 min); Phase 1 not timed
- Total execution time: ~1.7 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |
| 2. Skeleton+cookie | 4/4 | ~103 min | 26 min |

**Recent Trend:**

- Last 5 plans: 01-tooling/01-PLAN (passed), 02-skeleton/02-01-PLAN (passed), 02-skeleton/02-02-PLAN (passed), 02-skeleton/02-03-PLAN (passed), 02-skeleton/02-04-PLAN (passed)
- Trend: Plan 02-04 = 5 tasks (5 commits), 3 deviations (2× Rule 3 tooling normalize, 1× Rule 3 blocking issue — hermetic SQLite Multisite bleed worked around via reflection-priming). composer qa green / 26 tests / 89 assertions / 88.1 % coverage / PixelHead 100% / middleware 100% / PluginGuard 100%. **Phase 2 COMPLETE — all 6 SKEL requirements shipped.**

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

New from Plan 02-04 execution:

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

Last activity: 2026-05-12 — Plan 02-04 (PixelHead component — SKEL-04) shipped end-to-end. 5 task commits. composer qa green: 26 tests / 89 assertions / 88.1 % coverage (PixelHead 100 % / middleware 100 % / PluginGuard 100 % / Settings 91.7 % / Plugin 52.0 %). SKEL-04 complete. **Phase 2 COMPLETE — all 6 SKEL requirements (SKEL-01..06) shipped across 4 plans.**
Last session: 2026-05-12
Stopped at: Phase 2 complete. Next: Phase 3 planning kickoff — `/gsd:plan-phase 03` for the Purchase end-to-end requirements (PAY-01..11).
Resume file: `.planning/phases/03-purchase-end-to-end/` (to be created at Phase 3 planning kickoff)
