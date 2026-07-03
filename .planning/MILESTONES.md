# Milestones

## v2.0.0 Generic-event-tracking marketplace plugin (Shipped: 2026-07-03)

**Phases completed:** 6 phases, 52 plans, 147 tasks

**Key accomplishments:**

- Adapter pattern core shipped — `EventSubjectAdapter` + `ValueResolver` interfaces, `AdapterRegistry` singleton, 3 `Event::fire` hooks (halt-able before_dispatch, after_dispatch, dead_letter); third parties register adapters from their own `Plugin::boot()` without touching plugin core.
- Shopaholic fully decoupled — Order/CartPosition/Product adapters registered behind a `PluginManager::exists('Lovata.OrdersShopaholic')` guard; minimal installs boot clean (CI Run B); composer-dependency-analyser enforces the Lovata import boundary to `classes/adapter/shopaholic/`.
- Multisite settings rework — per-site `pixel_id`/`capi_access_token` via October 4 Multisite trait, operator-supplied TrustedHosts allowlist with PSL-aware parsing, `_fbp`/`_fbc` cookie writer with kill switch + fbclid validation, FailedEvents backend UI with synchronous Replay + dedup-status check.
- ViewContent funnel closed at offer-level grain — ProductPageWatcher fires browser+server twins with shared event_id on PDP open; offer-switch JS re-fires via hybrid AJAX path; PixelHead refactored to deferred flush at `cms.page.beforeRenderPage`.
- Quality bar held throughout — PHPStan level 10, PHP 8.3/8.4 dual CI matrix, ≥90% coverage gate (~15.5k test LOC vs ~6.1k source LOC), Pest 4 hermetic SQLite suites, phpmd 0 violations.
- Marketplace launch surface authored — 202-line README with live-smoke-verified walkthroughs, CUSTOM-ADAPTERS guide, CHANGELOG, 5 dummy-value screenshots; operator-signed UAT gates for PageView/AddToCart/Purchase browser+server dedup.

**Stats:** 640 commits, 2026-04-22 → 2026-07-04 (~10 weeks), ~6.1k source LOC + ~15.5k test LOC PHP.

**Known verification overrides:** 1 — init.manager phase-2 plan-count projection quirk (tool counts 11 plans, disk + ROADMAP carry 9/9 with VERIFICATION passed). Substance verified in v2.0.0 milestone audit.

**Known deferred items:** 8 (see STATE.md Deferred Items) — incl. FailedEvents multisite-replay-creds tech debt + Launch Milestone carry-overs (05-13 redact, 05-14 public flip, v2.0.0 tag re-verify).

---

## v1.1.1 : Logingrupa.MetapixelShopaholic — Shopaholic-coupled Meta Pixel + CAPI (Backfilled: 2026-07-03)

**Note:** Synthesized from archive snapshot by `/gsd-health --backfill`. Original completion date unknown.

---
