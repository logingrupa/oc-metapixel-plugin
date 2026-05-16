---
gsd_state_version: 1.0
milestone: v2.0.0
milestone_name: Generic-event-tracking marketplace plugin
status: phase_1_executed_pending_verification
last_updated: "2026-05-16T06:05:00.000Z"
last_activity: 2026-05-16
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 3
  completed_plans: 3
  percent: 20
---

# Project State

## Active Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Goal:** Decouple plugin from Shopaholic via Lovata-style extensible adapter pattern. Marketplace-grade Meta Pixel + CAPI plugin sellable to any OctoberCMS operator regardless of cart-plugin. Third parties can register custom adapters without modifying plugin core. PHP 8.3 + 8.4 dual support.

See `.planning/PROJECT.md` "Current Milestone" section for full feature list + locked decisions.
See `.planning/ROADMAP.md` for 5-phase v2.0.0 roadmap with success criteria.
See `.planning/REQUIREMENTS.md` for 61 v2 requirements + traceability table.

## Current Position

Phase: 1 — Tooling + composer + namespace rename + CI matrix — EXECUTED (3/3 plans shipped)
Plan: 01-03 SHIPPED — Phase 1 awaiting verification (`/gsd-verify-phase 01`)
Status: All 3 plans complete; Phase 1 execution closed; TOOL-01..11 (11) requirements satisfied
Last activity: 2026-05-16 — Plan 01-03 executed: phpunit.xml + tests/MetapixelTestCase + tests/ShopaholicAdapterTestCase + tests/Pest.php + tests/Unit/PluginSanityTest.php + .github/workflows/metapixel-qa.yml landed in single atomic commit 64b5762; smoke chain green (pint+phpstan+phpmd+pest), Plugin.php coverage 100%

**Next action:** `/gsd-verify-phase 01` to run Phase 1 verifier — then `/gsd-execute-phase 02` for Adapter system core.

## Roadmap Snapshot

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 1 | Tooling + composer + namespace rename + CI matrix | TOOL-01..11 (11) | Executed (3/3 plans) — pending verification |
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

- `/gsd-verify-phase 01` to verify Phase 1 execution outcomes (3 plans / 11 TOOL-* requirements).
- Phase 2 PHPStan `paths` reopen: when classes/, models/, components/ land, append each to phpstan.neon paths list.
- Phase 2+ phpunit.xml `<source><include>` reopen: when classes/, models/, components/, middleware/, controllers/, console/ land, add each as `<directory>` entry alongside existing `Plugin.php`.
- Phase 3 SHOP-* adds `<testsuite name="Metapixel Adapter Tests">` block to phpunit.xml when tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic land (Run B's --exclude-testsuite='Metapixel Adapter Tests' becomes a real exclude then; currently a no-op).
- Phase 2 ADAP-03 wires AdapterRegistry::flush() call into MetapixelTestCase::flushModelEventListeners() (currently absent — Phase 1 plan 01-03 intentionally did not add a placeholder comment).

### Blockers/Concerns

(none — Plan 01-03 shipped cleanly; standalone-repo composer install limitation persists from 01-01/01-02 — smoke tests executed via host vendor binaries, documented in 01-03-SUMMARY.md "Smoke-Test Path Deviations". Full qa chain integration smoke (including composer-dependency-analyser) deferred to CI matrix.)

## Session Continuity

Last session: 2026-05-16 — Plan 01-03 executed. Pest 4 test scaffold + GitHub Actions CI matrix landed: phpunit.xml (PHPUnit 12 config + 2 testsuites + SQLite-in-memory), tests/MetapixelTestCase.php (170 LOC, no cart deps), tests/ShopaholicAdapterTestCase.php (85 LOC, Lovata Orders hermetic), tests/Pest.php (uses() binding for both bases), tests/Unit/PluginSanityTest.php (3 tests / 5 assertions / 100% Plugin.php coverage), .github/workflows/metapixel-qa.yml (2x2 matrix php:[8.3,8.4] × install:[full-lovata,minimal], Run A --min=90, Run B excludes Adapter testsuite). Single atomic commit 64b5762. Auto-fixes during smoke: Plugin instantiation (`new Plugin($this->app)`) + register/boot coverage (3rd test method).

Stopped at: post-Plan 01-03 ship. Phase 1 EXECUTED (3/3 plans, 11/11 TOOL-* requirements). Next: `/gsd-verify-phase 01`, then `/gsd-execute-phase 02` for ADAP-01..11 (adapter system core).

Resume file: `.planning/STATE.md` — Phase 1 closed; choose `/gsd-verify-phase 01` to verify, or `/gsd-plan-phase 02` to start next.

## Performance Metrics

| Phase | Plan | Duration | Tasks | Files | Date |
|-------|------|----------|-------|-------|------|
| 1 | 01-01 | ~12 min | 6 (4 active, 2 deferred) | 5 created, 71 deleted | 2026-05-16 |
| 1 | 01-02 | ~14 min | 9 (7 active, 1 skipped, 1 smoke-only) | 5 created, 2 modified | 2026-05-16 |
| 1 | 01-03 | ~18 min | 8 (7 active, 1 smoke-only) | 6 created, 0 modified | 2026-05-16 |
