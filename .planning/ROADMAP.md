# Roadmap: Logingrupa.Metapixel

## Milestones

- ✅ **v2.0.0 Generic-event-tracking marketplace plugin** — Phases 1-6 (shipped 2026-07-04). Full details: `.planning/milestones/v2.0.0-ROADMAP.md`
- ✅ **Launch Milestone** — marketplace publication complete 2026-07-04: repo public, annotated v2.0.0 tag pushed, MIT license, CI 4/4 green at tag. Log: `.planning/phases/05-documentation-marketplace-launch/05-14-LAUNCH-LOG.md`
- ✅ **v1.1.1 Shopaholic-coupled Meta Pixel + CAPI** — prior milestone (2026-04-22 → 2026-05-14), partial close 28/50 requirements, superseded by v2.0 architecture pivot. Archived: `.planning/milestones/v1.1.1-ROADMAP.md` + `.planning/archive/`

## Phases

<details>
<summary>✅ v2.0.0 Generic-event-tracking marketplace plugin (Phases 1-6) — SHIPPED 2026-07-04</summary>

- [x] Phase 1: Tooling + composer + namespace rename + CI matrix (3/3 plans) — completed 2026-05-16, verification re-passed 2026-07-04
- [x] Phase 2: Adapter system core — contracts + registry + extension hooks (9/9 plans) — completed 2026-05-17
- [x] Phase 3: ShopaholicAdapter + ThemeActionAdapter parallel wave (10/10 plans) — completed 2026-05-18
- [x] Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations (5/5 plans) — completed 2026-05-20
- [x] Phase 5: Documentation + marketplace launch (19 plans) — completed 2026-07-03 (05-13 + 05-14 split to Launch Milestone)
- [x] Phase 6: ViewContent funnel — Shopaholic PDP + offer-switch (7/7 plans) — completed 2026-05-28

Milestone audit: PASSED 2026-07-04 — 61/61 requirements, 6/6 integration connections, 5/5 E2E flows. Report: `.planning/milestones/v2.0.0-MILESTONE-AUDIT.md`

</details>

### ✅ Launch Milestone (Complete 2026-07-04)

Pre-flip security sweep + public repo flip + `v2.0.0` annotated tag. Triggered when operator decides to launch; not gated by phase progress.

- [x] launch-01: Redact operator-specific values from public artifacts
- [x] launch-02: Public flip + v2.0.0 annotated tag + README `:dev-master` re-verify — completed 2026-07-04 (LAUNCH COMPLETE)

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Tooling + composer + namespace + CI | v2.0.0 | 3/3 | Complete | 2026-05-16 |
| 2. Adapter system core | v2.0.0 | 9/9 | Complete | 2026-05-17 |
| 3. ShopaholicAdapter + ThemeActionAdapter | v2.0.0 | 10/10 | Complete | 2026-05-18 |
| 4. Settings rework + Multisite + TrustedHosts | v2.0.0 | 5/5 | Complete | 2026-05-20 |
| 5. Documentation + marketplace launch | v2.0.0 | 19 | Complete | 2026-07-03 |
| 6. ViewContent funnel — PDP + offer-switch | v2.0.0 | 7/7 | Complete | 2026-05-28 |
| Launch Milestone | launch | 2/2 | Complete | 2026-07-04 |

## Backlog

Deferred to v2.1+ (see `.planning/milestones/v2.0.0-REQUIREMENTS.md` — Future Requirements):

- **v2.1 MallAdapter** (MALL-01) — `OFFLINE\Mall\Models\Order` adapter; reference example in `docs/CUSTOM-ADAPTERS.md`
- **v2.1 MeloncartAdapter** (MELON-01) — requires paid Meloncart plugin install
- **v2.1 Additional Event::fire hooks** (EXT-01..05) — adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup
- **v2.1 Debug / Test-Events panel** (DBG-01) — backend last-100 EventLog rows with payload preview
- **v2.x Ops integrations** (OPS-01..02) — Slack/email/Telegram dead-letter alerting
- **v2.x Auto PSL refresh** (PSL-01) — operator-opt-in cron for automatic PSL refresh
- **Tech debt (from v2.0.0 close):** FailedEvents Replay honors per-site credentials (`Settings::lookupForSite` on `FailedEvent.site_id` — currently primary-site only, `controllers/FailedEvents.php:28-35`); per-row Replay UI trigger; optional queue for CAPI server events (todo 2026-05-27)
