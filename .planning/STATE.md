---
gsd_state_version: 1.0
milestone: v2.0.0
milestone_name: Generic-event-tracking marketplace plugin
status: planning
last_updated: "2026-05-15T11:04:19.552Z"
last_activity: 2026-05-15
progress:
  total_phases: 0
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + CAPI plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

See `.planning/PROJECT.md` "Current Milestone" section for full feature list + locked decisions.

## Current Position

Phase: Not started (defining requirements)
Plan: —
Status: Defining requirements
Last activity: 2026-05-15 — Milestone v2.0.0 started; v1.1.1 archived; phase dirs moved to `.planning/archive/v1.1.1/`

## Previously Shipped: v1.1.1

Closed 2026-05-15. Partial close — Phase 4 + 5 dropped on architecture pivot. See [`milestones/v1.1.1-ROADMAP.md`](milestones/v1.1.1-ROADMAP.md) for full archive.

- 5 phases complete (1, 2, 3.1, 3.1-07, 3.1-08)
- 28/50 v1 requirements validated; 22 dropped; 2 staging-deferred to operator
- 16/21 plans (76%)
- 207 commits, 11,027 PHP lines
- 177 tests / 0 failed (82.8% coverage)
- composer qa green end-to-end
- Tag `v1.1.1` annotated local-only at SHA `3f32ca6`
- Legacy branch `legacy/v1.1.1` preserves full v1.x codebase + tests + `.planning/`

## Accumulated Context

### Decisions carried forward from v1.x (locked, do NOT re-derive)

- **event_id direction = server → frontend.** Meta dedupes on event_id match within ±10s. Never reverse.
- **CR-02 TrustedHosts allowlist.** v1.x hardcoded HOST_INDEX_MAP; v2.0 operator-supplies via Settings + `jeremykendall/php-domain-parser` for multi-TLD index derivation. Untrusted host → skip cookies (fail-safe).
- **CR-03 fbclid validation.** `[A-Za-z0-9_-]` charset, ≤255 chars. Invalid → skip `_fbc`.
- **Idempotency via EventLog UNIQUE race-fence.** `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record` returns false on collision or DB failure (fail-safe).
- **Multi-site site_id from Subject model attribute.** v1.x reads `Order.site_id` (Lovata column); v2.0 generalizes to `EventSubjectAdapter::getSiteId(object $obSubject): ?int`.
- **PluginGuard pattern.** Empty `pixel_id` → `Log::warning` + disabled flag, never throw at boot.
- **No `assert()`** — prod `zend.assertions=0` silently no-ops. Enforced by `spaze/phpstan-disallowed-calls`.
- **content_ids format = `SKU-{product_id}[-{offer_id}]`** for Shopaholic adapter (matches Facebook Catalog feed). Other adapters define own format via `ValueResolver::resolveContentIds()`.
- **Anonymous external_id** = sha256 of subject's unique token (Order.secret_key, Session id, etc.) per adapter.

### v2.0 architectural decisions (new — locked at milestone start)

- **Namespace:** `Logingrupa\Metapixel` (drop "Shopaholic").
- **Plugin dir:** `plugins/logingrupa/metapixel/`.
- **PHP support:** `"php": "^8.3 || ^8.4"` — avoid 8.4-only syntax (no property hooks, asymmetric visibility, `array_find`/`array_any`, `#[\Deprecated]`).
- **Composer suggest pattern** — `lovata/shopaholic-plugin` becomes `suggest:`. Plugin works without Shopaholic.
- **Lovata-style extensibility:**
  - `AdapterRegistry::register(string $sSubjectClass, string $sAdapterClass)` — third parties register custom adapters from their `Plugin::boot()`
  - `Event::fire('metapixel.event.before_dispatch', [$obAdapter, $obSubject, &$arPayload])` at decision boundaries
  - `Component::extend(...)` + `addDynamicMethod(...)` on PixelHead and FailedEvents controller
  - Service container bindings for `MetaClientInterface` swap
- **Multisite trait** on `pixel_id` + `capi_access_token` Settings fields (per-site overrides).
- **Code style additions:** DRY, SRP, self-explanatory variable names (no `$mId`, `$tmp`), Laravel short docblocks (one-line summary + `@param` + `@return`; no multi-paragraph narrative), no phase/CR/incident markers in code.

### Pending Todos

(none — v2.0 milestone just started; requirements + roadmap to be defined)

### Blockers/Concerns

(none)

## Session Continuity

Last session: 2026-05-15 — v1.1.1 milestone archived; v2.0.0 milestone initialized; entering research + requirements definition phase.

Stopped at: post-milestone-switch. Next: 4 parallel `gsd-project-researcher` agents (STACK + FEATURES + ARCHITECTURE + PITFALLS), then `gsd-research-synthesizer` → SUMMARY.md → requirements definition → roadmapper.
Resume file: `.planning/PROJECT.md`
