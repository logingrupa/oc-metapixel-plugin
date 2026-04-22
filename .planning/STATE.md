---
gsd_state_version: 1.0
milestone: v1.0.0
milestone_name: MetapixelShopaholic
status: in_progress
stopped_at: Milestone bootstrapped — Phase 1 not started
last_updated: "2026-04-22T00:00:00.000Z"
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-22)

**Core value:** Meta Ads Manager sees every purchase (including bank-transfer and admin-marked-paid), dedup ≥ 80 %, EMQ ≥ 8 for Purchase
**Current focus:** Phase 1 — Tooling

## Current Position

Phase: Not started (defining requirements complete)
Plan: —
Status: Defining requirements complete, ready to plan Phase 1
Last activity: 2026-04-22 — Milestone v1.0.0 started

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| — | — | — | — |

**Recent Trend:**

- Last 5 plans: none
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

None yet.

### Blockers/Concerns

None. All 5 open questions resolved via codebase evidence (see `.planning/answers/`).

### Quick Tasks Completed

| # | Description | Date | Commit | Status | Directory |
|---|---|---|---|---|---|
| 20260422 | Metapixel plan v2→v3 refactor — 7 parallel audits, all 5 open questions resolved via codebase evidence. Plan files committed to plugin repo. | 2026-04-22 | c14ee5c | Complete | (plugin root `.planning/`) |

## Session Continuity

Last activity: 2026-04-22 — `/gsd-new-milestone` bootstrapped v1.0.0 MetapixelShopaholic milestone inside plugin repo
Last session: 2026-04-22
Stopped at: Milestone artifacts written (PROJECT, REQUIREMENTS, ROADMAP, STATE, config). Next: `/gsd-plan-phase 1` for Tooling phase planning.
Resume file: `.planning/ROADMAP.md`
