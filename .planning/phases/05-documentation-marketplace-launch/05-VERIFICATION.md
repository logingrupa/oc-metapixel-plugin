---
phase: 05-documentation-marketplace-launch
verified: 2026-07-03T00:00:00Z
status: gaps_found
score: 3/7 truths verified (1 present-behavior-unverified, 1 failed, 2 deferred to Launch Milestone)
behavior_unverified: 1
overrides_applied: 0
gaps:
  - truth: "SC3/MKT-05: `composer qa` exits 0 on a full-Lovata install"
    status: failed
    reason: "`composer.json` chains `qa` = pint-test → analyse → phpmd → test-cov. `vendor/bin/phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` exits 2 with 5 live violations, so the chain does not exit 0. pint-test and phpstan analyse (level 10) both pass; full pest suite is 561/561 GREEN — only the phpmd step is red."
    artifacts:
      - path: "classes/adapter/theme/ThemeAjaxHandler.php"
        issue: "ExcessiveClassComplexity (55 > 50 threshold); onBeforeRun() Cyclomatic Complexity 10 (at threshold); dispatchViaAdapter() Cyclomatic Complexity 14 (>10) and NPath 1024 (>200)"
      - path: "classes/event/adapter/shopaholic/ProductPageWatcher.php"
        issue: "dispatchForOfferSwitch() Cyclomatic Complexity 11 (>10) and NPath 240 (>200)"
      - path: "components/PixelHead.php"
        issue: "flushDeferredFromController() Cyclomatic Complexity 11 (>10)"
    missing:
      - "Refactor ThemeAjaxHandler::dispatchViaAdapter and ::onBeforeRun to reduce complexity below phpmd.xml thresholds (extract sub-methods per Tiger-Style <70-line rule already in project CLAUDE.md)"
      - "Refactor ProductPageWatcher::dispatchForOfferSwitch similarly"
      - "Refactor PixelHead::flushDeferredFromController similarly (introduced in Phase 6, but still blocks Phase 5's composer-qa success criterion today)"
      - "Re-run `composer qa` end-to-end and confirm exit 0 on the full-Lovata cell before claiming MKT-05 satisfied"
deferred:
  - truth: "SC5/MKT-04: Git tag v2.0.0 annotated and pushed to remote"
    addressed_in: "Launch Milestone (launch-02-PLAN.md)"
    evidence: "ROADMAP.md: 'Launch Milestone (deferred, separate from numbered phases) — Pre-flip security sweep Step B + public repo flip + v2.0.0 annotated tag... Triggered when operator decides to launch; not gated by phase progress.' Plans 05-13/05-14 were formally split out of Phase 5 to Launch Milestone per ROADMAP reorg commit a900473."
  - truth: "MKT-01: composer require succeeds on a clean OctoberCMS install (no-cart + full-Lovata configs)"
    addressed_in: "Launch Milestone (launch-02-PLAN.md)"
    evidence: "launch-02-PLAN.md task list: 'Repo flip public + v2.0.0 annotated tag + composer VCS install smoke from /tmp + CI matrix verify (MKT-01, MKT-04, MKT-05)'."
behavior_unverified_items:
  - truth: "SC1/DOCS-01: A timed dry-run (composer require → Settings configuration → first CAPI event verified in Meta Test Events) completes in under 10 minutes — the launch acceptance gate"
    test: "Starting a stopwatch at `composer require logingrupa/oc-metapixel-plugin` on a genuinely clean OctoberCMS 4.x install, follow only README.md verbatim through Settings configuration to the first Purchase (or ViewContent) event visible in Meta Test Events; stop the watch."
    expected: "Elapsed time under 10 minutes."
    why_human: "No artifact in this phase records a single continuous timed run. 05-SMOKE-LOG.md documents a real, successful end-to-end walkthrough (09:27-10:20 UTC, 2026-07-03) but it deliberately interleaves screenshot capture, a forced-failure/replay detour, and Settings restores that are not part of a buyer's critical path — it was never isolated or stopwatched as the 'under 10 minutes' acceptance gate the ROADMAP names explicitly. The individual steps look fast, but nothing in the phase artifacts asserts the aggregate number."
human_verification:
  - test: "Time a clean-room README dry-run per the item above."
    expected: "Under 10 minutes end to end."
    why_human: "Requires a real stopwatch run against a fresh install; cannot be derived from static analysis."
  - test: "Run `composer require logingrupa/oc-metapixel-plugin` from a VCS repository entry against a genuinely clean, network-connected OctoberCMS 4.x install (both no-cart and full-Lovata configs), per MKT-01."
    expected: "Install completes without errors on both configs."
    why_human: "This environment has no outbound network access and the plugin repo's own working tree is not a disposable install target; requires the actual Launch Milestone execution (launch-02-PLAN.md), which has no SUMMARY evidence of having run yet despite ROADMAP.md marking it 'completed 2026-07-03'."
---

# Phase 5: Documentation + marketplace launch Verification Report

**Phase Goal:** A buyer on a clean OctoberCMS 4.x install runs `composer require logingrupa/oc-metapixel-plugin` and reaches their first verified CAPI event in Meta Test Events within 10 minutes by following the README. A third-party developer authors a custom adapter against `docs/CUSTOM-ADAPTERS.md` with a working `AcmeCartAdapter` reference example. The plugin ships as a Composer package on the private GitHub repo with `v2.0.0` annotated tag, marketplace assets (icon + 5 screenshots + CHANGELOG.md), and `composer qa` exits 0 on both CI matrix branches.

**Verified:** 2026-07-03
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

Truths are the 5 ROADMAP.md "Phase 5" Success Criteria (non-negotiable roadmap contract), cross-referenced against the 8 requirement IDs (DOCS-01..03, MKT-01..05) named in the task scope.

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | **SC1/DOCS-01** — Timed dry-run (`composer require` → Settings → first verified CAPI event) completes in under 10 minutes; this is the launch acceptance gate | ⚠️ PRESENT_BEHAVIOR_UNVERIFIED | README.md walkthrough + `05-SMOKE-LOG.md` both exist and every individual step PASSes, but no artifact records a single continuous stopwatched run of just the buyer critical path (the smoke log's 09:27-10:20 window mixes in screenshot capture, a deliberately-forced failure/replay detour, and Settings restores). Routed to human verification. |
| 2 | **SC2/DOCS-03** — `docs/CUSTOM-ADAPTERS.md` ships a working ~50-LOC `AcmeCartAdapter` + `AcmeCartValueResolver` example documenting `AdapterRegistry` registration, `$require` plugin dependency, and the 3 `Event::fire` hooks | ✓ VERIFIED | `docs/CUSTOM-ADAPTERS.md` read in full: contract interfaces, minimal AcmeCart register snippet, full OFFLINE Mall inline example (`MallOrderAdapter` + `MallOrderValueResolver`), all 3 hook constants (`HOOK_BEFORE_DISPATCH`/`AFTER_DISPATCH`/`DEAD_LETTER`, cross-checked against `classes/queue/SendCapiEvent.php:56-60`), and a `Testing your adapter` section. `CustomAdaptersStructureTest` 8/8 GREEN (behaviorally run, not just read — see Behavioral Spot-Checks). CR-01/WR-01 fix (commit `6dc8402`) confirmed live: both `README.md:177` and `docs/CUSTOM-ADAPTERS.md:106` use `App::make(AdapterRegistry::class)->register(...)` — the real, non-fatal API — with no lingering `AdapterRegistry::instance()->register` or static `AdapterRegistry::register` forms found by grep. |
| 3 | **SC3/MKT-05** — `composer require` succeeds on (a) clean OctoberCMS + no cart plugin, (b) clean OctoberCMS + Shopaholic+OrdersShopaholic+Buddies; `composer qa` exits 0 on both; CI matrix Run A + Run B green on the `v2.0.0` tag commit | ✗ FAILED | `composer qa` = `pint-test → analyse → phpmd → test-cov`. Ran each step directly: `pint --test` → `{"tool":"pint","result":"passed"}`. `phpstan analyse` (level 10) → `[OK] No errors`. `phpmd ... phpmd.xml` → **exit code 2**, 5 violations (`ExcessiveClassComplexity` in `ThemeAjaxHandler`; `CyclomaticComplexity`/`NPathComplexity` in `ThemeAjaxHandler::onBeforeRun`+`dispatchViaAdapter`, `ProductPageWatcher::dispatchForOfferSwitch`, `PixelHead::flushDeferredFromController`). Full pest suite (`tests/Unit`+`tests/Feature`+`tests/Contract`) is 561/561 GREEN with 2171 assertions — the test layer is solid, but the `qa` chain as a whole does not exit 0 today. `ThemeAjaxHandler.php` and `ProductPageWatcher.php` were last touched by this phase's own gap-closure commits (`e72ed42`, `27a460c`, both 2026-07-03T02:xx — plans 05-15/05-16/05-17). The `v2.0.0` tag does not exist yet (see truth #5), so the CI-matrix-on-tag-commit sub-clause cannot even be evaluated. |
| 4 | **SC4/MKT-02/MKT-03** — `plugin.yaml` generic name/description/icon; marketplace assets present: plugin icon (PNG), 5 screenshots, CHANGELOG.md | ✓ VERIFIED (1 documented deviation) | `plugin.yaml`: name/description are lang keys, `author: Logingrupa`, `homepage: https://github.com/logingrupa/oc-metapixel-plugin`. 5 real PNG files confirmed via `file`: `01-settings.png` (1440×900), `02-failed-events-list.png`/`03-replay-flow.png`/`04-check-dedup.png` (2200×950), `05-twig-api.png` (1440×900) — all committed (`git ls-files docs/screenshots/`, commit `d94d59a`). `CHANGELOG.md` has `## [2.0.0] - 2026-05-27` + `### Added`. `PluginYamlSanityTest` 6/6 GREEN, `AssetsExistTest` 5/5 GREEN (behaviorally run). **Deviation:** no PNG plugin-icon file ships anywhere in the repo — `plugin.yaml` keeps the Font Awesome `icon-bullseye` class instead. This is a locked, documented decision (`05-CONTEXT.md` D-20: "MKT-03 PNG-icon line satisfied if marketplace listing later requires PNG — punt to that point"; also `05-DISCUSSION-LOG.md` "User's choice: Keep icon-bullseye"), not an oversight. |
| 5 | **SC5/MKT-04** — Git tag `v2.0.0` annotated and pushed to remote | DEFERRED (see below) | `git tag -l` shows only `v2.0.0-rc.1`; no `v2.0.0` tag exists locally. Per ROADMAP reorg commit `a900473`, this work was formally split out of Phase 5's plan list into a separate "Launch Milestone" (`launch-02-PLAN.md`, MKT-01/MKT-04/MKT-05). Per explicit scoping instruction for this verification run, the absence of 05-13/05-14 from Phase 5's own plan list is not a Phase-5 gap. **However:** ROADMAP.md marks the Launch Milestone "completed 2026-07-03", and `.planning/launch/` contains only the two PLAN.md files (`launch-01-PLAN.md`, `launch-02-PLAN.md`) plus `launch-01-SECURITY-SWEEP.md` — **no SUMMARY.md exists for either launch plan**, and the tag state directly contradicts a "completed" claim. This is flagged as a deferred item with a caveat, not silently accepted. |
| 6 | **DOCS-02** — README includes Settings field walkthrough, Shopaholic + Theme adapter setup, Pixel/CAPI credential acquisition (with Meta UI screenshots per literal REQUIREMENTS.md wording), `.env` variable reference, troubleshooting runbook keyed to `Log::*`, multi-site routing setup | ✓ VERIFIED (1 documented deviation) | README.md has all 7 required H2 sections (`ReadmeStructureTest::test_readme_contains_seven_named_sections` GREEN), an 8-row Troubleshoot table with verbatim `Log::*`/exception-context signatures (spot-checked several against `classes/`), and a "Multi-site routing" section describing the `$propagatable=[]` isolation guarantee. Every `field.*_label` value from `lang/en/lang.php` appears verbatim in the README (`ReadmeStructureTest::test_readme_anchors_field_labels_from_lang_en` GREEN). **Deviations, both intentional and documented:** (a) credential acquisition is plain numbered text with **no Meta UI screenshots** — locked decision D-12 in `05-CONTEXT.md` ("Survives Meta UI redesigns. Higher friction for non-marketers accepted."), directly overriding the literal REQUIREMENTS.md DOCS-02 wording; (b) there is no ".env variable reference" section — the README instead states config is 100% backend-Settings-driven and secrets are never placed in `.env`, which is architecturally accurate (this plugin has no `.env`-configurable values) rather than a missed requirement. |
| 7 | **MKT-01** — `composer require logingrupa/oc-metapixel-plugin` succeeds on a clean OctoberCMS 4.x install (no-cart config and full-Lovata config) | ? UNCERTAIN / DEFERRED | Cannot be exercised from this environment (no outbound network access, no disposable clean-install target). Owned by Launch Milestone `launch-02-PLAN.md` per the ROADMAP reorg. No SUMMARY evidence it has actually run. |

**Score:** 3/7 truths cleanly VERIFIED (#2, #4, #6 — each with a documented, intentional deviation noted, not a gap). 1 FAILED (#3 — `composer qa` does not exit 0 today, a hard, currently-reproducible defect). 1 PRESENT_BEHAVIOR_UNVERIFIED (#1). 2 DEFERRED to Launch Milestone (#5, #7), flagged with a caveat about that milestone's unverified completion claim.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `README.md` | ≥7 named sections, install→verify walkthrough, no v1.x refs | ✓ VERIFIED | 202 lines; `ReadmeStructureTest` 6/6 GREEN |
| `docs/CUSTOM-ADAPTERS.md` | Working AcmeCart + OFFLINE Mall examples, 3 hooks, contract-test section | ✓ VERIFIED | 359 lines; `CustomAdaptersStructureTest` 8/8 GREEN; CR-01 fix confirmed live |
| `docs/screenshots/0[1-5]-*.png` | 5 real PNGs, dummy-values-only | ✓ VERIFIED | All 5 confirmed as real PNG images via `file`; git-tracked (commit `d94d59a`) |
| `CHANGELOG.md` | Keep-a-Changelog `## [2.0.0] - YYYY-MM-DD` + `### Added`, no v1.x diff text | ✓ VERIFIED | `AssetsExistTest` 5/5 GREEN |
| `plugin.yaml` | Generic name/description/icon/author/homepage | ✓ VERIFIED (icon deviation D-20) | `PluginYamlSanityTest` 6/6 GREEN |
| Plugin icon PNG | Marketplace icon asset (PNG) | ✗ MISSING (accepted deviation) | No PNG file anywhere in repo; `icon-bullseye` FA class kept instead per locked decision D-20 |
| `v2.0.0` git tag | Annotated, pushed to remote | ✗ MISSING | Only `v2.0.0-rc.1` present; deferred to Launch Milestone |
| `tests/Feature/Docs/*Test.php` + `tests/Feature/Plugin/PluginYamlSanityTest.php` | 4 Wave-0 gate files, all flipped GREEN | ✓ VERIFIED | 30/30 assertions GREEN when actually executed (see Behavioral Spot-Checks) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `README.md:177` (custom-adapter registration snippet) | `classes/adapter/AdapterRegistry.php` | `App::make(AdapterRegistry::class)->register(...)` | ✓ WIRED | `AdapterRegistry::register()` is a real instance method (confirmed by reading the class); the documented call shape is executable, matching `Plugin.php`'s own internal usage |
| `docs/CUSTOM-ADAPTERS.md:106` (AcmeCart minimal snippet) | `classes/adapter/AdapterRegistry.php` | Same container-resolved form | ✓ WIRED | Same as above; both docs consistent post-fix |
| `docs/CUSTOM-ADAPTERS.md` hook constants | `classes/queue/SendCapiEvent.php:56-60` | Literal string match `metapixel.event.before_dispatch` / `after_dispatch` / `dead_letter` | ✓ WIRED | grep-confirmed all 3 constants exist verbatim in `SendCapiEvent.php` and are documented identically in the doc |
| `README.md` field labels | `lang/en/lang.php` `field.*_label` | Verbatim string containment | ✓ WIRED | `ReadmeStructureTest::test_readme_anchors_field_labels_from_lang_en` (GREEN) programmatically enforces this on every run |
| `composer.json` `qa` script | `phpmd.xml` thresholds | `phpmd Plugin.php,classes,... text phpmd.xml` | ✗ NOT WIRED (currently red) | Chain step returns exit 2; downstream `test-cov` step in the `qa` script never runs when invoked as a single `composer qa` |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Wave-0 doc/manifest gate tests actually pass (not just "exist") | `vendor/bin/pest -c plugins/logingrupa/metapixel/phpunit.xml --filter='ReadmeStructure\|CustomAdapters\|AssetsExist\|PluginYamlSanity\|NoV1xReferences'` (run from repo root, since project `vendor/bin/pest` — contrary to the task's context note — IS installed and runnable) | 30/30 tests passed, 335 assertions | ✓ PASS |
| Full plugin test suite has no regressions from Phase 5's gap-closure waves | `vendor/bin/pest -c plugins/logingrupa/metapixel/phpunit.xml` (full run, once) | 561/561 tests passed, 2171 assertions, 27.86s | ✓ PASS |
| `composer qa` pint step | `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` | ✓ PASS |
| `composer qa` phpstan step | `vendor/bin/phpstan analyse --no-progress` (level 10, phpVersion 80300) | `[OK] No errors` | ✓ PASS |
| `composer qa` phpmd step | `vendor/bin/phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` | Exit code 2, 5 violations listed | ✗ FAIL |
| CR-01/WR-01 fix verified live (not just SUMMARY claim) | `grep -n "AdapterRegistry::instance\|AdapterRegistry::register(" README.md docs/CUSTOM-ADAPTERS.md` | No matches for either broken form; both docs use `App::make(AdapterRegistry::class)->register` | ✓ PASS |

Note: the task's context note stated `vendor/bin/pest` was "NOT installed on this server." This was found to be inaccurate for this environment — the repo-root `vendor/bin/pest` (4.7.0) runs successfully against the plugin's own `phpunit.xml`. All test-based claims above are therefore first-hand behavioral evidence, not static-only inference.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DOCS-01 | 05-00, 05-08, 05-09 | README <10min install→verified-event walkthrough | ⚠️ Partial — content shipped and structurally gated, timing never measured as a single run | Truth #1 |
| DOCS-02 | 05-00, 05-09 | README field/adapter/credential/troubleshoot/multisite walkthrough | ✓ Satisfied (2 documented deviations: no Meta screenshots per D-12; no `.env` section, architecturally N/A) | Truth #6 |
| DOCS-03 | 05-00, 05-10 | `docs/CUSTOM-ADAPTERS.md` working example | ✓ Satisfied — **REQUIREMENTS.md checkbox is stale** (still shows `[ ]`/"Pending" despite content shipped and tests GREEN) | Truth #2 |
| MKT-01 | Launch Milestone (was 05-14) | Composer install on clean instances | ? UNCERTAIN — deferred to Launch Milestone, no SUMMARY evidence it ran; REQUIREMENTS.md still "Pending" (accurate) | Truth #7 |
| MKT-02 | 05-00, 05-12 | Generic `plugin.yaml` | ✓ Satisfied — **REQUIREMENTS.md checkbox is stale** ("Pending" despite `PluginYamlSanityTest` 6/6 GREEN) | Truth #4 |
| MKT-03 | 05-00, 05-08, 05-12 | Icon(PNG)+5 screenshots+CHANGELOG | ✓ Mostly satisfied (PNG icon deviated per D-20) — **REQUIREMENTS.md checkbox is stale** | Truth #4 |
| MKT-04 | Launch Milestone (was 05-13/05-14) | `v2.0.0` tag pushed | ✗ NOT satisfied — REQUIREMENTS.md "Pending" is accurate; only `v2.0.0-rc.1` exists | Truth #5 |
| MKT-05 | Launch Milestone (was 05-14), but the `composer qa`-exits-0 sub-clause is a live Phase-5 code-quality fact | `composer qa` exits 0 on both configs, CI green on tag | ✗ NOT satisfied — REQUIREMENTS.md "Pending" is accurate; `phpmd` fails today | Truth #3 |

**Orphaned requirements:** None — all 8 IDs named in scope (DOCS-01..03, MKT-01..05) are present in REQUIREMENTS.md's Phase 5 traceability block (lines 252-259) and mapped to at least one Phase 5 plan.

**REQUIREMENTS.md hygiene observation (not a phase gap):** DOCS-03, MKT-02, and MKT-03 are objectively delivered (tests GREEN, artifacts present) but REQUIREMENTS.md's own checkboxes (lines 105, 109-113) and traceability table (lines 254-258) still show them unchecked/"Pending" — the requirements ledger was never updated after the work shipped. Recommend a housekeeping pass on REQUIREMENTS.md independent of this gap-closure cycle.

### Anti-Patterns Found

None. Grepped `README.md`, `CHANGELOG.md`, `docs/CUSTOM-ADAPTERS.md`, and the phpmd-flagged production files for `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`/"coming soon"/"not yet implemented" — zero matches. No debt markers block this phase.

### Human Verification Required

1. **Timed dry-run (SC1/DOCS-01 launch acceptance gate)**
   **Test:** Starting a stopwatch at `composer require logingrupa/oc-metapixel-plugin`, follow README.md verbatim on a genuinely clean OctoberCMS 4.x install through Settings configuration to the first CAPI event verified in Meta Test Events.
   **Expected:** Elapsed time under 10 minutes.
   **Why human:** No phase artifact records this as a single continuous timed measurement; `05-SMOKE-LOG.md` documents a real successful walkthrough but interleaves unrelated screenshot/replay/restore steps and was never stopwatched end-to-end against the 10-minute gate.

2. **Composer install on a clean instance (MKT-01)**
   **Test:** `composer require logingrupa/oc-metapixel-plugin` from the VCS repository entry, against a genuinely clean, network-connected OctoberCMS 4.x install — once with no cart plugin, once with Shopaholic + OrdersShopaholic + Buddies.
   **Expected:** Both installs complete without errors.
   **Why human:** This verification environment has no outbound network access and no disposable install target; this is explicitly the Launch Milestone's own job (`launch-02-PLAN.md`), which has no SUMMARY evidence of having actually executed despite ROADMAP.md's "completed 2026-07-03" claim.

## Gaps Summary

**One hard, currently-reproducible blocker:** `composer qa` — the exact command REQUIREMENTS.md's MKT-05 and ROADMAP Success Criterion 3 name as the acceptance bar — does not exit 0. `pint-test` and `phpstan analyse` (level 10) are both clean, and the full 561-test Pest suite is fully GREEN, but the `phpmd` step in the chain fails with 5 complexity violations in `ThemeAjaxHandler.php`, `ProductPageWatcher.php`, and `PixelHead.php`. The first two files were last touched by this phase's own gap-closure work (05-15/05-16/05-17, commits `e72ed42`/`27a460c`, 2026-07-03) closing the D-07 browser-AddToCart-dedup and per-view-ViewContent gaps — the refactors that fixed those functional gaps pushed method complexity over the `phpmd.xml` thresholds and nobody re-ran `composer qa` end-to-end afterward to catch it. `PixelHead.php`'s violation traces to Phase 6 (already marked complete) but still blocks Phase 5's own stated exit criterion today, since `phpmd` scans the whole `classes/`+`components/` tree regardless of which phase last touched a given file.

Everything else that could be verified from this environment is in good shape: the marketplace documentation surface (README.md, docs/CUSTOM-ADAPTERS.md) is accurate, behaviorally test-gated, and the critical CR-01/WR-01 `AdapterRegistry` registration-API defect from the code review is fixed and confirmed live in both public docs. The 5 screenshots are real, leak-checked (per the smoke log), and committed. CHANGELOG.md and plugin.yaml are marketplace-shaped. Two items (`v2.0.0` tag, live composer-install smoke) are legitimately out of this phase's scope per the explicit ROADMAP reorg that moved them to the Launch Milestone — but that milestone's ROADMAP-claimed "completed 2026-07-03" status has zero SUMMARY evidence and is directly contradicted by the tag state (`v2.0.0-rc.1`, not `v2.0.0`), so it is flagged rather than silently trusted.

**To close this phase:** refactor the 3 flagged methods below phpmd's complexity thresholds (or adjust `phpmd.xml` thresholds with an explicit, documented rationale if the complexity is judged acceptable), then re-run `composer qa` end-to-end and confirm exit 0 before re-verifying.

---

*Verified: 2026-07-03*
*Verifier: Claude (gsd-verifier)*
