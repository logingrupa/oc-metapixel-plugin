---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: MetapixelShopaholic
status: in_progress
stopped_at: Phase 1 complete — Phase 2 (Skeleton + cookie fix) next
last_updated: "2026-05-12T00:00:00.000Z"
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 1
  completed_plans: 1
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 2 — Skeleton + cookie fix

## Current Position

Phase: 1 complete; 2 not started
Plan: —
Status: Phase 1 (Tooling) shipped — composer qa green, all 5 ROADMAP success criteria PASS, code review findings remediated (4 commits). Ready to plan Phase 2.
Last activity: 2026-05-12 — Phase 1 verified passed (pcov installed, qa runs clean without shim)

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| 1. Tooling | 1 | — | — |

**Recent Trend:**

- Last 5 plans: 01-tooling/01-PLAN (passed)
- Trend: —

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

- **BR-01** CI auth — `.github/workflows/metapixel-qa.yml` runs `composer install` at repo root which needs auth for private logingrupa/* deps. Recommended: composer GH OAuth secret. Will fail on first push without it.
- **LR-01** Namespace casing — `Logingrupa\Metapixelshopaholic` (current, lowercase) vs `Logingrupa\MetaPixelShopaholic` (PascalCase, matches sibling plugins). Rename cheaper now than later.
- **MR-02** phpmd script path widen — currently only scans `Plugin.php`; reopens at every phase.

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-05-12 — Phase 1 (Tooling) shipped end-to-end via `/gsd-autonomous --only 1`. composer qa green, 14 commits (1 repo-root + 13 plugin), VERIFICATION.md status: passed.
Last session: 2026-05-12
Stopped at: Phase 1 complete. Next: `/gsd-plan-phase 2` for Skeleton + cookie fix (SKEL-01..06).
Resume file: `.planning/ROADMAP.md`
