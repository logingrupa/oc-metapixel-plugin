---
gsd_state_version: 1.0
milestone: v2.0.0
milestone_name: Generic-event-tracking marketplace plugin
status: executing_phase_1_wave_2_pending
last_updated: "2026-05-16T05:25:00.000Z"
last_activity: 2026-05-16
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 3
  completed_plans: 1
  percent: 6
---

# Project State

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + CAPI plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

See `.planning/PROJECT.md` "Current Milestone" section for full feature list + locked decisions.
See `.planning/ROADMAP.md` for 5-phase v2.0.0 roadmap with success criteria.
See `.planning/REQUIREMENTS.md` for 61 v2 requirements + traceability table.

## Current Position

Phase: 1 — Tooling + composer + namespace rename + CI matrix
Plan: 01-01 SHIPPED — next: 01-02 (TOOL-04..07, 10, 11 — phpstan/rector/pint/phpmd configs + composer qa chain + dependency-analyser)
Status: Plan 01-01 complete; awaiting Plan 01-02 execution
Last activity: 2026-05-16 — Plan 01-01 executed: v1.x source removed, v2.0 minimal scaffold written (Plugin.php + plugin.yaml + composer.json + lang/{en,lv}/lang.php), directory filesystem-renamed metapixelshopaholic → metapixel

**Next action:** `/gsd-execute-plan 01-02` to execute Phase 1 Plan 02 (tooling configs + composer qa chain).

## Roadmap Snapshot

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 1 | Tooling + composer + namespace rename + CI matrix | TOOL-01..11 (11) | Ready to plan |
| 2 | Adapter system core | ADAP-01..11 (11) | Not started |
| 3 | ShopaholicAdapter + ThemeActionAdapter | SHOP-01..05 + THEM-01..07 (12) | Not started |
| 4 | Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations | MULT-01..06 + HOST-01..06 + COOK-01..03 + FAIL-01..03 + LANG-01 (19) | Not started |
| 5 | Documentation + marketplace launch | DOCS-01..03 + MKT-01..05 (8) | Not started |

**Coverage:** 61/61 v2 requirements mapped (100%). 0 orphaned.

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
- **CR-02 TrustedHosts allowlist.** v1.x hardcoded HOST_INDEX_MAP; v2.0 operator-supplies via Settings + `jeremykendall/php-domain-parser` for multi-TLD index derivation. Untrusted host → skip cookies (fail-safe). Owned by Phase 4 (HOST-01..06).
- **CR-03 fbclid validation.** `[A-Za-z0-9_-]` charset, ≤255 chars. Invalid → skip `_fbc`. Carried forward in Phase 4 COOK-02.
- **Idempotency via EventLog UNIQUE race-fence.** `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record` returns false on collision or DB failure (fail-safe). Kept verbatim per ARCHITECTURE.md §2.
- **Multi-site site_id from Subject model attribute.** v1.x reads `Order.site_id` (Lovata column); v2.0 generalizes to `EventSubjectAdapter::getSiteId(object $obSubject): ?int`. Owned by Phase 2 ADAP-01/ADAP-06.
- **PluginGuard pattern.** Empty `pixel_id` → `Log::warning` + disabled flag, never throw at boot. Carried forward verbatim per ARCHITECTURE.md §2.
- **No `assert()`** — prod `zend.assertions=0` silently no-ops. Enforced by `spaze/phpstan-disallowed-calls`. Locked in Phase 1 TOOL-04.
- **content_ids format = `SKU-{product_id}[-{offer_id}]`** for Shopaholic adapter (matches Facebook Catalog feed). Other adapters define own format via `ValueResolver::resolveContentIds()`. Owned by Phase 3 SHOP-02.
- **Anonymous external_id** = sha256 of subject's unique token (Order.secret_key, Session id, etc.) per adapter. Owned by Phase 2 ADAP-08.

### v2.0 architectural decisions (new — locked at milestone start)

- **Namespace:** `Logingrupa\Metapixel` (drop "Shopaholic"). Owned by Phase 1 TOOL-03.
- **Plugin dir:** `plugins/logingrupa/metapixel/`. Owned by Phase 1 TOOL-02.
- **PHP support:** `"php": "^8.3 || ^8.4"` — avoid 8.4-only syntax (no property hooks, asymmetric visibility, `array_find`/`array_any`/`array_all`/`array_find_key`, `#[\Deprecated]`). Enforced by Phase 1 TOOL-04..06.
- **Composer suggest pattern** — `lovata/shopaholic-plugin` becomes `suggest:`. Plugin works without Shopaholic. Owned by Phase 1 TOOL-01.
- **Graph API pinned to `v23.0`** — v20 expires 2026-09-24. Owned by Phase 2 ADAP-09.
- **Lovata-style extensibility:**
  - `AdapterRegistry::register(string $sSubjectClass, string $sAdapterClass)` — third parties register custom adapters from their `Plugin::boot()`. Owned by Phase 2 ADAP-03.
  - Three `Event::fire` decision-point hooks (`metapixel.event.before_dispatch`, `after_dispatch`, `dead_letter`) with documented payload mutability contracts. Owned by Phase 2 ADAP-04.
  - Five additional hooks (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup) DEFERRED to v2.1 until real third-party use case surfaces.
  - `Component::extend(...)` + `addDynamicMethod(...)` on PixelHead and FailedEvents controller (operator-prefix convention for namespacing).
- **Multisite trait** on `pixel_id` + `capi_access_token` Settings fields (per-site overrides). `$propagatable = []` lock prevents cross-site token leak. Owned by Phase 4 MULT-01..06.
- **Build philosophy (from `feedback-no-overengineering-fresh-simple` memory):** Simple logic, fresh ideas, no over-engineering. No BC shims (operators stay on `legacy/v1.1.1` branch). No dead code, no unused functions, no premature abstractions. Build for current need only.
- **Code style additions (from `feedback-lovata-extensibility-pattern` memory):** DRY, SRP, self-explanatory variable names (no `$mId`, `$tmp`), Laravel short docblocks (one-line summary + `@param` + `@return`; no multi-paragraph narrative), no phase/CR/incident markers in code.

### Pitfall ownership (each CRITICAL/HIGH pitfall mapped to a phase)

See ROADMAP.md "Pitfall Coverage Map" section.

Anchored CRITICALs:
- **P-01 Cross-context resolution drift** (Phase 3.1-07 production bug anchor): Phase 2 ADAP-06 prevents via SiteResolver::forSubject + PHPStan disallowed-calls + contract test.
- **P-03 Hidden Lovata imports outside adapter dir**: Phase 1 TOOL-11 prevents via composer-dependency-analyser; Phase 3 SHOP-04 enforces at adapter boundary.
- **P-05 EventLog subject_type alias ambiguity**: Phase 2 ADAP-01 locks alias-string convention; Phase 3 SHOP-01 returns `'shopaholic.order'`.
- **P-06 PHP 8.4-only syntax slips**: Phase 1 TOOL-04 phpstan `phpVersion: 80300` + TOOL-05 Rector UP_TO_PHP_83 + TOOL-06 Pint nullable rule.
- **P-15 TrustedHosts marketplace blocker**: Phase 4 HOST-01..06 MUST close before Phase 5 marketplace launch.

### Pending Todos

- `/gsd-execute-plan 01-02` to execute Phase 1 Plan 02 (phpstan/rector/pint/phpmd configs + composer qa chain + composer-dependency-analyser).
- `/gsd-execute-plan 01-03` to execute Phase 1 Plan 03 (Pest scaffold + .github/workflows/metapixel-qa.yml CI matrix).

### Blockers/Concerns

(none — Plan 01-01 shipped cleanly; standalone-repo composer install limitation documented in 01-01-SUMMARY.md "Deviations". Full autoload smoke-test deferred to host-repo integration.)

## Session Continuity

Last session: 2026-05-16 — Plan 01-01 executed. Plugin source reset on master to v2.0 minimal scaffold (Plugin.php + plugin.yaml + composer.json + lang/{en,lv}/lang.php). Containing dir filesystem-renamed metapixelshopaholic → metapixel. v1.x preserved on legacy/v1.1.1 branch (SHA 3f32ca6).

Stopped at: post-Plan 01-01 ship. Next: `/gsd-execute-plan 01-02` for tooling configs + composer qa chain.

Resume file: `.planning/phases/01-tooling-composer-namespace-rename-ci-matrix/01-02-PLAN.md`.

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files | Date |
|-------|------|----------|-------|-------|------|
| 1 | 01-01 | ~12 min | 6 (4 active, 2 deferred) | 5 created, 71 deleted | 2026-05-16 |
