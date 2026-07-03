# Phase 5: Documentation + marketplace launch - Context

**Gathered:** 2026-05-21
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 5 delivers the marketplace launch surface for v2.0.0. Scope expanded during discuss to absorb two pre-launch blockers:

1. **Legacy v1.x JS pixel strip** in `themes/logingrupa-naisstore/` — gated cutover with operator UAT between each step (strip → zero-events verify → PixelHead add → PageView verify → EventPixel per event → event_id-sync verify).
2. **Live end-to-end smoke** on `your-staging-host.example` (Forge-hosted, real Lovata stack, real orders, `test_event_code` set) — produces the validated step-sequence + screenshots that feed the README walkthrough.
3. **Docs (DOCS-01..03):** single-page `README.md` with Shopaholic + Theme twin walkthroughs; `docs/CUSTOM-ADAPTERS.md` with OFFLINE\Mall preview example + all 3 Event::fire hook patterns + dedicated `## Testing your adapter` section covering `EventSubjectAdapterContractTestCase`.
4. **Marketplace assets (MKT-01..05):** 5 screenshots from the smoke run, FA `icon-bullseye` kept, generic `plugin.yaml`, CHANGELOG.md as **clean v2.0.0 initial release** (no v1.x diff), pre-flip-public security sweep, annotated tag from master, repo flipped public at launch.

Strict no-scope-creep beyond these four buckets. AddToCart + Theme Twig API smoke deferred to post-launch. MallAdapter ships only as inline doc example, not production code.

</domain>

<decisions>
## Implementation Decisions

### Pre-launch blockers (added during discuss — scope expansion)

- **D-01:** Phase 5 absorbs legacy-strip + live-smoke plans BEFORE docs/manifest/tag plans. ROADMAP.md Phase 5 entry needs an edit to reflect expanded scope; CONTEXT.md is the authoritative scope record until that ROADMAP edit lands. Plans sequenced as: 05-01 legacy JS inventory → 05-02 legacy JS strip → 05-03 zero-events UAT gate → 05-04 PixelHead layout wire → 05-05 PageView UAT gate → 05-06 EventPixel per-event wire → 05-07 event_id-sync UAT gate → 05-08 live smoke (Purchase + PageView + ViewContent) → 05-09 README → 05-10 docs/CUSTOM-ADAPTERS.md → 05-11 v1.x reference strip across `.planning/` + lang + Plugin.php → 05-12 plugin.yaml + CHANGELOG.md + screenshots → 05-13 pre-flip security sweep → 05-14 repo flip public + v2.0.0 annotated tag. Planner refines the wave assignment.

### Legacy JS pixel strip + cutover

- **D-02:** Inventory plan greps `themes/logingrupa-naisstore/` for ALL three legacy emission patterns simultaneously: inline `<script>fbq(...)` in layouts/pages/partials; bundled pixel logic in webpack entry `assets/js/common.js`; Twig `{% set %}` dataLayer + dispatcher partial. Pattern detection scripts: `grep -rE 'fbq\(|fbevents\.js|connect\.facebook\.net' themes/logingrupa-naisstore/`, plus webpack source-map inspection for the bundled case.
- **D-03:** Cutover is gated two-step (operator-confirmed between plans). Strip first → operator verifies zero pixel events fire on every page (homepage, product, checkout, post-purchase). Only after explicit operator confirmation does PixelHead component land. Then EventPixel per event lands and operator verifies event_id-sync. Three named UAT gates with measurable pass/fail criteria documented per-plan.
- **D-04:** Replacement = `PixelHead` in layout (head-tag base pixel) + `EventPixel` per event-emitting page (server-confirmed browser pixel). Server-side CAPI handled by ShopaholicAdapter (Phase 3). Browser + server stay event_id-synced via dedup window (±10s).
- **D-05:** Verification toolchain for each UAT gate combines three sources: (1) Meta Pixel Helper Chrome extension (fastest manual signal), (2) Meta Test Events live view in Events Manager (highest signal — confirms dedup), (3) `logingrupa_metapixel_event_log` DB table tail (server-side CAPI confirmation). DevTools Network panel skipped — Pixel Helper covers the same surface. Each plan documents the exact pass/fail criteria for its gate.

### Live end-to-end smoke

- **D-06:** Target environment = `your-staging-host.example` (Laravel Forge-hosted Production-shaped staging install with real Lovata stack + real orders + Settings `test_event_code` set so events route to Meta Test Events live view, NOT production Pixel analytics). NOT docker. NOT prod.nailscosmetics.lv. Single environment — no parallel docker dry-run.
- **D-07:** Smoke event set = Purchase (primary, DOCS-01 critical path) + PageView (head-tag sanity) + ViewContent (content_ids format `SKU-{product_id}[-{offer_id}]`). AddToCart + Theme Twig API custom event deferred to post-launch.
- **D-08:** Smoke results captured as `.planning/phases/05-documentation-marketplace-launch/05-SMOKE-LOG.md` — markdown audit trail with timestamp, env, exact button clicks, EventLog row count, Meta Test Events screenshot count, fbp/fbc cookie values, event_id sample, fail-pass per step. README walkthrough copies the validated step sequence verbatim from this file.
- **D-09:** Smoke-found bugs route to `/gsd-debug` sessions (one session per bug). Fix lands in a Phase 5 fix-plan or backport plan as the debug session determines. NOT inline-fix-and-continue.

### Docs (DOCS-01..03)

- **D-10:** README.md = single-page linear walkthrough (Install → Configure → Shopaholic → Theme → FailedEvents → Troubleshoot). Marketplace pages render the README directly. Long-form depth lives only in `docs/CUSTOM-ADAPTERS.md` (MANDATORY per DOCS-03), no other docs/ files unless surfaced by smoke needs.
- **D-11:** README covers BOTH Shopaholic Purchase path AND Theme Twig API path as twin walkthroughs. Matches MKT-05 CI matrix (Run A full-Lovata + Run B minimal-install) — buyers from both audiences must succeed in <10 min.
- **D-12:** Meta credential acquisition (Business Manager → Data Sources → Pixel → Settings → Generate Access Token) documented as numbered steps in **plain text only, no screenshots**. Survives Meta UI redesigns. Higher friction for non-marketers accepted.
- **D-13:** Troubleshooting runbook shape = markdown table mapping `symptom → Log::* line → fix`. Columns: visible buyer symptom ("FailedEvents pile up", "No EventLog row"), grep-able log message ("metapixel: missing pixel_id"), operator action. Matches DOCS-02 wording ("keyed to `Log::*` context arrays").
- **D-14:** `docs/CUSTOM-ADAPTERS.md` example tracks `OFFLINE\Mall\Models\Order` (real Mall plugin model). Code lives **only inline in the doc** as code blocks (~50 LOC adapter + ~30 LOC value resolver). NOT in `plugins/logingrupa/metapixel/classes/adapter/mall/`. No composer require on OFFLINE\Mall. Matches ROADMAP backlog MALL-01 deferral to v2.1.
- **D-15:** `docs/CUSTOM-ADAPTERS.md` shows all 3 `Event::fire` hooks with concrete third-party use cases: `before_dispatch` → inject `test_event_code` for staging; `after_dispatch` → mirror EventLog to analytics dashboard; `dead_letter` → Slack alert. Copy-paste-able. No links-only treatment.
- **D-16:** `docs/CUSTOM-ADAPTERS.md` includes dedicated `## Testing your adapter` section documenting how to extend `Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase` + supply `makeAdapter()` + `makeSubject()`. Locks the v2.x adapter marketplace contract (10 invariants).

### Marketplace assets (MKT-01..05)

- **D-17:** All 5 MKT-03 screenshots come from the live smoke on `your-staging-host.example` — Settings UI, FailedEvents list (after intentionally bad-token call), Replay flow success, CheckDedup with Meta API response, theme Twig API on a real product page. Smoke produces them as side-effect. NOT synthetic docker. NOT Figma mockups.
- **D-18:** Redaction strategy = redact-friendly Settings record on staging: dummy row with placeholder values (`pixel_id = 000000000000000`, `access_token = REDACTED_FOR_DEMO_DO_NOT_USE`). Screenshot the dummy row. No image post-processing overlay required. Plan 05-12 explicitly verifies the dummy row is in place before screenshot capture.
- **D-19:** Screenshots live at `docs/screenshots/01-settings.png ... 05-twig-api.png`. README references them by relative path. plugin.yaml does not embed screenshot refs (October backend does not consume them).
- **D-20:** Plugin icon = keep existing `icon-bullseye` Font Awesome reference in `plugin.yaml`. October backend renders the FA glyph natively. Zero binary asset commitment. MKT-03 PNG-icon line satisfied if marketplace listing later requires PNG — punt to that point.

### Release-tag flow + public flip

- **D-21:** v2.0.0 ships as **annotated tag from master HEAD** at the smoke-validated commit. `git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"`. Push tag after all Phase 5 plans green. Single source of truth. No release branch, no v2.x maintenance branch (cut later when v2.0.x patch surfaces).
- **D-22:** CHANGELOG.md = **fresh v2.0.0 initial release entry only**. ZERO v1.x diff text. No "vs legacy/v1.1.1" references. No "breaking changes from v1.x" subsection. Treat repo as net-new public artifact. Single `## [2.0.0] - YYYY-MM-DD` section with `### Added` subsection enumerating Phase 1-5 deliverables.
- **D-23:** Plan 05-11 strips ALL v1.x references from `.planning/` docs, `lang/en/lang.php`, `lang/lv/lang.php`, `Plugin.php` docblocks, `ROADMAP.md` (MKT-04 wording currently says "v1.1.1 + legacy/v1.1.1 branch preserved" — rewrite to "v2.0.0 annotated tag from master"), `REQUIREMENTS.md` (MKT-04 same), any class-level PHPDoc citing Phase N or legacy semantics. Net effect: a reader of the public repo finds no trace of v1.x.
- **D-24:** `legacy/v1.1.1` branch + `v1.1.1` tag stay **local-only** as personal archive. NEVER pushed to origin. Confirmed via `git ls-remote --tags origin 'v1*'` returns empty. Operator retains `git checkout legacy/v1.1.1` inspection capability.
- **D-25:** Publishing flow = repo flipped public at v2.0.0 launch (current state: private GitHub repo, master + tag local + remote). Buyer install path = composer VCS without auth: `{"repositories":[{"type":"vcs","url":"https://github.com/logingrupa/oc-metapixel-plugin"}]}` then `composer require logingrupa/oc-metapixel-plugin`. NOT Packagist (defer to post-launch if buyer demand surfaces).
- **D-26:** Pre-flip-public security sweep (plan 05-13) scope = (1) secrets in git history (`git log -p | grep -iE 'pixel_id|access_token|capi_access_token'`) — any hit triggers `git filter-repo` history rewrite BEFORE flip; (2) internal hostnames + IPs in `.planning/` docs (`grep -r 'your-staging-host.example\|forge.laravel.com\|10\.\|192\.168\.' .planning/`) — redact or generalize. Theme PII NOT in scope (theme is separate private repo). Application logs NOT in scope (DB-only, never committed).
- **D-27:** `.planning/` directory ships **in the public repo** (after sweep). Shows GSD workflow rigor to marketplace audience + helps collaborators. NOT `.gitignore`-d. NOT split into separate repo. Sweep removes operator-specific infra refs.

### Claude's Discretion

- Plan-level sequencing within the 14-plan Phase 5 (waves, parallelism). Operator locked sequence above is the dependency order; planner may merge adjacent plans (e.g., 05-04 PixelHead + 05-05 UAT gate as one plan with internal checkpoint) if waves stay clean.
- Exact `composer.json` `keywords` array for marketplace discoverability — planner picks from `meta-pixel`, `conversions-api`, `capi`, `october-cms`, `shopaholic`, `tracking`, `analytics`.
- README ordering of FailedEvents section (before or after Troubleshoot) — both defensible.
- CHANGELOG date format (YYYY-MM-DD vs ISO 8601 timestamp).
- Exact wording of UAT gate pass/fail criteria — planner drafts; operator reviews.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 5 scope + requirements
- `.planning/ROADMAP.md` § Phase 5 — Phase goal, depends_on, requirements list (DOCS-01..03 + MKT-01..05), 5 Success Criteria, Plans: TBD
- `.planning/ROADMAP.md` § MKT-04 wording — currently references v1.1.1 preservation; D-23 strips this in plan 05-11
- `.planning/REQUIREMENTS.md` — DOCS-01, DOCS-02, DOCS-03, MKT-01, MKT-02, MKT-03, MKT-04, MKT-05 detailed requirements text
- `.planning/PROJECT.md` § Current Milestone — v2.0.0 marketplace plugin positioning

### Upstream phase contracts (Phase 2-4 outputs Phase 5 documents)
- `plugins/logingrupa/metapixel/classes/adapter/EventSubjectAdapter.php` — 7-method interface (Phase 2 ADAP-01); CUSTOM-ADAPTERS.md anchors here
- `plugins/logingrupa/metapixel/classes/adapter/ValueResolver.php` — 5-method interface (Phase 2 ADAP-02)
- `plugins/logingrupa/metapixel/classes/adapter/AdapterRegistry.php` — `register()` + `resolveFor()` + `resolveByClass()` (Phase 2 ADAP-03); CUSTOM-ADAPTERS register snippet anchors here
- `plugins/logingrupa/metapixel/classes/queue/SendCapiEvent.php` — 3 `Event::fire` hooks (Phase 2 ADAP-04); D-15 hook examples anchor here
- `plugins/logingrupa/metapixel/classes/testing/EventSubjectAdapterContractTestCase.php` — 10 marketplace contract invariants (Phase 2 ADAP-11); D-16 testing section anchors here
- `plugins/logingrupa/metapixel/classes/adapter/shopaholic/ShopaholicOrderAdapter.php` — ShopaholicAdapter (Phase 3); README Shopaholic walkthrough anchors here
- `plugins/logingrupa/metapixel/classes/adapter/theme/ThemeActionAdapter.php` — ThemeActionAdapter (Phase 3); README Theme Twig walkthrough anchors here
- `plugins/logingrupa/metapixel/models/Settings.php` — Multisite `lookupForSite()` (Phase 4 MULT-01..03); README Settings walkthrough anchors here
- `plugins/logingrupa/metapixel/controllers/FailedEvents.php` — Replay + CheckDedup (Phase 4 FAIL-01..03); README FailedEvents section anchors here
- `plugins/logingrupa/metapixel/middleware/EnsureFbpFbcCookies.php` — TrustedHosts + fbclid validation (Phase 4 HOST-01..06); README cookie troubleshooting anchors here

### Plugin manifest + composer
- `plugins/logingrupa/metapixel/plugin.yaml` — current state (Logingrupa author, GitHub homepage, icon-bullseye)
- `plugins/logingrupa/metapixel/composer.json` — current state (`logingrupa/oc-metapixel-plugin` package name, MIT or proprietary license decision pending)
- `plugins/logingrupa/metapixel/CLAUDE.md` — plugin-specific code style + extensibility contract (already documents D-14/D-15 hook ranking)

### Theme legacy strip targets
- `themes/logingrupa-naisstore/` — entire theme tree; D-02 inventory grep target
- `themes/logingrupa-naisstore/webpack.mix.js` — webpack entry; D-02 bundled-pixel case
- `themes/logingrupa-naisstore/assets/js/common.js` — webpack output; D-02 bundled-pixel inspection point

### Smoke environment
- `your-staging-host.example` — Forge-hosted staging-shaped install (D-06); credentials, Forge deploy path, and `test_event_code` setting set out-of-band by operator before plan 05-08 fires

### v1.x archive (referenced only for what NOT to inherit)
- `legacy/v1.1.1` local git branch + `v1.1.1` local git tag — D-24 confirms local-only, never pushed; D-23 strips all references from public surface

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `plugin.yaml` — generic name + description already lang-key-driven via `logingrupa.metapixel::lang.plugin.name|description`. MKT-02 partially satisfied. Plan 05-12 verifies lang values are buyer-friendly (no "v1.x", no "Shopaholic" in primary description).
- `composer.json` — `"name": "logingrupa/oc-metapixel-plugin"` + `"description": "Meta Pixel + Conversions API for OctoberCMS"` already MKT-01-ready. License field reads `"proprietary"` — D-25 public-flip implies license re-evaluation; planner surfaces as discretion item.
- `EventSubjectAdapterContractTestCase` — already shipped Phase 2 with 10 invariants. D-16 doc section embeds usage example referencing this file by FQN.
- `tests/doubles/FakeAdapter.php` + `tests/doubles/FakeValueResolver.php` — doc example can reference these as "what a minimal adapter looks like under test".
- `CHANGELOG.md` — does not exist yet. Plan 05-12 creates fresh.
- `README.md` — does not exist yet. Plan 05-09 creates fresh.
- `docs/CUSTOM-ADAPTERS.md` — does not exist; `docs/` directory does not exist. Plan 05-10 creates both.

### Established Patterns

- **Single-page README convention** — matches Lovata.Toolbox + Lovata.Shopaholic plugin conventions (their READMEs are single-page linear walkthroughs); D-10 aligns.
- **Annotated git tag convention** — matches v1.1.1 precedent (`git tag -a v1.1.1`). D-21 same shape.
- **Lang-key-driven plugin manifest** — already established by Phase 1 namespace rename + Phase 4 lang work. D-23 strip touches `lang/en/lang.php` + `lang/lv/lang.php` for any v1.x or operator-specific values.
- **Markdown table troubleshooting runbook** — `02-VERIFICATION.md` already uses symptom→evidence→fix tables; D-13 inherits the shape.
- **`/gsd-debug` for one-off bugs** — Phase 4 used this for HOST-* incidents; D-09 carries the convention forward.

### Integration Points

- README install command (`composer require logingrupa/oc-metapixel-plugin` + `php artisan october:up`) hits Phase 1 composer + autoload + migration scaffolding.
- README Shopaholic walkthrough hits Phase 3 ShopaholicOrderAdapter; Theme walkthrough hits Phase 3 ThemeActionAdapter + Twig API.
- Smoke Purchase path exercises full Phase 2 + Phase 3 + Phase 4 stack (Settings → ShopaholicAdapter → SendCapiEvent → MetaClient → EventLog → FailedEvents-if-fails).
- Legacy-strip plans modify `themes/logingrupa-naisstore/` (theme is a sibling directory under `themes/`, not under `plugins/logingrupa/metapixel/`). Plan files reference theme paths with absolute project paths.
- v1.x reference strip (plan 05-11) touches files outside the plugin dir: `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`. Coordinates with state-machine `gsd-sdk query commit` semantics.

</code_context>

<specifics>
## Specific Ideas

- **`your-staging-host.example` Forge install** is the canonical smoke environment. Real Lovata + real orders. Settings `test_event_code` routes events to Meta Test Events live view, NOT production Pixel analytics. Operator handles credential setup + place-test-order + refund-after.
- **Public flip = launch event.** Plan 05-14 atomic action: GitHub repo settings → Visibility → Public. Verify Composer VCS install works from an unauthenticated clone immediately after.
- **No legacy mentions anywhere on the public surface.** D-22 + D-23 + D-26 enforce. CHANGELOG starts at v2.0.0 with no rear-view text. README never references "v1.x" or "legacy". `.planning/` docs scrubbed of internal infra + secrets but retain GSD workflow context (planner judges what stays).
- **Operator-confirmed UAT gates between cutover plans.** D-03 + D-05 mandatory. No autonomous cutover. Each gate has a measurable pass criterion (Pixel Helper detects N events, Meta Test Events live view shows N events, EventLog has N rows) documented in the gate plan.
- **Mall preview lives only in docs** (D-14). Reader copies code from `docs/CUSTOM-ADAPTERS.md` markdown block into their own plugin. The plugin itself stays Shopaholic-only at v2.0.0.

</specifics>

<deferred>
## Deferred Ideas

- **MallAdapter as production code** — pulled out of consideration during D-14. Stays v2.1 backlog (MALL-01).
- **AddToCart + Theme Twig API smoke events** — D-07 explicitly defers. Add to post-launch backlog or v2.0.x patch.
- **Video screencast / Loom buyer onboarding asset** — considered for smoke recording, declined in favor of D-08 markdown smoke log. Add to marketing backlog if buyer support load grows.
- **PNG plugin icon for marketplace listing** — D-20 keeps FA `icon-bullseye`. If marketplace listing later requires PNG (current October marketplace UI behavior unverified), add as v2.0.1 patch.
- **Long-lived `v2.x` maintenance branch** — D-21 punts. Cut when first v2.0.x patch surfaces.
- **Packagist publication** — D-25 punts. VCS install path suffices for launch.
- **Docker dry-run as second validation env** — D-06 discarded in favor of single Forge staging. Reconsider if marketplace UX research shows buyers want a docker-compose-up demo.
- **DevTools Network panel as a UAT verifier** — D-05 skipped (Pixel Helper covers same surface). Adopt if Pixel Helper misses an event class.
- **`composer.json` license re-evaluation** — currently `"proprietary"`; public flip implies re-evaluation. Punt to planner discretion in plan 05-14.

</deferred>

---

*Phase: 05-documentation-marketplace-launch*
*Context gathered: 2026-05-21*
