# Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-19
**Phase:** 04-settings-rework-multisite-trustedhosts-cookie-failedevents-translations
**Areas discussed:** Multisite migration + fallback semantics, FailedEvents Replay execution model, PSL bundling + refresh model, Trusted-hosts UX + Settings tab structure

User also reaffirmed a cross-cutting meta-decision: "No legacy code from v1, new fresh best practices approach." Aligns with plugin `CLAUDE.md` "Build philosophy" lock — recorded as D-20 in CONTEXT.md.

---

## Area 1 — Multisite migration + fallback semantics

### Q1.1 — Per-site empty/NULL fallback behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Fall back to default-row value silently | Multisite trait reads per-site row first; if pixel_id is empty/NULL, returns default-row value. Operator configures once, all sites inherit until they override. | ✓ |
| Strict per-site — NULL = plugin disabled for that site | PluginGuard disables plugin per-site when per-site pixel_id missing. Safer (no accidental cross-site fire) but high config cost. | |
| Hybrid — default-row required, per-site optional override | Default row MUST have pixel_id (boot-time PluginGuard check). Per-site row optional. | |

**User's choice:** Fall back to default-row value silently (Recommended)
**Notes:** Lowest operator-friction path. Matches single-default + per-site-override mental model. Captured as D-01.

### Q1.2 — MULT-05 cross-site test matrix shape

| Option | Description | Selected |
|--------|-------------|----------|
| Pest integration with hermetic SQLite + 2 fake Site rows | 2 cms_themes rows seeded; FakeAdapter::getSiteId returns 1 for Site-A, 2 for Site-B; UNIQUE NULL-distinct asserted in SQLite + parity callout to MySQL. | ✓ |
| MySQL-only integration test | Require real MySQL in CI for pre-8.0.13 NULL-in-UNIQUE divergence. | |

**User's choice:** Pest integration with hermetic SQLite + 2 fake Site rows (Recommended)
**Notes:** Captured as D-04.

---

## Area 2 — FailedEvents Replay execution model

### Q2.1 (initial) — Replay execution model

| Option | Description | Selected |
|--------|-------------|----------|
| Queued dispatch via SendCapiEvent::dispatch | Replay enqueues job; flash "Queued"; refresh page. | |
| Synchronous MetaClient call with immediate flash | Click Replay = direct MetaClient call inline; admin tab blocks 1–3s; immediate flash. | |
| Synchronous with timeout + fallback to queue | Try sync 5s, fall back to queue. Two code paths. | |

**User's choice (first pass):** Did not understand the difference; asked for plain explanation. Claude dropped caveman mode and explained how events land in FailedEvents (CAPI failures dead-lettered after 3 retries), how Replay works (admin manual recovery), and why synchronous is the better UX for a low-row-count admin table.

### Q2.1 (re-asked after explanation)

| Option | Description | Selected |
|--------|-------------|----------|
| Synchronous — click Replay, wait 1-3s, see result | Direct MetaClient call inside controller action; immediate success/error flash. | ✓ |
| Queued — click, flash 'Queued', refresh later | Dispatches the existing SendCapiEvent job to the queue. | |

**User's choice:** Synchronous — click Replay, wait 1-3s, see result (Recommended)
**Notes:** Captured as D-05.

### Q2.2 — Batch operations on FailedEvents list

| Option | Description | Selected |
|--------|-------------|----------|
| Per-row only — no batch toolbar | Each row has Replay button + CheckDedup link. Simpler controller. | |
| Batch Replay toolbar + per-row CheckDedup | October checkbox + batch action: 'Replay selected (N)'. | |
| Full batch — Replay + CheckDedup + Delete | All three as batch actions. Highest UX, most controller surface. | ✓ |

**User's choice:** Full batch — Replay + CheckDedup + Delete
**Notes:** Captured as D-07. Bulk operations stay synchronous (one Graph API call per row in a loop) — acceptable because dead-letter table size stays small.

### Q2.3 — CheckDedup response format

| Option | Description | Selected |
|--------|-------------|----------|
| JSON flash with summary string | Returns JSON; controller renders 'Dedup 87% · EMQ 8.2' in toolbar flash. | |
| Modal popup with full Graph API JSON response | Modal renders pretty-printed Graph API response. | |
| Inline column update — row shows latest dedup % | CheckDedup writes dedup_pct + emq columns onto FailedEvent row, list reloads. Schema change. | ✓ |

**User's choice:** Inline column update — row shows latest dedup %
**Notes:** Migration adds dedup_pct + emq + dedup_checked_at columns to failed_events. Captured as D-06.

---

## Area 3 — PSL bundling + refresh model

### Q3 (initial) — Ship model + stale handling + refresh

User responded "don't understand what it is, why cannot extract from domain url, don't understand" to all three sub-questions. Claude dropped caveman mode and explained:

- **What PSL is:** Mozilla-maintained text file listing every TLD where users can register subdomains. PHP has no built-in knowledge of multi-part TLDs like `.co.uk`, `.com.br`.
- **Why URL parsing isn't enough:** Naive `count('.')` gives wrong subdomain-index for multi-part TLDs (e.g. `example.co.uk` → naive 3, correct 1). PSL is the same data browsers use to draw cookie-domain boundaries.
- **Why bundle vs auto-fetch:** Marketplace install (`composer require`) must work offline; Mozilla CDN outage shouldn't kill cookie middleware; PSL changes ~monthly and bundled snapshot stays valid 6+ months.
- **For the user's sites** (`.no`/`.lv`/`.lt`, all single-TLD ccTLDs): naive counting would work for these specific hosts, but plugin ships for marketplace operators with arbitrary domains including multi-part TLDs and IDNs.

### Q3.1 (re-asked) — How PSL data lands on operator install

| Option | Description | Selected |
|--------|-------------|----------|
| Bundled snapshot, refresh on demand | Plugin ships `resources/data/public_suffix_list.dat`; works offline; operator runs `php artisan metapixel:refresh-psl` when desired. | ✓ |
| Bundled + weekly auto-refresh cron | Same bundle + Plugin::registerSchedule weekly. Requires reliable cron. | |
| Auto-fetch from publicsuffix.org on first request | No bundled file; download on first cookie write. Risky for firewalled hosts. | |

**User's choice:** Bundled snapshot, refresh on demand (Recommended)
**Notes:** Captured as D-09. README documents annual refresh + on-demand for new ccTLDs.

### Q3.2 (re-asked) — Stale PSL handling

| Option | Description | Selected |
|--------|-------------|----------|
| Keep working, log a warning | 180+ day snapshot still correctly handles existing domains; `Log::warning` once per process; cookies keep writing. | ✓ |
| Strict — refuse cookies if PSL older than N months | Force operator hygiene. Risk: silently kills tracking. | |

**User's choice:** Keep working, log a warning (Recommended)
**Notes:** Captured as D-10. 180-day threshold codified.

### Q3.3 (carried forward from initial set, planner-locked) — Refresh command behavior

Captured as D-11 (orchestrator default per recommendation since user did not select an alternative in the re-asked round): `metapixel:refresh-psl` fetches upstream, atomic-rename to `resources/data/`, wipes `storage/app/metapixel/psl/`. No composer post-install-cmd hook; no auto-schedule.

---

## Area 4 — Trusted-hosts UX + Settings tab structure

### Q4.1 — Trusted-hosts input shape

| Option | Description | Selected |
|--------|-------------|----------|
| Simple textarea, one host per line | Plain textarea; PSL computes subdomain-index automatically; operator never sees the integer. Mirrors theme_custom_event_names pattern. | ✓ |
| Repeater field: host + optional manual index override | Per-row index override for power users. Unnecessary if PSL works. | |

**User's choice:** Simple textarea, one host per line (Recommended)
**Notes:** Captured as D-13. Empty default — operator MUST populate before middleware writes cookies.

### Q4.2 — Validation when operator saves trusted_hosts

| Option | Description | Selected |
|--------|-------------|----------|
| Loose: accept any line that looks like a host, validate at request time | beforeSave drops whitespace + basic charset check; PSL unknown TLD = middleware NO-OP at request time. | |
| Strict: PSL-parse on save, reject hosts where PSL can't resolve TLD | beforeSave runs each host through HostIndexResolver; rejects with Flash::error listing rejected hosts. | ✓ |

**User's choice:** Strict: PSL-parse on save, reject hosts where PSL can't resolve TLD
**Notes:** Captured as D-14. Operator gets immediate feedback on typos and unknown TLDs. Operator who legitimately needs a new ccTLD runs `metapixel:refresh-psl` first.

### Q4.3 — Settings tab structure

| Option | Description | Selected |
|--------|-------------|----------|
| Pixel & CAPI / Hosts & Cookies / Theme Tracking / Advanced | 4 tabs grouped by concern; each maps to README section. | ✓ |
| Keep v1.x tabs: Tracking / Compliance / Advanced | Reuse v1.x grouping. "Compliance" vague for marketplace audience. | |
| Flat — no tabs, single scroll | Simplest; borderline with ~12 fields. | |

**User's choice:** Pixel & CAPI / Hosts & Cookies / Theme Tracking / Advanced (Recommended)
**Notes:** Captured as D-15 with field-to-tab mapping.

---

## Cross-cutting meta-decision

User added to area selection: "No legacy code from v1, new fresh best practices approach."

**Effect:** Reaffirms plugin `CLAUDE.md` "Build philosophy" lock — v2.0 adapters re-derive logic following modern October 4 + Laravel 12 + Lovata.Toolbox idioms. Reuse v1.x DECISIONS, not v1.x code. Specifically: HOST_INDEX_MAP constant deleted (replaced by HostIndexResolver + PSL); EnsureFbpFbcCookies middleware body rewritten fresh; CR-02/CR-03/kill-switch semantics carried forward as decisions, not source. Captured as D-20.

---

## Claude's Discretion

The following implementation details were intentionally left to the planner per CONTEXT.md "Claude's Discretion" section:

- Migration filename ordinal numbers (`updates/2026_05_xx_*.php`) — planner picks based on existing `updates/version.yaml`.
- Backend `_list_toolbar.php` button icon classes (`oc-icon-bolt` / `oc-icon-shield` / `oc-icon-trash-o`).
- PHPStan disallowed-calls rule wording for the D-02 ban on direct `Settings::get('pixel_id'|'capi_access_token')`.
- PSL `Pdp\Rules` memoization shape (request-scoped vs Laravel cache repository).
- Lang key naming on semantic group collisions.

## Deferred Ideas

- Per-row index override Repeater for trusted_hosts (v2.1 if support ticket surfaces).
- PSL auto-refresh weekly cron via Plugin::registerSchedule (revisit on marketplace survey signal).
- Synchronous-with-timeout-to-queue fallback for Replay (deferred unless Meta latency > 5s consistently).
- RU translation file (operator self-services unless marketplace data shows >20% RU base).
- FailedEvents dashboard widget (PSL age + dead-letter count + last refresh date) — Phase 5 polish.
- Settings export/import as YAML for cross-site config migration — Phase 5+.
- `metapixel:refresh-psl` as composer post-install-cmd hook (rejected per D-11 — breaks firewalled installs).
