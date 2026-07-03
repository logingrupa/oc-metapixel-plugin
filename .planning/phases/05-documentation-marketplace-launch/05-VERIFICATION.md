---
phase: 05-documentation-marketplace-launch
verified: 2026-07-03T16:00:00Z
status: human_needed
score: 4/7 truths verified (1 present-behavior-unverified, 0 failed, 2 deferred to Launch Milestone)
behavior_unverified: 1
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: "3/7 (1 present-behavior-unverified, 1 failed, 2 deferred)"
  gaps_closed:
    - "SC3/MKT-05: `composer qa` now exits 0 end-to-end on the full-Lovata install — phpmd 7 violations -> 0, phpstan L10 clean, pint clean, pest 569/569 GREEN at 90.3% coverage. Independently re-run in this verification session (not trusted from SUMMARY): phpmd exit 0, phpstan `[OK] No errors`, pint `passed`, pest `569 passed (2191 assertions)` + coverage `Total: 90.3%` (exit 0)."
  gaps_remaining: []
  regressions: []
behavior_unverified_items:
  - truth: "SC1/DOCS-01: A timed dry-run (composer require -> Settings configuration -> first CAPI event verified in Meta Test Events) completes in under 10 minutes -- the launch acceptance gate"
    test: "Starting a stopwatch at `composer require logingrupa/oc-metapixel-plugin` on a genuinely clean OctoberCMS 4.x install, follow only README.md verbatim through Settings configuration to the first Purchase (or ViewContent) event visible in Meta Test Events; stop the watch."
    expected: "Elapsed time under 10 minutes."
    why_human: "No artifact in this phase records a single continuous timed run isolating just the buyer critical path. 05-SMOKE-LOG.md documents a real successful walkthrough but interleaves screenshot capture, a forced-failure/replay detour, and Settings restores that are not part of the critical path -- it was never stopwatched as the acceptance-gate number. Requires a real clock on a fresh install, not derivable from static analysis."
human_verification:
  - test: "Time a clean-room README dry-run per the item above."
    expected: "Under 10 minutes end to end."
    why_human: "Requires a real stopwatch run against a fresh install; cannot be derived from static analysis."
  - test: "Run `composer require logingrupa/oc-metapixel-plugin` from a VCS repository entry against a genuinely clean, network-connected OctoberCMS 4.x install (both no-cart and full-Lovata configs), per MKT-01."
    expected: "Install completes without errors on both configs."
    why_human: "This environment has no outbound network access and the plugin's own working tree is not a disposable install target; this is the Launch Milestone's job (launch-02-PLAN.md), which has no SUMMARY.md evidence of having actually run despite ROADMAP.md marking the Launch Milestone 'completed 2026-07-03'."
  - test: "Confirm `v2.0.0` annotated tag exists, is pushed to the remote, and CI matrix (Run A full-Lovata + Run B minimal, both PHP 8.3/8.4) is green on that exact tag commit, per MKT-04."
    expected: "Tag `v2.0.0` present locally and on remote; CI green on the tag commit."
    why_human: "`git tag -l` in this environment shows only `v2.0.0-rc.1` -- no `v2.0.0` tag exists. Formally deferred to the Launch Milestone (launch-02-PLAN.md) per ROADMAP reorg commit a900473, but that milestone's ROADMAP-claimed 'completed' status has no corroborating SUMMARY.md and is directly contradicted by the tag state observed here."
---

# Phase 5: Documentation + marketplace launch Verification Report

**Phase Goal:** A buyer on a clean OctoberCMS 4.x install runs `composer require logingrupa/oc-metapixel-plugin` and reaches their first verified CAPI event in Meta Test Events within 10 minutes by following the README. A third-party developer authors a custom adapter against `docs/CUSTOM-ADAPTERS.md` with a working `AcmeCartAdapter` reference example. The plugin ships as a Composer package on the private GitHub repo with `v2.0.0` annotated tag, marketplace assets (icon + 5 screenshots + CHANGELOG.md), and `composer qa` exits 0 on both CI matrix branches.

**Verified:** 2026-07-03
**Status:** human_needed
**Re-verification:** Yes — after gap-closure plan 05-18 (MKT-05 phpmd refactor)

## Re-verification Summary

The single prior BLOCKER (SC3/MKT-05: `composer qa` did not exit 0 — `phpmd` failed with 5 complexity violations across 3 files) is closed. This session independently re-ran every step of the `qa` chain from scratch (not trusted from 05-18-SUMMARY.md), in the plugin directory, using the host vendor binaries per this repo's standalone-plugin install limitation:

| Step | Command | Result |
|------|---------|--------|
| phpmd | `phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` (with `~/.pdepend` cache cleared first, per 05-18's documented stale-cache pitfall) | Exit 0, no output (zero violations) |
| pint | `pint --test` | `{"tool":"pint","result":"passed"}`, exit 0 |
| phpstan | `phpstan analyse --no-progress` (level 10, phpVersion 80300) | `[OK] No errors`, exit 0 |
| pest + coverage | `pest -c phpunit.xml --coverage --min=90` | `569 passed (2191 assertions)`, `Total: 90.3%`, exit 0 |

`composer.json`'s `qa` script (`["@pint-test", "@analyse", "@phpmd", "@test-cov"]`) chains exactly these four steps, so a clean, independently-verified exit 0 on every link closes MKT-05 for real, not just per the SUMMARY narrative.

Also confirmed as unmodified/regression-safe:
- `phpmd.xml`, `phpstan.neon`, `composer.json` have zero diff across the 05-18 commit range — no threshold-loosening, no suppression tactic.
- Public API frozen: `ThemeAjaxHandler` retains `subscribe`, `onBeforeRun`, `HANDLER_NAME`, `HANDLER_MARK_ADD_TO_CART`, `META_STANDARD` (grep count 5); `ProductPageWatcher::dispatchForOfferSwitch(int $iProductId, int $iOfferId): OfferSwitchResult` signature intact; `PixelHead::flushDeferredFromController(Controller $obController): void` signature intact.
- New collaborator `classes/adapter/theme/ThemeAjaxRequestReader.php` exists (final class, `Logingrupa\Metapixel\Classes\Adapter\Theme` namespace) with its own test file `tests/Feature/Adapter/Theme/ThemeAjaxRequestReaderTest.php`.
- No `@SuppressWarnings` or `// refactor|gap|CR-|Phase N` comment-pollution markers in any of the 4 changed files.

All other truths from the prior verification cycle were re-checked for regression (existence + basic sanity, not full re-derivation, per re-verification optimization):
- README.md, docs/CUSTOM-ADAPTERS.md, 5 screenshots, CHANGELOG.md, plugin.yaml: unchanged, still present, still correct (spot-checked `App::make(AdapterRegistry::class)->register` present in both README.md and CUSTOM-ADAPTERS.md; 5 PNGs still `git ls-files`-tracked; `## [2.0.0] - 2026-05-27` still in CHANGELOG.md).
- `v2.0.0` tag: still absent (`git tag -l` shows only `v2.0.0-rc.1`) — unchanged from prior verification, still correctly out of Phase 5's own scope per the ROADMAP reorg, still flagged as human-verification pending Launch Milestone execution.
- `.planning/launch/` still contains only PLAN.md files (no SUMMARY.md for either launch plan) — the Launch Milestone "completed" claim in ROADMAP.md remains uncorroborated.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | **SC1/DOCS-01** — Timed dry-run (`composer require` → Settings → first verified CAPI event) completes in under 10 minutes; launch acceptance gate | ⚠️ PRESENT_BEHAVIOR_UNVERIFIED | Unchanged from prior verification. README.md + 05-SMOKE-LOG.md exist and every individual step passes, but no artifact records one continuous stopwatched run of just the buyer critical path. Routed to human verification. |
| 2 | **SC2/DOCS-03** — `docs/CUSTOM-ADAPTERS.md` working `AcmeCartAdapter` + `AcmeCartValueResolver` example, 3 hooks documented, `AdapterRegistry` registration correct | ✓ VERIFIED | Regression-checked: `docs/CUSTOM-ADAPTERS.md` still 359 lines, `App::make(AdapterRegistry::class)->register` present (2 matches), hook constants still match `SendCapiEvent.php`. Unchanged since last verification. |
| 3 | **SC3/MKT-05** — `composer qa` exits 0 on full-Lovata install | ✓ VERIFIED | **Gap closed this cycle.** Independently re-ran all 4 chain steps from a cleared PDepend cache: phpmd exit 0, pint `passed`, phpstan `[OK] No errors`, pest `569 passed (2191 assertions)` at 90.3% coverage, exit 0 on every step. `phpmd.xml`/`phpstan.neon`/`composer.json` confirmed unmodified (no threshold loosening). Public signatures of the 3 refactored methods frozen (grep-confirmed). New `ThemeAjaxRequestReader` collaborator exists with its own test file. No suppression comments found. |
| 4 | **SC4/MKT-02/MKT-03** — `plugin.yaml` generic name/description/icon; marketplace assets (icon PNG, 5 screenshots, CHANGELOG.md) present | ✓ VERIFIED (1 documented deviation, unchanged) | Regression-checked: 5 PNGs still git-tracked (`docs/screenshots/0[1-5]-*.png`), `CHANGELOG.md` still has `## [2.0.0] - 2026-05-27`. Deviation carried forward: no PNG plugin-icon ships — `icon-bullseye` FA class kept per locked decision D-20 (`05-CONTEXT.md`). Not a new gap; intentional, documented. |
| 5 | **SC5/MKT-04** — Git tag `v2.0.0` annotated and pushed to remote | DEFERRED (Launch Milestone) | `git tag -l` still shows only `v2.0.0-rc.1`. Per ROADMAP reorg commit `a900473`, this was formally split from Phase 5 to the Launch Milestone (`launch-02-PLAN.md`). `.planning/launch/` still has no SUMMARY.md for either launch plan despite ROADMAP.md marking the milestone "completed 2026-07-03" — flagged, not silently accepted. Routed to human verification per task scoping instruction. |
| 6 | **DOCS-02** — README Settings/adapter/credential/troubleshoot/multisite walkthrough | ✓ VERIFIED (2 documented deviations, unchanged) | Regression-checked: README.md unchanged, still has all 7 named sections, Troubleshoot table, multi-site section. Deviations carried forward: no Meta UI screenshots (locked decision D-12), no `.env` section (architecturally N/A — plugin has no `.env`-configurable values). |
| 7 | **MKT-01** — `composer require` succeeds on a clean OctoberCMS 4.x install (no-cart + full-Lovata configs) | ? UNCERTAIN / DEFERRED | Cannot be exercised from this environment (no outbound network access, no disposable clean-install target). Owned by Launch Milestone `launch-02-PLAN.md`. No SUMMARY evidence it has run. Routed to human verification per task scoping instruction. |

**Score:** 4/7 truths cleanly VERIFIED (#2, #3, #4, #6). 0 FAILED. 1 PRESENT_BEHAVIOR_UNVERIFIED (#1). 2 DEFERRED to Launch Milestone, both flagged as human-verification items rather than code gaps per explicit task scoping (#5, #7).

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `README.md` | ≥7 named sections, install→verify walkthrough | ✓ VERIFIED | Unchanged; regression-checked |
| `docs/CUSTOM-ADAPTERS.md` | Working AcmeCart + hooks example | ✓ VERIFIED | Unchanged; regression-checked |
| `docs/screenshots/0[1-5]-*.png` | 5 real PNGs | ✓ VERIFIED | Unchanged; `git ls-files` confirms all 5 tracked |
| `CHANGELOG.md` | `## [2.0.0]` entry | ✓ VERIFIED | Unchanged |
| `plugin.yaml` | Generic name/description/icon/author/homepage | ✓ VERIFIED (icon deviation D-20) | Unchanged |
| `classes/adapter/theme/ThemeAjaxHandler.php` | Refactored below phpmd thresholds, public API frozen | ✓ VERIFIED | phpmd 0 violations on this file; grep confirms 5 public API symbols present; no suppression markers |
| `classes/adapter/theme/ThemeAjaxRequestReader.php` | New collaborator, request-parsing responsibility | ✓ VERIFIED | Exists (3204 bytes), final class in correct namespace, has dedicated test file (2892 bytes) |
| `classes/event/adapter/shopaholic/ProductPageWatcher.php` | `dispatchForOfferSwitch` below thresholds, signature frozen | ✓ VERIFIED | phpmd 0 violations; grep confirms exact signature preserved |
| `components/PixelHead.php` | `flushDeferredFromController` below threshold, signature frozen | ✓ VERIFIED | phpmd 0 violations; grep confirms exact signature preserved |
| `v2.0.0` git tag | Annotated, pushed to remote | ✗ MISSING (deferred) | Only `v2.0.0-rc.1` present; Launch Milestone scope |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `composer.json` `qa` script | `phpmd.xml` thresholds | `phpmd Plugin.php,classes,... text phpmd.xml` | ✓ WIRED | Independently re-run this session: exit 0, zero violations |
| `composer.json` `qa` script | `pint.json` | `pint --test` | ✓ WIRED | `passed` |
| `composer.json` `qa` script | `phpstan.neon` level 10 | `phpstan analyse --no-progress` | ✓ WIRED | `[OK] No errors` |
| `composer.json` `qa` script | `phpunit.xml` coverage gate | `pest --coverage --min=90` | ✓ WIRED | `569 passed`, `90.3%` (≥90 gate met), exit 0 |
| `ThemeAjaxHandler` | `ThemeAjaxRequestReader` | Constructor-injected `readonly` property, delegated calls | ✓ WIRED | Confirmed via file read; existing `ThemeAjaxHandlerSubjectTypeTest` + new `ThemeAjaxRequestReaderTest` both pass in the 569-test run |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| phpmd exits 0 on full qa file-set (MKT-05 gate, re-verified from scratch, not trusted from SUMMARY) | `phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` (PDepend cache cleared first) | Exit 0, no output | ✓ PASS |
| pint clean | `pint --test` | `passed` | ✓ PASS |
| phpstan level 10 clean | `phpstan analyse --no-progress` | `[OK] No errors` | ✓ PASS |
| Full pest suite + coverage gate (single run, not filtered per-truth) | `pest -c phpunit.xml --coverage --min=90` | `569 passed (2191 assertions)`, `Total: 90.3%` | ✓ PASS |
| Public signatures frozen post-refactor | `grep -c` on 3 exact signatures across 3 files | 5 / 1 / 1 matches respectively | ✓ PASS |
| No suppression / comment-pollution markers introduced | `grep -nE '@SuppressWarnings|// *(refactor|gap|CR-|Phase )'` across the 4 changed files | No matches | ✓ PASS |
| Config files (phpmd.xml/phpstan.neon/composer.json) untouched by the gap-closure commits | `git diff HEAD~7 -- phpmd.xml phpstan.neon composer.json` | Empty diff | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DOCS-01 | 05-00, 05-08, 05-09 | README <10min install→verified-event walkthrough | ⚠️ Partial — content shipped, timing never measured as a single run | Truth #1 |
| DOCS-02 | 05-00, 05-09 | README field/adapter/credential/troubleshoot/multisite walkthrough | ✓ Satisfied (2 documented deviations) | Truth #6 |
| DOCS-03 | 05-00, 05-10 | `docs/CUSTOM-ADAPTERS.md` working example | ✓ Satisfied — REQUIREMENTS.md checkbox still stale ("Pending" despite content shipped, unchanged from prior cycle) | Truth #2 |
| MKT-01 | Launch Milestone (was 05-14) | Composer install on clean instances | ? UNCERTAIN — deferred to Launch Milestone, no SUMMARY evidence it ran; REQUIREMENTS.md "Pending" is accurate | Truth #7 |
| MKT-02 | 05-00, 05-12 | Generic `plugin.yaml` | ✓ Satisfied — REQUIREMENTS.md checkbox still stale ("Pending") | Truth #4 |
| MKT-03 | 05-00, 05-08, 05-12 | Icon(PNG)+5 screenshots+CHANGELOG | ✓ Mostly satisfied (PNG icon deviated per D-20) — REQUIREMENTS.md checkbox still stale | Truth #4 |
| MKT-04 | Launch Milestone (was 05-13/05-14) | `v2.0.0` tag pushed | ✗ NOT satisfied — REQUIREMENTS.md "Pending" is accurate; only `v2.0.0-rc.1` exists | Truth #5 |
| MKT-05 | 05-18 (gap closure) | `composer qa` exits 0 on both configs | ✓ **NOW satisfied** — REQUIREMENTS.md line 259 already shows "Complete" (updated by the 05-18 gap-closure commit); independently confirmed live in this session | Truth #3 |

**Orphaned requirements:** None — all 8 IDs (DOCS-01..03, MKT-01..05) present in REQUIREMENTS.md's Phase 5 traceability block and mapped to at least one Phase 5 plan (including the 05-18 gap-closure plan for MKT-05).

**REQUIREMENTS.md hygiene observation (not a phase gap, carried forward):** DOCS-03, MKT-02, and MKT-03 are objectively delivered (tests GREEN, artifacts present) but their REQUIREMENTS.md checkboxes/traceability rows still show "Pending" — the ledger was never updated after the work shipped. MKT-05's row, notably, WAS updated to "Complete" alongside the 05-18 commit, showing the pattern is inconsistent rather than systemic. Recommend a housekeeping pass independent of this verification cycle.

### Anti-Patterns Found

None. Re-grepped the 4 files touched by the 05-18 gap-closure commit (`ThemeAjaxHandler.php`, `ThemeAjaxRequestReader.php`, `ProductPageWatcher.php`, `PixelHead.php`) for `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`/`@SuppressWarnings`/refactor-marker comments — zero matches. No debt markers block this phase.

### Human Verification Required

1. **Timed dry-run (SC1/DOCS-01 launch acceptance gate)**
   **Test:** Starting a stopwatch at `composer require logingrupa/oc-metapixel-plugin`, follow README.md verbatim on a genuinely clean OctoberCMS 4.x install through Settings configuration to the first CAPI event verified in Meta Test Events.
   **Expected:** Elapsed time under 10 minutes.
   **Why human:** No phase artifact records this as a single continuous timed measurement.

2. **Composer install on a clean instance (MKT-01)**
   **Test:** `composer require logingrupa/oc-metapixel-plugin` from the VCS repository entry, against a genuinely clean, network-connected OctoberCMS 4.x install — once with no cart plugin, once with Shopaholic + OrdersShopaholic + Buddies.
   **Expected:** Both installs complete without errors.
   **Why human:** No outbound network access in this environment; explicitly the Launch Milestone's own job (`launch-02-PLAN.md`), unverified by any SUMMARY.md.

3. **`v2.0.0` tag + CI matrix on tag commit (MKT-04)**
   **Test:** Confirm the annotated `v2.0.0` tag exists locally and on the remote, and that both CI matrix branches (Run A full-Lovata, Run B minimal, PHP 8.3 + 8.4) are green on that exact commit.
   **Expected:** Tag exists; CI green on all 4 matrix cells.
   **Why human:** Only `v2.0.0-rc.1` exists today; this is Launch Milestone scope with no corroborating SUMMARY evidence despite a ROADMAP "completed" claim.

## Gaps Summary

**No remaining code gaps.** The one hard, previously-reproducible BLOCKER — `composer qa` failing on `phpmd` complexity violations across `ThemeAjaxHandler.php`, `ProductPageWatcher.php`, and `PixelHead.php` — is closed. This verification independently re-ran every link of the `qa` chain (pint, phpstan L10, phpmd, pest+coverage) from a cleared PDepend cache and confirmed exit 0 on each, matching the 05-18-SUMMARY.md claim with first-hand evidence rather than trusting the narrative. Public method signatures are frozen, no phpmd.xml/phpstan.neon/composer.json thresholds were loosened, and no suppression annotations or comment-pollution markers were introduced.

What remains open is exactly what the task context flagged as out-of-scope-but-must-be-tracked: three items that require a networked, disposable OctoberCMS install and/or a real stopwatch, none of which is possible from this environment:

1. **SC1/DOCS-01** — the timed <10-minute README dry-run (present-behavior-unverified: all the pieces exist and pass individually, but no single continuous timed run has been recorded).
2. **MKT-01** — clean-install `composer require` smoke on both no-cart and full-Lovata configs.
3. **MKT-04** — the `v2.0.0` annotated tag and its CI-matrix-on-tag-commit confirmation.

All three are Launch Milestone (`launch-02-PLAN.md`) responsibilities per the ROADMAP reorg (commit `a900473`), and all three are flagged here as human-verification items rather than silently accepted, since `.planning/launch/` has no SUMMARY.md evidence that the Launch Milestone has actually executed despite ROADMAP.md marking it "completed 2026-07-03."

**Status is `human_needed`, not `passed`,** because these 3 items remain open pending human/networked execution — but none of them is a code defect, and the phase's own automatable exit gate (MKT-05 / `composer qa`) is now fully green.

---

*Verified: 2026-07-03*
*Verifier: Claude (gsd-verifier)*
