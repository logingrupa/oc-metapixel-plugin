# Phase 5: Documentation + marketplace launch — Research

**Researched:** 2026-05-21
**Domain:** Marketplace launch (docs, manifest, screenshots, security sweep, public-flip, annotated tag) + theme legacy-pixel strip + live CAPI smoke
**Confidence:** HIGH (locked CONTEXT.md + verifiable in-repo artifacts; minor MEDIUM on external Meta/October docs)

## Summary

Phase 5 is a launch phase, not a build phase. The plugin code is already complete — Phase 2 contracts, Phase 3 ShopaholicAdapter + ThemeActionAdapter, Phase 4 Multisite + TrustedHosts + FailedEvents are all shipped and tested. What ships in Phase 5 is the *surface*: a single-page README that gets a buyer to a verified CAPI event in under 10 minutes, a `docs/CUSTOM-ADAPTERS.md` that gets a third-party developer to a registered custom adapter, the marketplace assets (`plugin.yaml` lang-key driven, 5 PNG screenshots from a live smoke, `CHANGELOG.md` as a fresh v2.0.0 entry), an annotated `v2.0.0` tag, and the atomic GitHub repo-visibility flip from private to public.

Two pre-launch blockers were absorbed into the phase per D-01: (1) stripping the legacy v1.x JS pixel from `themes/logingrupa-naisstore/` and replacing it with the new `PixelHead` + `EventPixel` components under gated UAT checkpoints; (2) running a live Purchase + PageView + ViewContent smoke on `new.nailscosmetics.lv` with `test_event_code` set, producing the screenshots and validated step sequence that feed the README walkthrough. Everything else (MallAdapter, AddToCart, docker dry-run, Packagist, PNG icon for marketplace listing, video screencast) is explicitly deferred.

**Primary recommendation:** Sequence the 14 plans exactly as D-01 specifies — legacy-strip is irreversible until UAT confirms zero events, the live smoke produces the inputs for both README and screenshots, and the security sweep MUST run BEFORE the public-flip is atomic. Within that locked sequence the planner has freedom on wave-packing (e.g., merge 05-04 PixelHead-wire with 05-05 PageView-UAT into a single plan with internal checkpoint) and on the keywords/license-field discretion items.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Pre-launch blockers (scope expansion):**

- **D-01:** Phase 5 absorbs legacy-strip + live-smoke plans BEFORE docs/manifest/tag plans. Locked sequence: 05-01 legacy JS inventory → 05-02 legacy JS strip → 05-03 zero-events UAT gate → 05-04 PixelHead layout wire → 05-05 PageView UAT gate → 05-06 EventPixel per-event wire → 05-07 event_id-sync UAT gate → 05-08 live smoke (Purchase + PageView + ViewContent) → 05-09 README → 05-10 docs/CUSTOM-ADAPTERS.md → 05-11 v1.x reference strip across `.planning/` + lang + Plugin.php → 05-12 plugin.yaml + CHANGELOG.md + screenshots → 05-13 pre-flip security sweep → 05-14 repo flip public + v2.0.0 annotated tag.

**Legacy JS pixel strip + cutover:**

- **D-02:** Inventory plan greps `themes/logingrupa-naisstore/` for ALL three legacy emission patterns simultaneously: inline `<script>fbq(...)` in layouts/pages/partials; bundled pixel logic in webpack entry `common.js`; Twig `{% set %}` dataLayer + dispatcher partial.
- **D-03:** Cutover is gated two-step (operator-confirmed between plans). Strip first → operator verifies zero pixel events fire on every page. Only after explicit operator confirmation does PixelHead land. Then EventPixel per event lands and operator verifies event_id-sync. Three named UAT gates with measurable pass/fail criteria per-plan.
- **D-04:** Replacement = `PixelHead` in layout (head-tag base pixel) + `EventPixel` per event-emitting page (server-confirmed browser pixel). Server-side CAPI handled by ShopaholicAdapter (Phase 3). Browser + server stay event_id-synced via dedup window (±10s).
- **D-05:** Verification toolchain for each UAT gate combines three sources: (1) Meta Pixel Helper Chrome extension (fastest manual signal), (2) Meta Test Events live view in Events Manager (highest signal — confirms dedup), (3) `logingrupa_metapixel_event_log` DB table tail (server-side CAPI confirmation). DevTools Network panel skipped — Pixel Helper covers the same surface.

**Live end-to-end smoke:**

- **D-06:** Target environment = `new.nailscosmetics.lv` (Laravel Forge-hosted Production-shaped staging install with real Lovata stack + real orders + Settings `test_event_code` set). NOT docker. NOT prod.nailscosmetics.lv.
- **D-07:** Smoke event set = Purchase (primary, DOCS-01 critical path) + PageView (head-tag sanity) + ViewContent (content_ids format `SKU-{product_id}[-{offer_id}]`). AddToCart + Theme Twig API custom event deferred to post-launch.
- **D-08:** Smoke results captured as `.planning/phases/05-documentation-marketplace-launch/05-SMOKE-LOG.md` — markdown audit trail with timestamp, env, exact button clicks, EventLog row count, Meta Test Events screenshot count, fbp/fbc cookie values, event_id sample, fail-pass per step. README walkthrough copies the validated step sequence verbatim.
- **D-09:** Smoke-found bugs route to `/gsd-debug` sessions (one session per bug). Fix lands in a Phase 5 fix-plan or backport plan as the debug session determines. NOT inline-fix-and-continue.

**Docs (DOCS-01..03):**

- **D-10:** README.md = single-page linear walkthrough (Install → Configure → Shopaholic → Theme → FailedEvents → Troubleshoot). Marketplace pages render the README directly. Long-form depth lives only in `docs/CUSTOM-ADAPTERS.md`.
- **D-11:** README covers BOTH Shopaholic Purchase path AND Theme Twig API path as twin walkthroughs. Matches MKT-05 CI matrix (Run A full-Lovata + Run B minimal-install).
- **D-12:** Meta credential acquisition documented as numbered steps in **plain text only, no screenshots**. Survives Meta UI redesigns.
- **D-13:** Troubleshooting runbook shape = markdown table mapping `symptom → Log::* line → fix`.
- **D-14:** `docs/CUSTOM-ADAPTERS.md` example tracks `OFFLINE\Mall\Models\Order`. Code lives **only inline in the doc** as code blocks (~50 LOC adapter + ~30 LOC value resolver). NOT in `plugins/logingrupa/metapixel/classes/adapter/mall/`. No composer require on OFFLINE\Mall.
- **D-15:** `docs/CUSTOM-ADAPTERS.md` shows all 3 `Event::fire` hooks with concrete third-party use cases: `before_dispatch` → inject `test_event_code` for staging; `after_dispatch` → mirror EventLog to analytics dashboard; `dead_letter` → Slack alert. Copy-paste-able.
- **D-16:** `docs/CUSTOM-ADAPTERS.md` includes dedicated `## Testing your adapter` section documenting how to extend `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` + supply `makeAdapter()` + `makeSubject()`. Locks the v2.x adapter marketplace contract (10 invariants).

**Marketplace assets (MKT-01..05):**

- **D-17:** All 5 MKT-03 screenshots come from the live smoke on `new.nailscosmetics.lv` — Settings UI, FailedEvents list (after intentionally bad-token call), Replay flow success, CheckDedup with Meta API response, theme Twig API on a real product page.
- **D-18:** Redaction strategy = redact-friendly Settings record on staging: dummy row with placeholder values (`pixel_id = 000000000000000`, `access_token = REDACTED_FOR_DEMO_DO_NOT_USE`).
- **D-19:** Screenshots live at `docs/screenshots/01-settings.png ... 05-twig-api.png`. README references them by relative path. plugin.yaml does not embed screenshot refs.
- **D-20:** Plugin icon = keep existing `icon-bullseye` Font Awesome reference in `plugin.yaml`. October backend renders the FA glyph natively. Zero binary asset commitment. MKT-03 PNG-icon line satisfied if marketplace listing later requires PNG — punt to that point.

**Release-tag flow + public flip:**

- **D-21:** v2.0.0 ships as **annotated tag from master HEAD** at the smoke-validated commit. `git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"`. Push tag after all Phase 5 plans green.
- **D-22:** CHANGELOG.md = **fresh v2.0.0 initial release entry only**. ZERO v1.x diff text.
- **D-23:** Plan 05-11 strips ALL v1.x references from `.planning/` docs, `lang/en/lang.php`, `lang/lv/lang.php`, `Plugin.php` docblocks, `ROADMAP.md` (MKT-04 wording currently says "v1.1.1 + legacy/v1.1.1 branch preserved" — rewrite to "v2.0.0 annotated tag from master"), `REQUIREMENTS.md` (MKT-04 same), any class-level PHPDoc citing Phase N or legacy semantics.
- **D-24:** `legacy/v1.1.1` branch + `v1.1.1` tag stay **local-only** as personal archive. NEVER pushed to origin. Confirmed via `git ls-remote --tags origin 'v1*'` returns empty.
- **D-25:** Publishing flow = repo flipped public at v2.0.0 launch. Buyer install path = composer VCS without auth. NOT Packagist (defer to post-launch).
- **D-26:** Pre-flip-public security sweep (plan 05-13) scope = (1) secrets in git history; (2) internal hostnames + IPs in `.planning/` docs. Theme PII NOT in scope. Application logs NOT in scope.
- **D-27:** `.planning/` directory ships **in the public repo** (after sweep). Shows GSD workflow rigor to marketplace audience + helps collaborators.

### Claude's Discretion

- Plan-level sequencing within the 14-plan Phase 5 (waves, parallelism). Locked sequence is dependency order; planner may merge adjacent plans (e.g., 05-04 + 05-05 as one plan with internal checkpoint) if waves stay clean.
- Exact `composer.json` `keywords` array for marketplace discoverability — planner picks from `meta-pixel`, `conversions-api`, `capi`, `october-cms`, `shopaholic`, `tracking`, `analytics`.
- README ordering of FailedEvents section (before or after Troubleshoot).
- CHANGELOG date format (YYYY-MM-DD vs ISO 8601 timestamp).
- Exact wording of UAT gate pass/fail criteria — planner drafts; operator reviews.
- `composer.json` `license` field re-evaluation (currently `"proprietary"`; public flip implies re-evaluation per Deferred Ideas).

### Deferred Ideas (OUT OF SCOPE)

- **MallAdapter as production code** — stays v2.1 backlog (MALL-01).
- **AddToCart + Theme Twig API smoke events** — D-07 explicitly defers.
- **Video screencast / Loom buyer onboarding asset** — declined in favor of D-08 markdown smoke log.
- **PNG plugin icon for marketplace listing** — D-20 keeps FA `icon-bullseye`. If marketplace listing later requires PNG, add as v2.0.1 patch.
- **Long-lived `v2.x` maintenance branch** — D-21 punts.
- **Packagist publication** — D-25 punts.
- **Docker dry-run as second validation env** — D-06 discarded.
- **DevTools Network panel as a UAT verifier** — D-05 skipped.
- **`composer.json` license re-evaluation** — punt to planner discretion in plan 05-14.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DOCS-01 | README walks buyer from `composer require` → Settings → verified CAPI event in <10 min. Timed dry-run = launch acceptance gate. | `## README single-page convention`, `## Validation Architecture` (10-min timer), `## Code Examples` (README skeleton) |
| DOCS-02 | README includes: Settings walkthrough, Shopaholic+Theme adapter setup, Pixel+CAPI token acquisition with Meta UI screenshots, .env reference, troubleshooting runbook keyed to `Log::*` context arrays, multi-site routing. | `## Code Examples` (Settings walkthrough anchors to existing `lang/en/lang.php` field labels); `## Troubleshooting runbook shape` (D-13 markdown table) |
| DOCS-03 | `docs/CUSTOM-ADAPTERS.md` with working AcmeCartAdapter+AcmeCartValueResolver example. Documents AdapterRegistry::register, $require declaration, 3 Event::fire hooks. | `## CUSTOM-ADAPTERS.md anchors` lists the 10 contract-test invariants verbatim, the 3 hook signatures verbatim from SendCapiEvent docblock; D-14 OFFLINE\Mall inline example; D-15 hook use cases |
| MKT-01 | Composer package published as `logingrupa/oc-metapixel-plugin` on private GitHub repo. Composer install on clean OctoberCMS 4.x completes without errors. | `composer.json` already correct ("name": "logingrupa/oc-metapixel-plugin"); D-25 VCS-install path verified via Composer docs |
| MKT-02 | plugin.yaml: generic name, generic description, generic icon. Author Logingrupa. Homepage GitHub repo. | Existing `plugin.yaml` already lang-key-driven; verified `icon: icon-bullseye` accepted by October backend |
| MKT-03 | Marketplace assets: plugin icon (PNG), 5 screenshots, CHANGELOG.md documenting v2.0.0. | D-17 screenshots from smoke; D-19 path; D-20 PNG icon line punted; D-22 CHANGELOG fresh v2.0.0 |
| MKT-04 | Plugin git tag `v2.0.0` annotated. Pushed to remote. (Wording in ROADMAP currently says "v1.1.1 + legacy/v1.1.1 branch preserved" — D-23 strips this.) | D-21 annotated tag flow; D-24 local-only legacy archive; `## Annotated tag flow` |
| MKT-05 | `composer qa` exits 0 on clean OctoberCMS + Shopaholic install AND on clean OctoberCMS + no-cart install. Both CI matrix runs green. | CI matrix already shipped Phase 1 TOOL-09; `## Validation Architecture` covers exit-0 gate |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Legacy `<script>fbq(...)>` strip | Theme (Twig + JS) | — | All legacy emission lives in `themes/logingrupa-naisstore/` — partials, layouts, and webpack source JS. Plugin core is untouched. |
| `PixelHead` head-tag base pixel | Theme layout (Twig include) | Plugin component (PHP) | Component lives in plugin; placement (head-tag) is per-layout in theme. Operator inserts `{% component 'pixelHead' %}` in `<head>`. |
| `EventPixel` server-confirmed render | Theme page (Twig include) | Plugin component (PHP) | Component lives in plugin; placement on event-emitting pages (thank-you, PDP) is per-page in theme. |
| CAPI dispatch (server-side) | Plugin queue (`SendCapiEvent`) | Adapter (Shopaholic / Theme) | Already shipped Phase 2-3. Phase 5 only consumes. |
| Settings UI walkthrough | OctoberCMS backend | Plugin Settings model | Phase 4 shipped Multisite Settings; Phase 5 README documents the operator click path. |
| FailedEvents replay walkthrough | OctoberCMS backend | Plugin controller | Phase 4 shipped Controller; Phase 5 README documents Replay + CheckDedup buttons. |
| `composer require` install path | Buyer machine (Composer) | GitHub VCS repo (public after flip) | D-25 VCS install, no auth after flip. |
| `v2.0.0` annotated tag | Git (local repo) | GitHub origin (remote) | `git tag -a` annotates locally; `git push origin v2.0.0` publishes. |
| Public-flip atomic action | GitHub Settings UI | — | One click. Irreversible-in-practice (clones may persist; private re-flip does not retract them). |
| README single-page assembly | Repo root (`README.md`) | `docs/screenshots/*.png` | Marketplace renders README directly. Long-form depth lives in `docs/CUSTOM-ADAPTERS.md`. |
| Pre-flip security sweep | Git history + `.planning/` text | — | grep-and-rewrite operation; `git filter-repo` only if a hit surfaces. |

## Standard Stack

### Core (no new dependencies — Phase 5 ships docs + assets, not code)

Phase 5 does NOT add a single composer require. Every dependency the plugin needs is already in `composer.json`:
- `guzzlehttp/guzzle ^7.8` [VERIFIED: in-repo composer.json] — MetaClient HTTP transport (Phase 2)
- `jeremykendall/php-domain-parser ^6.4` [VERIFIED: in-repo composer.json] — HostIndexResolver PSL parsing (Phase 4)
- `lovata/toolbox-plugin ^2.2` [VERIFIED: in-repo composer.json] — CommonSettings + Hungarian convention backbone (Phases 1-4)
- `october/system ^4.0` [VERIFIED: in-repo composer.json] — October Rain framework

### Supporting (tooling already shipped Phase 1)

| Tool | Purpose | Phase 5 use |
|------|---------|-------------|
| `pestphp/pest ^4.1` [VERIFIED: composer.json] | Test runner | `composer qa` gate (MKT-05) |
| `phpstan/phpstan` + larastan ^3.0 [VERIFIED] | Static analysis | `composer qa` gate |
| `laravel/pint ^1.26` [VERIFIED] | Code style | `composer qa` gate |
| `phpmd/phpmd ^2.15` [VERIFIED] | Mess detection | `composer qa` gate |
| `shipmonk/composer-dependency-analyser ^1.8` [VERIFIED] | Lovata import boundary | `composer deps` |

### Phase 5 external-tool requirements

| Tool | Phase 5 use | Available locally? | Fallback |
|------|-------------|--------------------|----------|
| `git` (annotated tags + history rewrite) | Tag `v2.0.0`; history rewrite if sweep finds secrets | ✓ (system) | — |
| `gh` (GitHub CLI) | Visibility flip via `gh repo edit --visibility public` (or browser UI) | ✓ v2.4.0 [VERIFIED: `gh --version`] | Browser UI |
| `git-filter-repo` | History rewrite IF security sweep finds real secrets | ✗ [VERIFIED: `command -v` returned not installed] | `pip install --user git-filter-repo` on demand; OR `git filter-branch` (legacy, slow, deprecated) — prefer install |
| `slopcheck` (package legitimacy gate) | Verify any new packages | ✗ [VERIFIED: not installed] | N/A — Phase 5 installs zero new packages, gate is informational only |
| Meta Pixel Helper (Chrome extension) | UAT gates 1-3 (D-05) | Operator-side | — |
| Meta Events Manager Test Events live view | UAT gates + smoke verification | Operator-side (web app) | — |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| GitHub VCS install (D-25) | Packagist publication | Packagist is friendlier discovery, but requires public-Packagist account + ongoing maintenance burden. D-25 punts until buyer demand surfaces. |
| FontAwesome `icon-bullseye` (D-20) | PNG icon at `assets/images/icon.png` | October backend Plugin Manager renders FA natively [CITED: docs.octobercms.com/4.x/extend/system/plugins.html]; marketplace LISTING page may require PNG 64×64 [CITED: octobercms.com/help/guidelines/quality]. D-20 punts PNG to v2.0.1 if listing requires it. |
| `git filter-repo` (history rewrite) | `git filter-branch` (legacy) | filter-repo is the modern replacement; filter-branch is deprecated per git docs. Only run EITHER if security sweep finds real secrets — current scan shows only test/dummy values. |
| Annotated tag (D-21) | Release branch (`release/v2.0.0`) | Annotated tag is simpler + matches v1.1.1 precedent. Maintenance branch (`v2.x`) cut later only when v2.0.x patch surfaces. |

**Installation:** No new composer require in Phase 5. The plugin's own `composer.json` is already shipping-ready.

**Version verification:** Verified existing packages in `composer.json` against in-repo composer.lock; no version drift detected. No new packages introduced — slopcheck audit is a no-op (see Package Legitimacy Audit).

## Package Legitimacy Audit

> **Result: NO NEW PACKAGES INSTALLED IN PHASE 5.** The plugin already ships all required composer dependencies (added in Phases 1-4). Phase 5 deliverables are docs (`README.md`, `docs/CUSTOM-ADAPTERS.md`), assets (`docs/screenshots/*.png`, `CHANGELOG.md`), manifest review (`plugin.yaml`, `composer.json`), live smoke, security sweep, public-flip, and annotated tag — none of these introduce a composer package.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| *(none)* | — | — | — | — | N/A | No new packages |

**Packages removed due to slopcheck [SLOP] verdict:** none — Phase 5 installs zero packages
**Packages flagged as suspicious [SUS]:** none

*slopcheck was not available at research time (`pip install slopcheck` not executed because no packages to audit). Phase 5 installs zero composer packages by design; no slopcheck verdict needed.*

## Architecture Patterns

### System Architecture Diagram — Phase 5 deliverable flow

```
                    ┌──────────────────────────────────────────┐
                    │   THEME LEGACY STRIP (D-02..D-04)        │
                    │                                          │
[grep -rE 'fbq\|fbevents.js']                                  │
   ↓                                                            │
[Inventory: 9 files in themes/logingrupa-naisstore/]            │
   ↓                                                            │
[Strip (05-02)]──────────────[UAT Gate 1: zero events]────┐    │
                                                            ↓    │
                                                  [Operator: ✓]  │
                                                            ↓    │
                                   ┌────────────────────────┘    │
                                   ↓                              │
[Plugin PixelHead in layout <head> (05-04)]                       │
   ↓                                                              │
[UAT Gate 2: PageView fires once, dedup confirmed]───────[Operator: ✓]
   ↓
[Plugin EventPixel on order-complete + PDP (05-06)]
   ↓
[UAT Gate 3: event_id round-trip verified]─────────────[Operator: ✓]
   ↓
                    ┌──────────────────────────────────────────┐
                    │   LIVE SMOKE (D-06..D-09)                │
                    │                                          │
[new.nailscosmetics.lv Forge install + test_event_code set]   │
   ↓                                                            │
[Place real order via UI]                                       │
   ↓                                                            │
[Verify: 3 channels × 3 events = 9 datapoints]                  │
   ├─→ Meta Pixel Helper (browser detection)                    │
   ├─→ Meta Test Events live view (server+browser dedup)        │
   └─→ logingrupa_metapixel_event_log table tail                │
   ↓                                                            │
[Capture 5 screenshots → docs/screenshots/]                     │
   ↓                                                            │
[Write 05-SMOKE-LOG.md]                                         │
                                                                 │
                    ┌──────────────────────────────────────────┐
                    │   DOCS + MANIFEST (D-10..D-22)           │
                    │                                          │
[README.md (05-09) ← copies smoke step sequence]                │
   ↓                                                            │
[docs/CUSTOM-ADAPTERS.md (05-10) ← anchors to EventSubjectAdapterContractTestCase 10 invariants]
   ↓                                                            │
[Strip v1.x refs from .planning/ + lang + ROADMAP + REQUIREMENTS (05-11)]
   ↓                                                            │
[plugin.yaml + CHANGELOG.md + 5 PNG screenshots (05-12)]        │
                                                                 │
                    ┌──────────────────────────────────────────┐
                    │   SECURITY SWEEP (D-26)                  │
                    │                                          │
[git log -p | grep -iE 'pixel_id|access_token']─→ verified no real secrets
   ↓                                                            │
[grep -r 'new.nailscosmetics.lv\|forge.laravel.com\|10\.\|192\.168\.' .planning/]
   ↓                                                            │
[Redact/generalize → commit cleanup]                            │
                                                                 │
                    ┌──────────────────────────────────────────┐
                    │   PUBLIC FLIP + v2.0.0 TAG (D-21, D-25)  │
                    │                                          │
[gh repo edit --visibility public]                              │
   ↓                                                            │
[git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"]
   ↓                                                            │
[git push origin v2.0.0]                                        │
   ↓                                                            │
[Verify: composer require from unauthenticated clone works]     │
   ↓                                                            │
[LAUNCH COMPLETE]                                               │
```

### Recommended Repository Structure (after Phase 5 launch)

```
plugins/logingrupa/metapixel/
├── README.md                                       # NEW (05-09) — < 10 min install guide
├── CHANGELOG.md                                    # NEW (05-12) — fresh v2.0.0 entry
├── docs/
│   ├── CUSTOM-ADAPTERS.md                          # NEW (05-10) — third-party authoring guide
│   └── screenshots/
│       ├── 01-settings.png                         # NEW (05-12) — from live smoke
│       ├── 02-failed-events-list.png               # NEW
│       ├── 03-replay-flow.png                      # NEW
│       ├── 04-check-dedup.png                      # NEW
│       └── 05-twig-api.png                         # NEW
├── plugin.yaml                                     # UNCHANGED — already lang-key-driven; icon-bullseye OK
├── composer.json                                   # MAY CHANGE — keywords array (discretion); license re-eval
├── Plugin.php                                      # MAY CHANGE — docblock "Phase N" decorators stripped (D-23)
├── classes/                                        # UNCHANGED
├── components/                                     # UNCHANGED
├── controllers/                                    # UNCHANGED
├── lang/{en,lv}/lang.php                           # MAY CHANGE — any v1.x/operator refs stripped (D-23)
├── models/                                         # UNCHANGED
├── middleware/                                     # UNCHANGED
├── console/                                        # UNCHANGED
├── updates/                                        # UNCHANGED
├── resources/                                      # UNCHANGED
├── tests/                                          # UNCHANGED
└── .planning/                                      # SHIPS PUBLIC (D-27) — after sweep
    ├── ROADMAP.md                                  # CHANGES — MKT-04 wording rewritten (D-23)
    ├── REQUIREMENTS.md                             # CHANGES — MKT-04 wording rewritten (D-23)
    ├── STATE.md                                    # CHANGES — operator-specific notes redacted
    ├── PROJECT.md                                  # UNCHANGED (or minor sweep)
    └── phases/05-documentation-marketplace-launch/
        ├── 05-CONTEXT.md
        ├── 05-RESEARCH.md                          # THIS FILE
        ├── 05-SMOKE-LOG.md                         # NEW (05-08)
        ├── 05-PLAN.md                              # NEW (planner)
        └── ...
```

### Pattern 1: Gated UAT cutover (D-03, D-05)

**What:** A multi-step cutover where each step has explicit operator confirmation before the next step lands.

**When to use:** When the previous emission system and the new emission system can coexist for a brief verification window, but cannot both fire — would double-count events.

**Pattern:**

```
1. Strip legacy
2. UAT Gate: Operator verifies NO events fire (clean baseline)
3. Add new emission (PixelHead head-tag only — base PageView)
4. UAT Gate: Operator verifies PageView fires once per page-load, dedup'd between server + browser
5. Add new per-event emission (EventPixel on order-complete + PDP)
6. UAT Gate: Operator verifies event_id round-trip — same event_id appears in Pixel Helper, Test Events live view, AND EventLog DB row
```

**UAT gate measurable criteria template (planner refines exact wording):**

| Gate | Pass condition | Tooling |
|------|----------------|---------|
| Zero-events (after strip) | 0 fbq() calls on /, /catalog, /product, /checkout, /order-complete across all 4 layouts (`main.htm`, `content.htm`, `light.htm`, `catalog_default.htm`) | Meta Pixel Helper Chrome extension; visible page-load count = 0 |
| PageView-only (after PixelHead) | Exactly 1 PageView event per page-load. Test Events live view shows "Browser" + "Server" sources for same event_id. EventLog has 1 row per page-load with `event_name='PageView'`, `channel='capi'`. | Pixel Helper (1 event); Test Events ("Deduplicated"); `SELECT count(*) FROM logingrupa_metapixel_event_log WHERE event_name='PageView' AND created_at >= NOW() - INTERVAL 1 MINUTE` |
| event_id round-trip (after EventPixel) | Place test order. Pixel Helper shows Purchase event with `eventID`. Test Events shows same `event_id`, "Deduplicated" label. EventLog has 2 rows: `channel='capi'` + `channel='pixel'` with identical `event_id`. | Three-source convergence on a single UUID v4 string |

### Pattern 2: README single-page convention (D-10, D-11)

**What:** Lovata and most October CMS marketplace plugins ship a single linear `README.md` rendered directly by the marketplace listing page. No multi-page docs site; no Read-the-Docs.

**When to use:** Any October CMS marketplace plugin where install + configure + verify fits in <10 min.

**Section ordering (locked by D-10):**

1. **Heading + one-line description** — "Meta Pixel + Conversions API for OctoberCMS"
2. **Install** — `composer require logingrupa/oc-metapixel-plugin` + `php artisan october:up`
3. **Configure** — Backend → Settings → Marketing → Meta Pixel + CAPI. Per-field walkthrough anchored to `lang/en/lang.php` field labels (already shipped).
4. **Acquire Meta credentials** — Numbered plain-text steps (D-12, no screenshots):
   1. Business Manager → Data Sources → Pixel → Settings → copy Pixel ID
   2. Same screen → Generate Access Token → copy
   3. Same screen → Test Events → copy Test Event Code (optional; routes to Test Events live view)
5. **Shopaholic walkthrough (D-11)** — operator with Lovata Shopaholic installed. ShopaholicOrderAdapter registers automatically when `Lovata.OrdersShopaholic` is enabled (verified `Plugin::boot` line 75-81). Status flip → Purchase event dispatch. EventPixel placement on `order-complete.htm` for browser-side fbq.
6. **Theme walkthrough (D-11)** — operator on a Lovata-free OctoberCMS install. PixelHead in layout `<head>`. EventPixel on event-emitting pages. Theme Twig API: `{% do this.metapixel.pushEvent({name: 'ViewContent', ...}) %}` — verified live in `Plugin.php` line 83-89 + ThemeEventCollector singleton.
7. **FailedEvents UI** — Backend → Settings → Marketing → Failed events. Replay button + CheckDedup button. (Phase 4 shipped.)
8. **Troubleshooting** — markdown table (D-13). See `## Troubleshooting runbook shape` below.
9. **Multi-site routing** — top-bar site picker scopes Settings reads/writes. `$propagatable = []` locks per-site credentials.
10. **CHANGELOG link + License** — links to `CHANGELOG.md` (D-22); license per discretion item.

**Reference structure** (confirmed shape via in-repo Lovata samples):
`/home/forge/nailscosmetics.lv/plugins/lovata/subscriptionsshopaholic/README.md` [VERIFIED: in-repo read]: Heading → Overview → Installation (Artisan + Composer) → Configuration → License — but no Troubleshooting section. Our README adds Troubleshooting (D-13) because our plugin has DOCS-02 mandate. Pure Lovata convention is leaner; we extend for clarity.

### Pattern 3: Troubleshooting runbook shape (D-13)

**What:** A markdown table mapping `visible symptom → grep-able Log::* line → operator action`. Three columns enable an operator with zero plugin knowledge to triage from a single error report.

**Template (planner fills concrete rows from Plugin code Log::* sites):**

```markdown
| Symptom | Log::* signature (grep your `storage/logs/laravel.log`) | Fix |
|---------|-----------------------------|-----|
| FailedEvents pile up, no events reaching Meta | `metapixel: adapter rehydrate failed — dead-lettered` | Worker process restarted with stale queue — clear `failed_events` table and re-dispatch; verify `php artisan queue:work` is running |
| No EventLog row written after Order paid | `metapixel: EventLogWriter rejected subject_id <= 0` | Order has no ID — Lovata model save mis-ordered; check OrderProcessor settings |
| Pixel fires but no Test Events confirmation | (none — Pixel Helper sees event but server CAPI never reached Meta) | `MissingPixelConfigException` or `MissingCapiTokenException` thrown silently; check Settings → top-bar site picker scope; verify `pixel_id` non-empty per-site |
| `_fbp`/`_fbc` cookies not set | `metapixel: untrusted host — cookie skipped` | Add host to Settings → Trusted Hosts (one per line) |
| Invalid fbclid in URL | `metapixel: fbclid rejected — invalid charset` | No action — fail-safe path; `_fbc` cookie skipped, event still fires without it |
| Purchase event fires on every status change | `metapixel: Purchase already logged for order N` (NOT seen — should be seen) | Paid status code Settings mismatch — verify `paid_status_code` matches your Lovata Status::code |
```

**Confirm symptoms map to real Log::* sites:** `grep -rn "Log::warning\|Log::critical\|Log::error" /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/` will enumerate every actual log line. Planner does this enumeration during 05-09 README plan; troubleshooting table rows must each cite a real log line from the source.

### Pattern 4: CUSTOM-ADAPTERS.md authoring guide (D-14..D-16)

**Section ordering:**

1. **Overview** — what an adapter is, when you'd write one. Anchor to `## Extensibility contract` in plugin `CLAUDE.md`.
2. **The contract: `EventSubjectAdapter` + `ValueResolver`** — interface signatures verbatim from `classes/adapter/EventSubjectAdapter.php` + `ValueResolver.php`. Each method documented in one paragraph.
3. **Inline example: AcmeCartAdapter** — D-14 example uses `OFFLINE\Mall\Models\Order` (real Mall plugin model — buyer can copy and adapt). Code lives ONLY in the doc as code blocks. ~50 LOC adapter + ~30 LOC value resolver. No `classes/adapter/mall/` directory.
4. **Register from your Plugin::boot()**:
   ```php
   public function boot(): void {
       AdapterRegistry::instance()->register(MyCart::class, MyCartAdapter::class);
   }
   public $require = ['Logingrupa.Metapixel'];
   ```
5. **Trigger dispatch** — `SendCapiEvent::dispatch('Purchase', $arPayload, $obSubject, MyCartAdapter::class)`.
6. **Hook patterns (D-15 — all 3 hooks, copy-pasteable):**
   - `before_dispatch` example: inject `test_event_code` from app env for staging environments
   - `after_dispatch` example: mirror EventLog to analytics dashboard via custom listener
   - `dead_letter` example: post Slack webhook on permanent CAPI failure
7. **Testing your adapter (D-16)** — extend `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase`; implement `makeAdapter()` + `makeSubject()`; the 10 invariants enforce the marketplace contract.
8. **Anti-patterns** — reasons NOT to extend `Component::extend(PixelHead::class)` (extensibility-contract rank 6 — last resort; use `metapixel.event.*` hooks instead).

### Anti-Patterns to Avoid

- **README that links out to multi-page docs site** — defeats DOCS-01 timed-dry-run (buyer leaves README, gets lost). Lock single-page.
- **Screenshots inlined in README of buyer's Meta Business Manager** — D-12 explicitly bans. Meta UI redesigns ~yearly; screenshots go stale. Numbered text steps survive.
- **Inline-fix-and-continue when smoke surfaces a bug** — D-09 bans. Open `/gsd-debug` session, name the bug, route to a fix-plan or backport. Keeps the smoke log clean as audit trail.
- **Bundling MallAdapter as production code** — D-14 bans. Inline doc example only. v2.1 backlog (MALL-01).
- **Pushing `legacy/v1.1.1` branch to origin during sweep** — D-24 lock. `git ls-remote --tags origin 'v1*'` must return empty after the sweep.
- **Amending CHANGELOG with v1.x diff text** — D-22 bans. Fresh entry only. Treat repo as net-new public artifact.
- **PNG icon at `assets/images/icon.png` "just to be safe"** — D-20 defers. October backend [CITED: docs.octobercms.com/4.x/extend/system/plugins.html] renders `icon-bullseye` FA glyph natively. Marketplace listing may later require PNG [CITED: octobercms.com/help/guidelines/quality § Icon Format: 64×64 transparent PNG] — punt to v2.0.1 patch if surfaces.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Pretty changelog page | Custom Markdown→HTML compiler, custom template | Keep-a-Changelog 1.1.0 format [CITED: keepachangelog.com/en/1.1.0/] — single section `## [2.0.0] - YYYY-MM-DD` with `### Added` subsection | Industry standard; GitHub renders natively; D-22 already locks fresh-entry-only shape |
| Adapter contract verification | Custom assertion library | `EventSubjectAdapterContractTestCase` — 10 invariants already shipped Phase 2 [VERIFIED: in-repo file] | Marketplace contract anchor; locked Phase 2 ADAP-11 |
| Repo visibility flip mechanics | API scripts, OAuth flows | GitHub Settings UI → Visibility → Public OR `gh repo edit --visibility public --accept-visibility-change-consequences` | Atomic, one-click. Browser UI is the audit-trail-friendly path. |
| Annotated tag publishing | Custom release scripts | `git tag -a v2.0.0 -m "..."` + `git push origin v2.0.0` | Matches v1.1.1 precedent; D-21 explicit |
| History scrub for secrets | Custom `sed -i` loops | `git filter-repo --replace-text expressions.txt` (only if sweep finds real secrets) [CITED: github.com/newren/git-filter-repo] | `git filter-branch` deprecated; `filter-repo` is upstream-recommended modern replacement |
| Composer publish flow without Packagist | Manual zip uploads to GitHub Releases | Composer VCS install via `repositories:[{type:vcs,url:…}]` [CITED: getcomposer.org/doc/05-repositories.md] | Native Composer feature; no platform-side onboarding needed |
| Markdown table renderer for troubleshooting | Custom HTML templates | Plain GitHub-Flavored Markdown pipe tables | Marketplace + README both render GFM natively |

**Key insight:** Phase 5 is an assembly phase, not a creation phase. Almost everything Phase 5 needs already exists in the ecosystem; the work is selection, naming, sequencing, and verification. The only "creative writing" is the README, the CUSTOM-ADAPTERS.md authoring guide, and the CHANGELOG entry — and all three follow well-established October CMS + Keep-a-Changelog conventions.

## Runtime State Inventory

> Rename/refactor scope: **v1.x reference strip across `.planning/` + lang + Plugin.php docblocks + ROADMAP/REQUIREMENTS** (D-23). NOT a code rename. This inventory enumerates what string-state the operator will encounter when the public-facing surface is scrubbed.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — there is no DB-stored "v1.x"/"shopaholic-coupled" content. EventLog + FailedEvent tables store opaque alias (`'shopaholic.order'`) and Meta event names — no version strings. | None |
| Live service config | None — `new.nailscosmetics.lv` Settings table holds dummy values per D-18 (`pixel_id=000000000000000`, `access_token=REDACTED_FOR_DEMO_DO_NOT_USE`). Settings UI has no version-string field. | None |
| OS-registered state | None — Forge deploy uses standard `php artisan queue:work` + supervisord (assumed); no version string baked into process names. | None |
| Secrets/env vars | None in plugin scope — `.env` lives at project root, not in plugin repo. No secret keys reference "v1.x". | None |
| Build artifacts | None in plugin scope — composer.lock is not committed for plugin packages (H-4 from STATE.md). No `dist/` artifacts. | None |
| **Source-code v1.x decorators** | 13 hits across `classes/` + `components/` (Plugin.php:148 "Phase 3 D-08"; classes/testing/EventSubjectAdapterContractTestCase.php × 4 "Phase 2/3" references; classes/queue/SendCapiEvent.php × 2 "Phase 4 admin UI"; etc.) [VERIFIED: grep on 2026-05-21] | **CODE EDIT (05-11):** strip docblock decorators per D-23. Behavior unchanged. |
| **Planning-doc v1.x refs** | 29 hits across ROADMAP.md, REQUIREMENTS.md, STATE.md [VERIFIED: grep on 2026-05-21] | **DOC EDIT (05-11):** rewrite per D-23 — MKT-04 wording from "v1.1.1 + legacy/v1.1.1 branch preserved" → "v2.0.0 annotated tag from master". |
| **Operator-infra refs in .planning/** | 12 hits referencing `new.nailscosmetics.lv` outside archive (4 files: 05-CONTEXT.md, 05-DISCUSSION-LOG.md, milestones/v1.1.1-ROADMAP.md, research/PITFALLS.md) [VERIFIED: grep] | **DOC EDIT (05-13):** redact or generalize (D-26). 05-CONTEXT.md may keep the ref as planning context — operator judgment. |
| **Git tag `v1.1.1`** | Local-only [VERIFIED: `git tag -l` shows `v1.1.1`; `git ls-remote --tags origin 'v1*'` returns empty] | None — D-24 lock holds. Verify pre-flip. |
| **Legacy branch `legacy/v1.1.1`** | Local-only (presumed — verify) | **VERIFY (05-13):** `git ls-remote --heads origin 'legacy/*'` must return empty pre-flip. |

**Theme legacy state inventory** (D-02 grep targets — verified 2026-05-21):

| File | LOC | Pattern |
|------|-----|---------|
| `themes/logingrupa-naisstore/partials/facebook_pixel.htm` | 7 | Inline `<script>!function(f,b,e,v,n,t,s){...}fbq('init', '{{ this.theme.facebook_pixel_id }}')...fbq('track', 'PageView')</script>` — included by 4 layouts via `{% partial 'facebook_pixel' %}` |
| `themes/logingrupa-naisstore/layouts/main.htm` line 149 | (include) | `{% partial 'facebook_pixel' obUser=obUser %}` |
| `themes/logingrupa-naisstore/layouts/content.htm` line 104 | (include) | Same partial include |
| `themes/logingrupa-naisstore/layouts/light.htm` line 76 | (include) | Same partial include |
| `themes/logingrupa-naisstore/layouts/catalog_default.htm` line 100 | (include) | Same partial include |
| `themes/logingrupa-naisstore/pages/checkout.htm` lines 93-94 | 100 (total file) | `fbq('track', 'InitiatedCheckout', {...})` inline |
| `themes/logingrupa-naisstore/pages/order-complete.htm` line 24 | 34 | `fbq('trackCustom', 'ViewdOrderCompleatedStatusPage', {...})` (typo "Compleated" preserved as-is) — note comment line 20 says "Disabled: legacy fbq replaced by Logingrupa.Metapixelshopaholic PurchasePixel" — partial v1.x migration already attempted, never finished |
| `themes/logingrupa-naisstore/pages/order-complete-proforma.htm` line 16 | 25 | `fbq('trackCustom', 'ViewdOrderProformaPage', {...})` inline |
| `themes/logingrupa-naisstore/partials/product/search-result/search.js` | 184 | `fbq("track","Search",{search_string:...})` — production JS (transpiled, used by site) |
| `themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js` | 38 | `sendFacebookPurchaseEvent()` export — already commented out in caller `checkout-form-validation.js` line 83 |
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-add-to-cart.js` | 36 | `trackFacebookAddToCart()` — imported by `partials/shared/controls/add-to-cart-control.js` line 13 |
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-view-content.js` | 30 | `trackFacebookViewContent()` — imported by `partials/shared/controls/product-detail-control.js` line 13 |
| `themes/logingrupa-naisstore/configs/fields.yaml` | (~) | `facebook_pixel_id` + `facebook_domain_verification_id` theme-settings fields — operator must clear these in Theme Settings; OR strip from fields.yaml in 05-02 |

**Webpack bundled-pixel verification:** `themes/logingrupa-naisstore/common.js` (entry source) is 0 bytes. Compiled `assets/js/common.js` (217741 bytes) is a webpack bundle that includes `fbq("track","Search",...)` from `partials/product/search-result/search.js` (verified via `grep -aoE 'fbq\(...\)' assets/js/common.js`). Strip recipe must rebuild theme assets (`pnpm run prod` in theme dir) after removing the source `.js` files.

**Nothing found in category:** *(see "None" rows in table above — verified by grep on 2026-05-21)*

## Common Pitfalls

### Pitfall 1: Public-flip without history sweep leaks operator secrets

**What goes wrong:** Operator clicks Settings → Visibility → Public on GitHub. Within seconds, the entire `git log -p` of the repo is mirrored to clone-aggregator sites + GitHub search index. A real `capi_access_token` (Meta EAA-prefixed string, ~190 chars) in any historical commit is now permanently public.

**Why it happens:** Pre-flip security sweep (D-26) deferred or skipped. Or sweep run but only `HEAD` checked, not `git log -p --all`.

**How to avoid:**
1. Plan 05-13 runs BEFORE plan 05-14. Sequence locked per D-01.
2. Sweep covers `git log --all -p` (every branch, every tag, every commit).
3. Grep regex covers all secret patterns: `pixel_id\s*=\s*[0-9]{10,}`, `access_token\s*=\s*EAA[A-Za-z0-9]{20,}`, `capi_access_token\s*=\s*EAA[A-Za-z0-9]{20,}`.
4. ANY non-test/non-dummy hit triggers `git filter-repo --replace-text expressions.txt` AND force-push. Coordinate with operator — force-push rewrites history; downstream clones (operator's local) must `git fetch --all && git reset --hard origin/master`.
5. Re-verify post-rewrite that `git log --all -p` returns zero hits.

**Warning signs:** Sweep grep returns >0 hits AND the matched values are not in the dummy-set (`pixel_id=000000000000000`, `pixel_id=1234567890` — both verified-dummy in current history). [VERIFIED: current `git log --all -p | grep -iE 'pixel_id.*=.*[0-9]{10,}'` on 2026-05-21 returned only test fixtures + the documented D-18 dummy].

### Pitfall 2: Composer VCS install fails on first buyer because GitHub repo is still private

**What goes wrong:** Buyer reads README, runs `composer require logingrupa/oc-metapixel-plugin`, gets `Could not find a matching version of package logingrupa/oc-metapixel-plugin`. Composer can't reach a private GitHub repo without credentials.

**Why it happens:** Plan 05-14 ran `git tag` + `git push origin v2.0.0` but forgot the actual Settings → Visibility flip. Or flipped public but the README install instructions don't mention the VCS `repositories` block.

**How to avoid:**
1. Plan 05-14 verifies the flip BEFORE announcing launch: open `https://github.com/logingrupa/oc-metapixel-plugin` in an incognito browser window; must load without sign-in.
2. README install section explicitly shows the VCS repositories block:
   ```json
   {
     "repositories": [
       {"type":"vcs","url":"https://github.com/logingrupa/oc-metapixel-plugin"}
     ]
   }
   ```
3. Smoke-test in 05-14 task: `cd /tmp && mkdir test-install && cd test-install && composer init -n && [add VCS block] && composer require logingrupa/oc-metapixel-plugin` — must succeed without auth.

**Warning signs:** Composer error "Could not find package" or "Authentication required" when run from an unauthenticated machine.

### Pitfall 3: Smoke screenshots reveal real Pixel ID

**What goes wrong:** Operator forgets D-18 dummy-row check before screenshot capture. `01-settings.png` shows the operator's real production Meta Pixel ID. Screenshot ships in `docs/screenshots/` and is now public.

**Why it happens:** Smoke runs on `new.nailscosmetics.lv` with `test_event_code` set — operator may also have set a real `pixel_id` for testing (Test Events live view requires a real Pixel ID to route to the right account, even with test_event_code).

**How to avoid:**
1. Plan 05-12 has an explicit task: SQL UPDATE the Settings row to D-18 dummy values BEFORE screenshot capture, then SQL UPDATE back to real values AFTER capture. Capture commands documented inline.
2. Visual review checklist before commit: every screenshot is opened in image viewer; reviewer confirms only `000000000000000` and `REDACTED_FOR_DEMO_DO_NOT_USE` visible.
3. Defense in depth: image post-processing redaction with `imagemagick` (`convert in.png -fill black -draw "rectangle X1,Y1 X2,Y2" out.png`) deferred per D-18 ("no image post-processing required") — but available as fallback if a screenshot inadvertently captures non-Settings UI showing a real ID.

**Warning signs:** Any 5-screenshot review where Pixel ID field shows >0 digits that aren't `000000000000000`.

### Pitfall 4: UAT gate "passes" without three-source convergence

**What goes wrong:** Operator confirms UAT Gate 2 (PageView fires once) based ONLY on Meta Pixel Helper showing the event. The CAPI server-side dispatch silently fails (`MissingCapiTokenException` swallowed in queue worker); only the browser event reaches Meta. Days later, FailedEvents pile up — but UAT gate said "passed".

**Why it happens:** D-05 requires three sources but operator under-time-pressure checks only one. Pixel Helper is the fastest signal; it's tempting to stop there.

**How to avoid:**
1. Each UAT gate plan documents the three-source check as three separate task-checkboxes, not one combined "PASS" line. Operator must tick all three.
2. Pass criteria for each source has its own measurable threshold:
   - Pixel Helper: N events visible in popup
   - Test Events live view: same N events, "Deduplicated" label on each
   - EventLog DB: `SELECT count(*) FROM logingrupa_metapixel_event_log WHERE created_at >= NOW() - INTERVAL 2 MINUTE AND channel IN ('capi', 'pixel')` returns 2N rows (N capi + N pixel)
3. If any source under-counts: gate fails, route via `/gsd-debug` (D-09).

**Warning signs:** Pixel Helper count > EventLog row count (server-side never wrote — CAPI failed silently). OR EventLog has only `channel='capi'` rows, no `channel='pixel'` (EventPixel component not wired correctly OR `onMarkFired` AJAX 4xx'd).

### Pitfall 5: README 10-min timer fails because Settings UI requires Lovata.Toolbox install first

**What goes wrong:** Buyer's clean OctoberCMS 4.x install (no Lovata Toolbox) runs `composer require logingrupa/oc-metapixel-plugin`. Composer resolves Toolbox transitively (via `require: lovata/toolbox-plugin ^2.2` in plugin composer.json [VERIFIED]). Plugin installs. But Settings UI rendering pulls `CommonSettings` which requires Toolbox migration to have run. `php artisan october:up` MUST run after composer install. README must say so explicitly.

**Why it happens:** Composer install ≠ October migration ≠ ready-to-configure. New buyer expects `composer require` then "go to Settings UI" — misses migration step.

**How to avoid:**
1. README install section is 2 commands, not 1:
   ```bash
   composer require logingrupa/oc-metapixel-plugin
   php artisan october:up
   ```
2. README explicitly notes: "If Settings → Marketing → Meta Pixel + CAPI is not visible after install, run `php artisan october:up` to apply migrations."
3. 10-min timed dry-run includes the `october:up` step. If dry-run runs over 10 min due to this confusion, README is failing DOCS-01.

**Warning signs:** Buyer reports "I installed the plugin but Settings doesn't appear" — root cause is migrations not run.

### Pitfall 6: Annotated tag created from wrong commit

**What goes wrong:** Operator runs `git tag -a v2.0.0` while on a feature branch, OR before the smoke-validated commit lands, OR after merging an unrelated commit. The `v2.0.0` tag points at the wrong SHA. Composer-installed package state diverges from documented `v2.0.0` claim.

**Why it happens:** D-21 says "annotated tag from master HEAD at the smoke-validated commit" — but the smoke-validated commit and master HEAD must be the same SHA at tag-time. Easy to drift if any cleanup commit lands between smoke and tag.

**How to avoid:**
1. Plan 05-14 task sequence:
   1. `git switch master`
   2. `git pull origin master` (sync)
   3. Verify last commit = smoke-validated commit SHA (cross-reference 05-SMOKE-LOG.md)
   4. `git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"`
   5. `git push origin v2.0.0`
   6. Verify `git describe --tags --exact-match HEAD` returns `v2.0.0`
2. If any cleanup commit must land after smoke (D-23 v1.x ref strip, D-26 sweep cleanup): re-run abbreviated smoke spot-check before tag (PageView only — quickest path), THEN tag.

**Warning signs:** `git log --oneline master | head -5` shows post-smoke commits that weren't part of the audit trail.

## Code Examples

### Example 1: README install section (Phase 5 deliverable skeleton)

```markdown
# Meta Pixel + Conversions API for OctoberCMS

Server-deduplicated Meta Pixel and Conversions API tracking for OctoberCMS 4.x.
Works on any cart plugin via the adapter pattern (Lovata Shopaholic ships first-party;
custom adapters in 50 LOC — see [`docs/CUSTOM-ADAPTERS.md`](docs/CUSTOM-ADAPTERS.md)).

## Install

```bash
composer require logingrupa/oc-metapixel-plugin
php artisan october:up
```

If your `composer.json` does not have a VCS repository for this plugin:

```json
{
  "repositories": [
    {"type":"vcs","url":"https://github.com/logingrupa/oc-metapixel-plugin"}
  ]
}
```

## Configure

1. Backend → Settings → Marketing → **Meta Pixel + CAPI**.
2. Enter **Pixel ID** (digits-only). Acquire from Meta Events Manager → Data sources → Pixel → Settings.
3. Enter **CAPI Access Token**. Acquire from Meta Events Manager → Settings → Generate access token.
4. (Optional) Enter **Test Events Code** — routes events to the Meta Test Events panel for verification.
5. Save.

Per-site setup: pick the site from the top-bar site picker before saving; each site holds independent credentials.

[... etc. — full structure per `## Architecture Patterns › Pattern 2`]
```

### Example 2: docs/CUSTOM-ADAPTERS.md AcmeCartAdapter skeleton (planner expands)

```markdown
# Authoring a custom adapter

A custom adapter lets you ship Meta Pixel + Conversions API tracking for any
OctoberCMS cart plugin — Lovata Shopaholic, OFFLINE Mall, Meloncart, or a
hand-rolled custom cart — in ~50 lines of code.

## The contract

Implement two interfaces:

- `Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter` — subject metadata + routing
- `Logingrupa\Metapixel\Classes\Adapter\ValueResolver` — value/currency/contents

## Inline example: OFFLINE Mall

```php
namespace Offline\Mall\Adapters;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use OFFLINE\Mall\Models\Order;

final class MallOrderAdapter implements EventSubjectAdapter {
    public function getSubjectType(object $obSubject): string {
        return 'mall.order';  // opaque alias — NOT class FQN
    }
    public function getSubjectId(object $obSubject): int {
        return (int) $obSubject->getKey();
    }
    public function getSiteId(object $obSubject): ?int {
        // Read from subject — never from request context.
        return (int) ($obSubject->site_id ?? null) ?: null;
    }
    public function getSecretKey(object $obSubject): ?string {
        return (string) ($obSubject->getAttribute('hash') ?? '') ?: null;
    }
    public function getValueResolver(object $obSubject): ValueResolver {
        return new MallOrderValueResolver;
    }
    public function getUserData(object $obSubject): array {
        return [
            'em' => $obSubject->customer?->email,
            'fn' => $obSubject->customer?->firstname,
            // ... 11 more keys allowed; see EventSubjectAdapterContractTestCase invariant 07
        ];
    }
    public function getSupportedEvents(): array {
        return ['Purchase' => ['capi', 'pixel']];
    }
}
```

Register from your `Plugin::boot()`:

```php
public $require = ['Logingrupa.Metapixel'];

public function boot(): void {
    AdapterRegistry::instance()->register(Order::class, MallOrderAdapter::class);
    Order::extend(function ($obOrder) {
        $obOrder->bindEvent('model.afterSave', function () use ($obOrder) {
            if ($obOrder->isDirty('status') && $obOrder->status === 'paid') {
                $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
                    'Purchase', new MallOrderAdapter, $obOrder,
                    (new MallOrderAdapter)->getValueResolver($obOrder),
                    \Ramsey\Uuid\Uuid::uuid4()->toString(), time(), []
                );
                SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, MallOrderAdapter::class);
            }
        });
    });
}
```

## Three Event::fire hook patterns

### `before_dispatch` — inject test_event_code for staging

```php
Event::listen('metapixel.event.before_dispatch',
    function (string $sEventName, array &$arPayload, object $obSubject): ?bool {
        if (app()->environment('staging')) {
            $arPayload['test_event_code'] = config('mall.metapixel.test_event_code');
        }
        return null;
    }
);
```

### `after_dispatch` — mirror to analytics dashboard

```php
Event::listen('metapixel.event.after_dispatch',
    function (string $sEventName, array $arPayload, object $obSubject, array $arResponse): void {
        AnalyticsClient::record('meta_capi_success', [
            'event_name' => $sEventName,
            'event_id' => $arPayload['data'][0]['event_id'] ?? null,
            'fbtrace_id' => $arResponse['fbtrace_id'] ?? null,
        ]);
    }
);
```

### `dead_letter` — Slack alert on permanent failure

```php
Event::listen('metapixel.event.dead_letter',
    function (string $sEventName, array $arPayload, object $obSubject, \Throwable $obException): void {
        SlackWebhook::send([
            'channel' => '#alerts-metapixel',
            'text' => "Meta CAPI permanent failure: {$sEventName} on " . get_class($obSubject)
                    . " — {$obException->getMessage()}",
        ]);
    }
);
```

## Testing your adapter

Extend the contract base + supply factory methods:

```php
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;

final class MallOrderAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new MallOrderAdapter;
    }

    protected function makeSubject(): object
    {
        return Order::factory()->create(['site_id' => 1, 'status' => 'paid']);
    }
}
```

`pest tests/MallOrderAdapterContractTest.php` exits 0 → your adapter satisfies
the marketplace contract. The 10 invariants enforce:

1. subject_type is opaque alias (no backslashes, ≤64 chars)
2. subject_id positive int
3. getSiteId deterministic across successive calls
4. getSiteId returns ?int (no Request side effect — PHPStan disallowed-calls anchored)
5. getSecretKey returns ?string, never throws
6. getValueResolver returns ValueResolver instance
7. getUserData keys ⊆ 13-key Meta CAPI allowed set
8. getSupportedEvents shape is `Map<string, list<'capi'|'pixel'>>`
9. Registry round-trip via `AdapterRegistry::register` + `resolveFor`
10. PayloadBuilder envelope shape with 6 inner record keys

(Source: `classes/testing/EventSubjectAdapterContractTestCase.php`)
```

### Example 3: CHANGELOG.md fresh v2.0.0 entry (D-22)

```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - YYYY-MM-DD

Initial public release.

### Added

- Generic adapter pattern (`EventSubjectAdapter` + `ValueResolver` + `AdapterRegistry`) — track any subject (Order, theme action, custom cart) through one CAPI + Pixel pipeline
- First-party ShopaholicOrderAdapter — tracks `Lovata\OrdersShopaholic\Models\Order` Purchase events with `SKU-{product_id}[-{offer_id}]` content_ids matching the Facebook Catalog feed format
- First-party ThemeActionAdapter — generic Twig API (`{% do this.metapixel.pushEvent(...) %}`) + Larajax handler (`Metapixel::onFireEvent`) for any OctoberCMS theme
- Three extension hooks: `metapixel.event.before_dispatch` (halt-able), `after_dispatch` (observe), `dead_letter` (alert)
- Multisite-native Settings — per-site `pixel_id` + `capi_access_token` via October's top-bar site picker; `$propagatable = []` prevents cross-site token leak
- TrustedHosts allowlist + PSL-aware multi-TLD subdomain index (`jeremykendall/php-domain-parser`) — `_fbp` / `_fbc` cookies route correctly on `example.co.uk`, `example.com.br`, IDN hosts
- `EnsureFbpFbcCookies` middleware — kill switch, CR-03 fbclid validation (`[A-Za-z0-9_-]`, ≤255 chars)
- FailedEvents backend UI — list view, Replay, CheckDedup (queries Meta Test Events status)
- EventLog UNIQUE race-fence on `(subject_type, subject_id, event_name, channel, site_id)` — peer-wins idempotency
- Graph API pinned to `v23.0` (v20 expires 2026-09-24)
- English + Latvian translations
- PHP 8.3 + 8.4 dual support; PHPStan level 10, Rector UP_TO_PHP_83, Pint Laravel preset
- `composer qa` chain: pint-test → phpstan analyse → phpmd → pest --coverage --min=90 (Run A full-Lovata + Run B minimal-install CI matrix)
- `EventSubjectAdapterContractTestCase` marketplace contract (10 invariants) for third-party adapter authors
- `docs/CUSTOM-ADAPTERS.md` authoring guide with inline OFFLINE Mall example

[2.0.0]: https://github.com/logingrupa/oc-metapixel-plugin/releases/tag/v2.0.0
```

### Example 4: Pre-flip security sweep recipe (D-26)

```bash
# Step 1 — Secrets in git history (run from plugin root)
git log --all -p 2>&1 | grep -iE 'pixel_id\s*[=:]\s*[0-9]{10,}|access_token\s*[=:]\s*EAA[A-Za-z0-9]{20,}|capi_access_token\s*[=:]\s*EAA' | grep -vE '1234567890|000000000000000|REDACTED_FOR_DEMO|placeholder'

# Expected output: empty (no real secrets in history — verified 2026-05-21).
# Any non-empty match → STOP. Use git filter-repo to rewrite, then force-push.

# Step 2 — Operator-specific infra refs in .planning/
grep -rnE 'new\.nailscosmetics\.lv|forge\.laravel\.com|\b10\.[0-9]|\b192\.168\.' .planning/ | grep -v archive/

# Current count: 12 hits in 4 files (verified 2026-05-21).
# Action: redact each to "your-staging-host.example" or remove from public-shipped surface.

# Step 3 — Verify legacy archive stays local
git ls-remote --tags origin 'v1*'    # MUST be empty
git ls-remote --heads origin 'legacy/*'    # MUST be empty

# Step 4 — Final visibility-flip-readiness gate
git status    # clean
git log --oneline -1    # confirm smoke-validated SHA
gh repo view --json visibility    # confirm currently private (sanity)
```

### Example 5: Public flip + tag flow (D-21, D-25)

```bash
# Step 1 — Final pre-flip checks
cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel
git switch master
git pull origin master
git status    # clean
# Cross-reference .planning/phases/05-documentation-marketplace-launch/05-SMOKE-LOG.md → confirm HEAD = smoke-validated SHA

# Step 2 — Annotated tag (D-21)
git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"
git push origin v2.0.0

# Step 3 — Public flip (D-25)
gh repo edit logingrupa/oc-metapixel-plugin --visibility public --accept-visibility-change-consequences

# Step 4 — Verify composer VCS install works unauthenticated
cd /tmp && rm -rf test-install && mkdir test-install && cd test-install
cat > composer.json <<'EOF'
{
  "name": "test/install-verify",
  "minimum-stability": "stable",
  "repositories": [
    {"type":"vcs","url":"https://github.com/logingrupa/oc-metapixel-plugin"}
  ],
  "require": {
    "logingrupa/oc-metapixel-plugin": "^2.0"
  }
}
EOF
composer install --no-interaction    # Expect: success, no auth prompt

# Step 5 — Verify tag visible
gh release view v2.0.0   # OR git ls-remote --tags origin v2.0.0
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `git filter-branch` for history rewrite | `git filter-repo` | 2019 (git-scm deprecation notice) | Modern recommended path; ~100× faster on large repos |
| Markdown rendered as static HTML site (e.g., MkDocs) | Single-page README rendered by GitHub/marketplace | Industry norm for small-to-medium plugins | Lower buyer friction — no docs site nav to navigate |
| `.htaccess` / inline `<script>` for Facebook Pixel | `connect.facebook.net/en_US/fbevents.js` + server-side CAPI dedup via event_id | Meta sunset older Pixel JS late 2010s; CAPI launched 2020 | Browser-only tracking is no longer competitive against iOS 14+ ATT / ITP / ad-blocker losses |
| Graph API endpoint version-per-call | Server-pinned `v23.0` constant | Phase 2 ADAP-09 | v20 deprecation 2026-09-24; plugin avoids per-call drift |
| `git tag v2.0.0` (lightweight) | `git tag -a v2.0.0 -m "..."` (annotated) | Industry preference for releases | Annotated tags carry author + date + GPG signature support |

**Deprecated/outdated:**

- **`git filter-branch`** — deprecated upstream. Use `git filter-repo`. [CITED: man git-filter-branch — "git-filter-branch is deprecated".]
- **Lightweight tags for releases** — annotated tags strictly preferred. Allow `git describe`, signed releases, release notes.
- **Multi-page docs site for small plugins** — adds 10× the maintenance burden, marketplace renders README directly anyway.
- **Theme-side hand-rolled Pixel** (current `themes/logingrupa-naisstore/partials/facebook_pixel.htm`) — plugin's `PixelHead` + `EventPixel` replace; D-02..D-04 cutover.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | OctoberCMS marketplace LISTING page may require PNG icon at 64×64 [CITED: octobercms.com/help/guidelines/quality but plugin is not yet listed there — guideline applies if/when listed] | Standard Stack / Pitfalls | Low — D-20 already punts to v2.0.1 patch |
| A2 | `gh repo edit --visibility public --accept-visibility-change-consequences` is the exact CLI flag string [VERIFIED via WebSearch + gh release notes; flag name may have changed] | Code Examples / Pattern flow | Low — fallback is GitHub web UI |
| A3 | `composer require logingrupa/oc-metapixel-plugin` will resolve via Composer's GitHub auto-discovery once repo is public, WITHOUT requiring the `repositories` block — assumed FALSE per D-25 phrasing. README plays safe and shows the explicit block. | README install section | Low — explicit block always works; auto-discovery is a bonus if it works |
| A4 | Forge zero-downtime release does NOT re-trigger `october:up` automatically on plugin updates | README troubleshooting | Medium — buyer runs `october:up` manually anyway per Pitfall 5 |
| A5 | `git ls-remote --heads origin 'legacy/*'` returns empty (legacy branch is local-only per D-24) — verified ONLY for tag, not for branch | Runtime State Inventory | Low — pre-flip verification re-runs the check |
| A6 | Meta Test Events live view dedup window is "within minutes" of event_time [CITED: leadsbridge.com + watsspace.com — sources agree but Meta official docs not directly reachable] — earlier v1.x phase docs say "±10s". Phase 5 should NOT depend on the exact value; UAT gate criteria say "Deduplicated label shown" which is the Meta-reported result, not a numerical window | UAT pass criteria | Low — Meta reports dedup state directly; we observe its label, not compute it |
| A7 | Smoke environment `new.nailscosmetics.lv` has Forge deploy permissions set up + operator can ssh to place test orders + EventLog table is queryable from operator's tools [ASSUMED based on CONTEXT D-06] | Validation Architecture | Medium — if missing, smoke gates 05-08 cannot run; operator confirms during 05-08 task 1 |

**The Assumptions Log is short by design.** Phase 5 deliberately picks well-trodden tooling and conventions. The handful of [ASSUMED] entries above need user confirmation only at the planner level — none are load-bearing for the locked plan sequence.

## Open Questions (RESOLVED)

1. **Should plan 05-12 also write `assets/images/icon.png` PNG as a defensive measure even though D-20 punts?**
   - What we know: October backend renders `icon-bullseye` FA glyph natively [CITED: docs.octobercms.com]; marketplace listing MAY require PNG [CITED: octobercms.com/help/guidelines/quality]
   - What's unclear: whether D-20 "punt to that point" intends to defer the PNG to a separate plan trigger or accept the marketplace-listing risk
   - **RESOLVED:** Keep FA `icon-bullseye` per D-20. No PNG defensive ship in plan 05-12. If marketplace listing later requires PNG, defer to v2.0.1 patch (already a Deferred Idea in 05-CONTEXT.md).

2. **What `composer.json` license should v2.0.0 ship?**
   - What we know: Currently `"proprietary"`. D-25 public-flip + Deferred Ideas note "license re-evaluation punt to planner discretion in plan 05-14".
   - What's unclear: Whether MIT/Apache-2.0/proprietary best matches operator intent (commercial-friendly marketplace vs. permissive open source)
   - **RESOLVED:** Operator picks at plan 05-14 Task 1 as `checkpoint:decision`. Default fallback if no operator choice is recorded: keep `"proprietary"` (the current state) and document as a v2.0.1 follow-up. This is acknowledged as a deferred decision, NOT an unresolved blocker — the plan 05-14 checkpoint provides the resolution surface.

3. **Does `git filter-repo` need installation in plan 05-13 or is the security sweep guaranteed to find nothing?**
   - What we know: Current `git log --all -p` scan finds only D-18 dummy values + test fixtures (`1234567890`) [VERIFIED 2026-05-21]
   - What's unclear: Whether a future commit between now and 05-13 could introduce a real secret
   - **RESOLVED:** Install opportunistically in plan 05-13 Task 1 (`pip install --user git-filter-repo`) ONLY if the secret-history grep returns hits. If the grep is clean (RESEARCH baseline 2026-05-21), filter-repo is never invoked. Plan 05-13 documents the install-vs-skip decision explicitly in the sweep doc.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `git` | All Phase 5 plans (tag, history sweep, push) | ✓ [VERIFIED] | system git | — |
| `gh` (GitHub CLI) | 05-14 public flip; 05-13 sweep convenience | ✓ [VERIFIED: 2.4.0] | 2.4.0 | Browser UI for repo settings; raw `git push` for tag |
| `composer` | 05-14 smoke-test VCS install verification | ✓ [VERIFIED: project uses it] | — | — |
| `git-filter-repo` | 05-13 history rewrite IF sweep finds secrets | ✗ [VERIFIED: command not found] | — | `pip install --user git-filter-repo`; OR `git filter-branch` (deprecated, slow) |
| `slopcheck` | Phase 5 package legitimacy audit | ✗ [VERIFIED: command not found] | — | N/A — Phase 5 installs zero packages, audit is no-op |
| `imagemagick` (`convert`) | 05-12 screenshot redaction (fallback for D-18) | Unverified | — | Manual screenshot retake with dummy Settings row in place |
| Forge SSH access to `new.nailscosmetics.lv` | 05-08 live smoke | Operator-side [ASSUMED A7] | — | None — smoke cannot run without env |
| Chrome + Meta Pixel Helper extension | UAT gates 1-3 + smoke verification | Operator-side | — | Edge/Firefox if equivalent; OR raw DevTools Network panel (D-05 already rejected this fallback) |
| Meta Business Manager + Pixel + Test Events code | Live smoke + UAT verification | Operator-side | — | None — Meta-side requirement |
| PHP 8.4 + Lovata stack (full-Lovata smoke) | 05-08 Shopaholic Purchase smoke | new.nailscosmetics.lv [ASSUMED A7] | — | — |
| Clean OctoberCMS 4.x test machine | DOCS-01 timed dry-run + MKT-01 install verification | Buyer machine OR /tmp scratch | — | Docker container with OctoberCMS image; deferred per D-06 |

**Missing dependencies with no fallback:**
- Forge SSH to `new.nailscosmetics.lv` — operator confirms during 05-08 task 1 prep

**Missing dependencies with fallback:**
- `git-filter-repo` — install on demand in 05-13
- `slopcheck` — not needed (no new packages)

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 (`pestphp/pest ^4.1`) + PHPUnit 12 + Larastan ^3.0 (PHPStan level 10) — all VERIFIED in composer.json |
| Config file | `phpunit.xml` (root) + `phpstan.neon` (root) + `pint.json` + `phpmd.xml` |
| Quick run command | `pest --filter='specific test'` or per-Wave 0 file scope |
| Full suite command | `composer qa` (= `pint-test → analyse → phpmd → test-cov --min=90`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| DOCS-01 | Timed dry-run: buyer reaches verified CAPI event in <10 min following README | **manual-only** (buyer dry-run) | Operator stopwatch + EventLog tail + Meta Test Events screenshot | ❌ (manual UAT — no automation possible — D-01 launch acceptance gate) |
| DOCS-02 | README sections present + lang-key labels match | unit + grep | `grep -E '^## (Install\|Configure\|Acquire Meta\|Shopaholic\|Theme\|FailedEvents\|Troubleshoot)' README.md \| wc -l` (expect ≥7); `grep -F '::lang.field.pixel_id_label' lang/en/lang.php` | ❌ Wave 0 — `tests/Feature/Docs/ReadmeStructureTest.php` |
| DOCS-03 | CUSTOM-ADAPTERS.md exists, includes 3 hook examples, 1 OFFLINE Mall example | unit + grep | `test -f docs/CUSTOM-ADAPTERS.md && grep -c "metapixel.event.before_dispatch\|after_dispatch\|dead_letter" docs/CUSTOM-ADAPTERS.md` (expect ≥3); `grep -c "OFFLINE\\\\\\\\Mall" docs/CUSTOM-ADAPTERS.md` (expect ≥1) | ❌ Wave 0 — `tests/Feature/Docs/CustomAdaptersStructureTest.php` |
| MKT-01 | `composer require logingrupa/oc-metapixel-plugin` succeeds on clean OctoberCMS | **smoke + manual** | Scratch dir + composer init + VCS block + `composer require` exit 0 | ❌ Manual smoke in 05-14 task |
| MKT-02 | plugin.yaml fields are generic + lang-key driven | unit | `pest tests/Feature/Plugin/PluginYamlSanityTest.php` (verify keys: `logingrupa.metapixel::lang.plugin.name`, `…description`, author `Logingrupa`, icon `icon-bullseye`, homepage matches GitHub URL) | ❌ Wave 0 (or extend existing PluginSanityTest) |
| MKT-03 | 5 screenshots exist + CHANGELOG.md valid Keep-a-Changelog | unit | `ls docs/screenshots/0[1-5]-*.png \| wc -l` (expect 5); `grep -E '^## \[2\.0\.0\]' CHANGELOG.md` | ❌ Wave 0 — `tests/Feature/Docs/AssetsExistTest.php` |
| MKT-04 | `git tag v2.0.0` annotated, pushed | **manual** | `git tag -v v2.0.0 \| grep '^object'` (verify annotated, not lightweight); `git ls-remote --tags origin v2.0.0` (verify pushed) | ❌ Manual in 05-14 |
| MKT-05 | `composer qa` exits 0 on Run A + Run B | **CI matrix** | `.github/workflows/metapixel-qa.yml` (Phase 1 shipped) — 4-cell matrix runs `composer qa` on push | ✅ Already shipped Phase 1 TOOL-09 |

### Sampling Rate

- **Per task commit:** `pest --filter='Docs\|Plugin\|Assets'` (Wave 0 doc-structure tests only — fast)
- **Per wave merge:** `composer qa` (full pint → phpstan → phpmd → pest-cov chain)
- **Phase gate:** Full `composer qa` green on Run A AND Run B (CI matrix on tag push) + DOCS-01 manual dry-run timer ≤10 min + 05-SMOKE-LOG.md fully filled

### Wave 0 Gaps

Wave 0 = new test files needed before Phase 5 plans land:

- [ ] `tests/Feature/Docs/ReadmeStructureTest.php` — covers DOCS-01 + DOCS-02. Asserts: file exists, ≥7 named sections, no v1.x references (`assertStringNotContainsString('v1.', $sReadme)`), every `lang/en/lang.php` field label appears in README (DOCS-02 walkthrough fidelity)
- [ ] `tests/Feature/Docs/CustomAdaptersStructureTest.php` — covers DOCS-03. Asserts: file exists at `docs/CUSTOM-ADAPTERS.md`, contains all 3 hook constant references, OFFLINE Mall inline example present, `EventSubjectAdapterContractTestCase` extension example present
- [ ] `tests/Feature/Docs/AssetsExistTest.php` — covers MKT-03. Asserts: 5 PNGs in `docs/screenshots/` matching `0[1-5]-*.png`; CHANGELOG.md exists with `## [2.0.0]` section header
- [ ] `tests/Feature/Plugin/PluginYamlSanityTest.php` — covers MKT-02. Asserts: plugin.yaml `icon == 'icon-bullseye'`, author == 'Logingrupa', name + description are lang keys (not literals), homepage matches GitHub URL pattern
- [ ] *(Optional)* `tests/Feature/Docs/NoV1xReferencesTest.php` — covers D-23. Greps lang/ + Plugin.php + ROADMAP.md + REQUIREMENTS.md for `v1\.|legacy/v1|Phase [1-5]` and asserts zero hits in public-facing surface
- [ ] Framework install: NONE — Pest 4 + Larastan already shipped

**Manual / non-automated gates** (NOT Wave 0 test files — these are operator UAT):

- DOCS-01 10-minute timed dry-run on clean OctoberCMS 4.x install (launch acceptance gate per Success Criterion 1)
- Three UAT gates (D-03, D-05): zero-events, PageView-only, event_id-sync — each with three-source verification (Pixel Helper + Test Events + EventLog DB tail)
- Smoke produces exactly 3 event classes × N entries per channel (D-07 + Pitfall 4 mitigation)
- Public-flip irreversibility check: composer VCS install from `/tmp/test-install/` works without auth (Pitfall 2 mitigation)
- Pre-flip secret-history grep returns empty (Pitfall 1 mitigation)
- Smoke screenshot review: every PNG shows only D-18 dummy values (Pitfall 3 mitigation)
- Annotated tag at correct SHA: `git describe --tags --exact-match HEAD == v2.0.0` (Pitfall 6 mitigation)

### Six Validation Criteria Coverage (Phase 5 ask)

| # | Criterion | Validation Mechanism | Where Asserted |
|---|-----------|---------------------|----------------|
| 1 | Buyer dry-run timer ≤10 min | Manual UAT — operator stopwatch on clean OctoberCMS 4.x install following README | 05-09 README plan acceptance task; documented in Success Criterion 1 of phase entry |
| 2 | `composer qa` exit-0 on both CI matrix branches | Automated CI — `.github/workflows/metapixel-qa.yml` Run A (full-Lovata) + Run B (minimal-install) | Already shipped Phase 1 TOOL-09; gate-fail = phase fail |
| 3 | Three UAT gates pass/fail measurable | Three-source verification per gate (Pixel Helper + Test Events + EventLog DB) with named pass criteria per plan | 05-03, 05-05, 05-07 plan tasks; Pattern 1 in `## Architecture Patterns` |
| 4 | Smoke log produces 3 event classes × N entries | `05-SMOKE-LOG.md` markdown audit trail; `SELECT count(*), event_name, channel FROM logingrupa_metapixel_event_log GROUP BY event_name, channel` returns 3 event names × 2 channels = 6 rows minimum | 05-08 plan task |
| 5 | Public-flip irreversibility check | `/tmp/test-install` composer VCS install from unauthenticated machine exits 0 | 05-14 plan task 4 |
| 6 | Pre-flip secret-history grep returns empty | `git log --all -p \| grep -iE 'pixel_id.*=.*[0-9]{10,}\|access_token.*=.*EAA' \| grep -vE '1234567890\|000000000000000\|REDACTED_FOR_DEMO'` returns empty | 05-13 plan task 1; Pitfall 1 |

## Project Constraints (from CLAUDE.md)

### From parent `/home/forge/nailscosmetics.lv/CLAUDE.md`

- **GSD workflow enforcement:** "Before Edit/Write/file-changing tools, start via GSD command." Phase 5 plans must use GSD execution flow.
- **No direct repo edits outside GSD unless user explicitly bypasses.**
- **Tiger-Style fail-fast** for any new code. Phase 5 ships almost no code — only docs + manifest review. Where code touches (e.g., Plugin.php docblock strip per D-23), behavior must not change.
- **Lovata.Toolbox patterns + Hungarian notation.** Phase 5 does not add new PHP classes — no Hungarian concerns. README + CHANGELOG use buyer-facing English.
- **PHP 8.3+ (prod 8.4)** — Phase 5 introduces no new code paths; existing PHP version compatibility carries.
- **PSR-2 via phpcs.xml.** Already enforced via `composer qa`.

### From plugin `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/CLAUDE.md`

- **Build philosophy locked:** "No over-engineering. No BC shims to v1.x. No dead code, no unused functions. Fresh code, NOT v1.x port. Simple > clever."
  - Phase 5 strictly honors this: zero new dependencies, single-page README (not multi-page docs), one-file CUSTOM-ADAPTERS.md, fresh CHANGELOG (no v1.x diff per D-22).
- **No comment pollution:** "zero `// CR-XX` / `// REFAC-XX` / `// Phase N` / `// Plan N` markers in code. Workflow refs belong in commits/PRs, not source."
  - Plan 05-11 enforces this via D-23 — 13 in-code "Phase N" decorators identified for strip.
- **No `assert()` anywhere** — irrelevant for docs phase but carries.
- **Self-explanatory class names** — irrelevant for docs phase but carries.
- **Adapter pattern locked:** "Adapter contract (10 invariants) is the marketplace contract."
  - DOCS-03 + D-16 anchor CUSTOM-ADAPTERS.md to `EventSubjectAdapterContractTestCase` 10 invariants verbatim. Cannot diverge from contract.
- **Graph API pinned to v23.0** — README + CUSTOM-ADAPTERS.md mention version once; no operator override documented.
- **PluginGuard cascade-safety:** "empty pixel_id → `Log::warning` + disabled flag, NEVER throw at boot." README troubleshooting table reflects this — empty pixel_id manifests as silent disable, not crash.
- **content_ids format anchor:** `SKU-{product_id}[-{offer_id}]` matches Facebook Catalog feed [VERIFIED: ShopaholicOrderValueResolver.php line 138-149]. CUSTOM-ADAPTERS.md notes this as Shopaholic-adapter-specific format; other adapters define own format via `ValueResolver::resolveContentIds()`.
- **Multisite: `$propagatable = []` lock holds at descendant level.** README multi-site section documents this; CUSTOM-ADAPTERS.md does not re-document (planner cross-references).

## Security Domain

Phase 5 deliverables: docs + manifest + screenshots + tag + repo-visibility flip. No new code paths, no new attack surface in the plugin itself. Security work in Phase 5 = **history hygiene + redaction** before public flip.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | GitHub repo auth is operator-side (gh CLI); not a plugin concern |
| V3 Session Management | no | Phase 5 ships zero session-handling code |
| V4 Access Control | no | Phase 5 ships zero access-control code |
| V5 Input Validation | no | Phase 5 ships zero input handlers |
| V6 Cryptography | no | Phase 5 introduces no crypto; existing CAPI uses sha256 via UserDataHasher (Phase 2) |
| **V14 Configuration** | **YES** | **Pre-flip secret-history sweep (D-26) — Pitfall 1 mitigation. No real `pixel_id` / `capi_access_token` values in git history. Verified 2026-05-21: current scan finds only dummy + test fixtures.** |
| **V14 Configuration** | **YES** | **Operator-infra refs (`new.nailscosmetics.lv`, `forge.laravel.com`, internal IPs) redacted from `.planning/` before public flip. 12 hits found (Runtime State Inventory).** |
| V12 File and Resources | edge | Screenshots in `docs/screenshots/` must not show real Pixel IDs (Pitfall 3) — verified via D-18 dummy-row strategy + visual review |

### Known Threat Patterns for marketplace launch

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Public repo leaks operator Meta credentials | Information Disclosure | D-26 history sweep; `git filter-repo` on demand; Pitfall 1 |
| Screenshot leaks operator Meta credentials | Information Disclosure | D-18 dummy-row strategy; visual review before commit; Pitfall 3 |
| Public repo leaks internal infrastructure topology (hostnames, IPs) | Information Disclosure | D-26 grep + redact; Runtime State Inventory category 2 |
| Slopsquat — buyer installs malicious package with similar name | Tampering / Supply chain | D-25 explicit `logingrupa/oc-metapixel-plugin` name in README + Composer VCS install URL pinned to GitHub repo (not Packagist namespace squatting risk) |
| MitM on `composer require` via VCS install | Tampering | HTTPS-only GitHub URL in README (composer rejects http: VCS by default); GPG-signed annotated tag (D-21 future-proof — current tag is unsigned, accept) |
| Buyer trusts README — README leads buyer to expose credentials | Information Disclosure | D-12 numbered text steps (no screenshots of Meta Business Manager UI showing buyer's account); README explicitly tells buyer to NOT commit `.env` |

## Sources

### Primary (HIGH confidence) — in-repo VERIFIED via Read/Bash

- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md` — 27 locked decisions D-01..D-27
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md` — DOCS-01..03 + MKT-01..05 requirement text
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning/STATE.md` — accumulated decisions, pitfall ownership map
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.planning/ROADMAP.md` — Phase 5 entry, Architecture-at-a-glance, Pitfall Coverage Map
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/CLAUDE.md` — plugin-specific build philosophy + extensibility contract
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/composer.json` — package name, php constraint, suggest pattern
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/plugin.yaml` — current manifest shape
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/Plugin.php` — boot flow + adapter registration + 13 `Phase N` docblock decorators
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/testing/EventSubjectAdapterContractTestCase.php` — 10-invariant marketplace contract
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php` — 3-hook signatures verbatim
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicOrderValueResolver.php` — content_ids format verification
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/lang/en/lang.php` — Settings field labels for README walkthrough anchor
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/facebook_pixel.htm` — legacy pixel partial (7 LOC)
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/layouts/{main,content,light,catalog_default}.htm` — 4 partial-include sites
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/pages/{checkout,order-complete,order-complete-proforma}.htm` — 3 inline fbq sites
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/{form/checkout-form,shared}/tracking/*.js` — 3 webpack source JS exports (38+30+36 LOC)
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/assets/js/common.js` — bundled `fbq("track","Search",...)` confirmed via grep
- Lovata in-repo READMEs sampled: `plugins/lovata/{subscriptions,paypal,filter}shopaholic/README.md` — single-page convention confirmed

### Secondary (MEDIUM confidence) — external verified

- [October CMS — Plugin Registration docs](https://docs.octobercms.com/4.x/extend/system/plugins.html) — confirmed `pluginDetails().icon` accepts FA class strings like `icon-bullseye`; also accepts `iconSvg` field for SVG
- [October CMS — Marketplace Quality Guidelines](https://octobercms.com/help/guidelines/quality) — confirmed marketplace listing PNG icon at 64×64 transparent; 5 screenshots max at 1.33 aspect ratio (838×630px ideal)
- [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) — confirmed `## [VERSION] - YYYY-MM-DD` + `### Added` section template; cross-referenced with multiple template sources
- [Composer — Repositories](https://getcomposer.org/doc/05-repositories.md) — confirmed `type: vcs` URL pattern for non-Packagist install
- [Composer — Handling private packages](https://getcomposer.org/doc/articles/handling-private-packages.md) — confirmed VCS install works for public + private GitHub repos; for public + no auth, `repositories` block recommended
- [Meta CAPI dedup — multiple sources cross-referenced](https://leadsbridge.com/blog/facebook-conversions-api-events/) + [Watsspace dedup explainer](https://watsspace.com/blog/meta-conversions-api-deduplication-event_id/) — confirmed Test Events live view, "Deduplicated" label, test_event_code routing behavior
- [AdAmigo CAPI test guide](https://www.adamigo.ai/blog/how-to-test-meta-conversion-events) — confirmed Meta Events Manager → Test Events tab UI path; test event data 24-hour retention

### Tertiary (LOW confidence) — needs validation if load-bearing

- Exact Meta dedup window in seconds — sources vary ("within minutes" / "short window"); UAT criteria use Meta's reported "Deduplicated" label, NOT a numerical window — no risk
- October Marketplace listing PNG icon mandatoriness — quality guidelines describe requirements but plugin is not yet listed there; D-20 punts — no risk

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every package VERIFIED in in-repo composer.json; no new packages introduced
- Architecture: HIGH — Phase 5 deliverables are docs + assets, not code; patterns drawn from CONTEXT.md locked decisions + in-repo precedent
- Pitfalls: HIGH — 6 pitfalls all rooted in concrete CONTEXT.md decisions (D-21, D-25, D-26, D-18, D-09, D-05); none speculative
- Validation: HIGH — Wave 0 test list is concrete (5 file paths); 6 validation criteria each map to a real verification mechanism
- Security: HIGH on history sweep + redaction (D-26 explicit); LOW on broader ASVS scope (Phase 5 ships no auth/access/crypto code)

**Research date:** 2026-05-21
**Valid until:** 2026-06-20 (30 days — marketplace conventions and Composer VCS install path are stable; Meta CAPI Graph API pinned to v23.0 with no version drift until 2026-09-24; OctoberCMS marketplace guidelines update infrequently)
