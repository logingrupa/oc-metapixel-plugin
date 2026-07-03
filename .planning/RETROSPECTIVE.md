# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v2.0.0 — Generic-event-tracking marketplace plugin

**Shipped:** 2026-07-04 (dev complete 2026-05-28; UAT + doc gap-closure through 2026-07-03)
**Phases:** 6 | **Plans:** 52 | **Commits:** 640 (2026-04-22 → 2026-07-04)

### What Was Built
- Adapter-pattern core: `EventSubjectAdapter` + `ValueResolver` interfaces, `AdapterRegistry`, 3 `Event::fire` hooks — third parties extend without touching plugin core
- Shopaholic fully decoupled: Order/CartPosition/Product adapters behind `PluginManager::exists` guard; plugin boots clean without any Lovata cart plugin
- Multisite settings (per-site pixel_id/token), TrustedHosts PSL allowlist, `_fbp`/`_fbc` cookie writer, FailedEvents backend UI with Replay
- ViewContent funnel at offer-level grain + PixelHead deferred flush at `cms.page.beforeRenderPage`
- Marketplace launch surface: README with live-smoke-verified walkthroughs, CUSTOM-ADAPTERS guide, CHANGELOG, screenshots

### What Worked
- RED→GREEN TDD waves (Phase 5/6): landing requirement rows + failing stubs first made per-plan scope unambiguous and verification mechanical
- Contract test base class (`EventSubjectAdapterContractTestCase`, 10 invariants) — every new adapter proves the marketplace contract for free
- Group-attribute test tagging (`#[Group('adapter')]`) over phpunit testsuite directories — sidestepped PHPUnit 12 overlapping-suite warnings and made the CI Run A/B split trivial
- Static enforcement of architecture locks (phpstan disallowed-calls banning `SiteManager`/`request()` in adapter/queue/event dirs) — P-01 class of bug became impossible, not just tested-against
- Operator-signed UAT gates with three-source evidence convergence caught real integration issues automated tests missed

### What Was Inefficient
- Stale planning docs cost real time at close: 01-VERIFICATION.md stayed gaps_found ~7 weeks after gaps were fixed; REQUIREMENTS.md Phase 3 rows stayed "Pending" after phase passed 12/12; STATE.md progress froze at 83% after Phase 6 shipped. Close required a forensic re-audit
- Milestone audit ran 2026-05-20 mid-milestone and was never refreshed — close pre-flight found it stale AND gaps_found, forcing a full re-audit
- Plan 05-02 hit a cross-repo execution boundary (theme repo vs plugin worktree) that blocked atomic commits — should have been caught at planning time
- Phase 5 sprawled: 19 plans on-disk vs 10 planned, with splits/respawns (05-02-RESPAWN, 05-13/05-14 extraction to Launch Milestone) — doc/UAT phases resist up-front decomposition

### Patterns Established
- Adapter directory isolation enforced by composer-dependency-analyser path-scoped rules (`classes/adapter/shopaholic/` is the only dir allowed to import `Lovata\OrdersShopaholic\*`)
- Browser+server event twins share one server-generated event_id; EventLog UNIQUE race-fence `(subject_type, subject_id, event_name, channel, site_id)` absorbs re-fires
- `$jsonable` over `'array'` cast for JSON-in-text columns (October idiom); Laravel-standard model property names override Hungarian for October-defined properties
- Verification-status flips require dated addendum with evidence (precedent: 02 human_needed→verified 2026-05-27; 01 gaps_found→passed 2026-07-04)

### Key Lessons
1. Flip verification/traceability docs in the same commit that closes the gap — stale gaps_found docs force expensive forensic re-audits at milestone close
2. Re-run `/gsd-audit-milestone` after the last phase ships, not just before close — mid-milestone audits go stale silently
3. When a fix supersedes the originally-specced mechanism (testsuite → group attributes), record the supersession in the verification doc, or the old gap looks open forever
4. Cross-repo tasks (theme + plugin) need explicit execution-boundary callouts in PLAN.md — a single-worktree executor cannot atomically commit both sides
5. Doc/UAT-heavy phases should be planned coarse with expected splits, not fine — Phase 5's 10→19 plan growth was normal iteration, not scope creep

### Cost Observations
- Model mix: opus (planner/researcher) + sonnet (checker/integration) per profile; execution mostly opus
- Notable: integration-checker subagent verified 6 connections + 5 flows in ~2 min / ~81k tokens — far cheaper than inline orchestrator reading

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Phases | Plans | Key Change |
|-----------|--------|-------|------------|
| v1.1.1 | 5 (partial close) | 16/21 | Shopaholic-coupled; architecture pivot decided at close |
| v2.0.0 | 6 | 52 | Adapter pattern; RED→GREEN waves; operator-signed UAT gates; static architecture locks |

### Cumulative Quality

| Milestone | Tests | Coverage | Static bar |
|-----------|-------|----------|------------|
| v1.1.1 | 177 | 82.8% | phpstan lvl 10 |
| v2.0.0 | ~466 (full-Lovata cell) | ≥90% gate held (91–100% per phase) | phpstan lvl 10 + phpmd 0 + dual PHP 8.3/8.4 CI |

### Top Lessons (Verified Across Milestones)

1. Fail-fast + static enforcement beats convention: every "banned by phpstan rule" decision (no assert(), no SiteManager in adapters, no PHP 8.4-only syntax) held without regressions across both milestones
2. Server-authoritative event_id + plugin-owned audit table (v1.1.1 Phase 3.1 decision) survived the v2.0 rewrite unchanged — invest in decisions, not code
