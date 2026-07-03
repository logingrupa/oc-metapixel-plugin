---
phase: 05-documentation-marketplace-launch
verified: 2026-07-04T00:05:00Z
status: passed
score: 6/8 truths verified (1 present-behavior-unverified deferred to Launch Milestone, 1 deferred to Launch Milestone, 0 failed)
behavior_unverified: 1
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: "5/8 (1 present-behavior-unverified, 1 deferred, 1 failed)"
  gaps_closed:
    - "ROADMAP.md launch-02 bookkeeping regression (3rd recurrence) fixed by commit `10ac972` and independently re-verified this session: ROADMAP.md:405 launch-02 bullet reads `- [ ]` with the `(completed 2026-07-03)` suffix removed (one-line diff confirmed via `git show 10ac972` — only .planning/ROADMAP.md touched, no code delta); launch-01 stays `[x]` (truthful — sweep log COMPLETE, verified in a prior cycle). All three consistency anchors now agree with on-disk reality: bullet `[ ]` (:405), `**Plans:** 1/2` header (:402), progress row `Launch Milestone | 1/2 | Deferred — tag awaits operator LAUNCH SCHEDULED` (:419), and `git tag -l` / `git ls-remote --tags origin` confirm no `v2.0.0` anywhere."
  gaps_remaining: []
  regressions: []
deferred:
  - truth: "SC5/MKT-04: Git tag `v2.0.0` annotated and pushed to remote, CI green on the tag commit"
    addressed_in: "Launch Milestone (launch-02-PLAN.md)"
    evidence: "ROADMAP.md:398 'Launch Milestone (deferred, separate from numbered phases) — ... Triggered when operator decides to launch — gated by Phase 5 close + Phase 6 ship + operator readiness.' ROADMAP.md:405 launch-02 bullet owns 'v2.0.0 annotated tag + composer VCS install smoke + CI-green-on-tag verify (MKT-01, MKT-04)', resume signal `LAUNCH SCHEDULED`. All phase-controllable prerequisites met: CI matrix green on last-pushed commit, `composer qa` green locally on current HEAD, bookkeeping truthful after `10ac972`."
  - truth: "SC1/DOCS-01: A timed dry-run (composer require -> Settings configuration -> first CAPI event verified in Meta Test Events) completes in under 10 minutes — the launch acceptance gate"
    addressed_in: "Launch Milestone (launch-02-PLAN.md Step F.2/F.3 + operator timed gate)"
    evidence: "launch-02-PLAN.md:49 — 'DOCS-01 timed dry-run can run independently AFTER this plan as the launch acceptance gate.' launch-02-PLAN.md:332 (success criteria) — 'DOCS-01 timed dry-run can now be executed independently as the launch acceptance gate (operator timer ≤10 min — separate manual gate, NOT this plan's responsibility but tracked in the launch log if performed).' Step F.2 owns the README verbatim `-W` re-verify on a fresh clean-room October root post-tag (closes UAT test 7 defect (1)); Step F.3 drops the `:dev-master` pre-release note once the stable tag resolves. 05-22-SUMMARY.md coverage item D5 (human_judgment: true) schedules the timed re-run 'verbatim after the v2.0.0 tag push — cannot be automated pre-tag while the remote is tagless.' Same deferral class as the tag itself: the verbatim stable-command measurement is structurally impossible until launch-02 pushes the tag."
behavior_unverified_items:
  - truth: "SC1/DOCS-01: A timed dry-run (composer require -> Settings configuration -> first CAPI event verified in Meta Test Events) completes in under 10 minutes — the launch acceptance gate"
    test: "Starting a stopwatch at `composer require logingrupa/oc-metapixel-plugin -W` on a genuinely clean OctoberCMS 4.x install (post-tag, per launch-02 Step F.2), follow only README.md verbatim through Settings configuration to the first event visible in Meta Test Events; stop the watch. Track the result in the launch log per launch-02-PLAN.md:332."
    expected: "Elapsed time under 10 minutes."
    why_human: "Requires a live clock against a fresh install; not derivable from static analysis. The three README-verbatim defects the 2026-07-03 live dry-run found (deprecated `october:up` no-op, missing `[pixelHead]` INI declaration, tagless-remote install failure) are fixed in code by 05-22 (commits d4a733a/7b8c124/16e1e07) and gate-locked by ReadmeStructureTest (8/8 GREEN, re-run live). UAT test 7's gap was adjudicated and closed through the UAT flow; that live run also proved the full pipeline works end-to-end (~9-10 min estimated net buyer time) — only the single continuous stopwatched measurement remains, and the ROADMAP/launch-02 explicitly assign it to the Launch Milestone as the operator's launch acceptance gate. Recorded here so it survives phase closure; NOT a phase-5 blocker."
---

# Phase 5: Documentation + marketplace launch Verification Report

**Phase Goal:** A buyer on a clean OctoberCMS 4.x install runs `composer require logingrupa/oc-metapixel-plugin` and reaches their first verified CAPI event in Meta Test Events within 10 minutes by following the README. A third-party developer authors a custom adapter against `docs/CUSTOM-ADAPTERS.md` with a working `AcmeCartAdapter` reference example. The plugin ships as a Composer package on the private GitHub repo with `v2.0.0` annotated tag, marketplace assets (icon + 5 screenshots + CHANGELOG.md), and `composer qa` exits 0 on both CI matrix branches.

**Verified:** 2026-07-04
**Status:** passed
**Re-verification:** Yes — sixth verification cycle, after the `10ac972` fix for the launch-02 bookkeeping regression this verification found in cycle five

**Scope note on goal wording:** the goal text says "private GitHub repo" but the repo is public — ROADMAP evolution (the Launch Milestone explicitly planned/executed the public flip with REDACT-FIRST, sweep log COMPLETE), not a defect. Not counted against the phase.

## Re-verification Summary

This is the sixth verification cycle. Every claim below was checked first-hand against current HEAD (`10ac972`), not trusted from SUMMARYs:

| Item | Finding |
|------|---------|
| **Cycle-5 blocking gap: launch-02 bookkeeping regression** | **CLOSED by `10ac972`, independently re-verified.** `git show 10ac972`: single-line diff, only `.planning/ROADMAP.md` touched — `- [x] ... (completed 2026-07-03)` → `- [ ]` with suffix removed, rest of the bullet verbatim. Live state re-read: ROADMAP.md:405 `[ ]`, :402 `Plans: 1/2`, :419 `Launch Milestone | 1/2 | Deferred` — all internally consistent and consistent with the tag state (`git tag -l`: only `v1.1.1` + `v2.0.0-rc.1`; `git ls-remote --tags origin`: empty). launch-01 correctly stays `[x]` (sweep log COMPLETE, substance verified in a prior cycle). |
| UAT test 7 gap-closure (05-22) | **Code-level fix confirmed live (cycle 5, unchanged by `10ac972`).** Zero `october:up` occurrences in README; `october:migrate` at all 3 required locations; `[pixelHead]` INI declaration documented at 2 locations; `:dev-master -W` pre-release fallback at 2 locations. `ReadmeStructureTest` 8/8 GREEN (21 assertions). Code review (`05-REVIEW.md`) independently confirmed all three technical claims against OctoberCMS core source — 0 critical findings. |
| SC1 timed stopwatch dry-run | **Reclassified `human_needed` → DEFERRED (Launch Milestone) per Step 9b**, with explicit later-milestone evidence: launch-02-PLAN.md:49 + :332 name the DOCS-01 timed dry-run as the launch acceptance gate executed at/after launch-02, Step F.2/F.3 own the post-tag README verbatim re-verify, and 05-22-SUMMARY D5 (human_judgment: true) schedules the timed re-run post-tag ("cannot be automated pre-tag while the remote is tagless"). The verbatim stable-command measurement is structurally impossible until the tag exists — same deferral class as the tag push itself. The truth remains ⚠️ PRESENT_BEHAVIOR_UNVERIFIED (excluded from the verified count) and is preserved in `behavior_unverified_items` so it survives phase closure. |
| `composer qa` full chain | **Re-run from scratch in cycle 5 on the same source tree** (`10ac972` touched only ROADMAP.md — no code delta, results carry): phpmd exit 0, pint passed, phpstan L10 `[OK] No errors`, pest `587 passed (2239 assertions)` at **90.5%** coverage. |
| CI matrix currency | Green on last-pushed commit (`4a3b4a0`, run `28675470920`, confirmed via `gh run list`); local HEAD now 9 commits ahead, unpushed. Locally reproduced `composer qa` green covers the delta (which is planning-docs + one doc-gate test rename + README text). Currency note, not a functional failure. |
| `docs/CUSTOM-ADAPTERS.md`, assets, `plugin.yaml`, REQUIREMENTS.md | Unchanged; re-confirmed green in cycle 5: `CustomAdaptersStructureTest` 8/8, `AssetsExistTest` 5/5, 5 screenshots on disk, `CHANGELOG.md` `## [2.0.0]`, MKT-04 row `[ ]` Pending with operator-gate note. |

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | **SC1/DOCS-01** — Timed dry-run (`composer require` → Settings → first verified CAPI event) completes in under 10 minutes; launch acceptance gate | ⚠️ PRESENT_BEHAVIOR_UNVERIFIED → DEFERRED (Launch Milestone) | All three defects the live dry-run found are fixed and gate-locked (`ReadmeStructureTest` 8/8 live); pipeline proven end-to-end by the 2026-07-03 live run (~9-10 min net); the single continuous stopwatch measurement is explicitly owned by launch-02 (:49, :332, Step F.2) and 05-22-SUMMARY D5, post-tag. Not counted as verified; not a phase blocker. |
| 2 | **SC2/DOCS-03** — `docs/CUSTOM-ADAPTERS.md` working `AcmeCartAdapter` + `AcmeCartValueResolver` example, 3 hooks documented, `AdapterRegistry` registration + `$require` pattern correct | ✓ VERIFIED | `CustomAdaptersStructureTest` 8/8 GREEN (15 assertions); contents spot-checked in prior cycles. |
| 3 | **SC3/MKT-05** — `composer qa` exits 0; CI matrix green on both branches | ✓ VERIFIED | All 4 qa steps run live, exit 0 each (587 tests, 90.5% coverage). CI 4/4 matrix cells green on last-pushed commit. |
| 4 | **SC4/MKT-02/MKT-03** — `plugin.yaml` generic name/description/icon; marketplace assets (icon, 5 screenshots, CHANGELOG.md) present | ✓ VERIFIED (1 documented deviation) | `AssetsExistTest` 5/5 GREEN; 5 PNGs on disk; `CHANGELOG.md` `## [2.0.0]`. Deviation D-20 (FA icon vs PNG) carried forward, intentional. |
| 5 | **SC5/MKT-04** — Git tag `v2.0.0` annotated and pushed | ⏸ DEFERRED (Launch Milestone) | Tag genuinely absent — by design, owned by launch-02, gated on operator `LAUNCH SCHEDULED` (ROADMAP:398/:405). All phase-controllable prerequisites met. |
| 6 | **DOCS-02** — README Settings/adapter/credential/troubleshoot/multisite walkthrough | ✓ VERIFIED (2 documented deviations) | All 7 named sections + Troubleshoot table + multi-site section present, gate-locked. Deviations D-12 (no Meta UI screenshots) + `.env` N/A carried forward. |
| 7 | **MKT-01** — `composer require` succeeds on a clean OctoberCMS 4.x install (no-cart + full-Lovata configs) | ✓ VERIFIED (via UAT execution evidence) | 05-UAT.md test 8: disposable-scratchpad clean install, both configs, version-specific evidence, 5 migrations green, PluginGuard degrades gracefully. |
| 8 | **05-20-PLAN.md must_have** — ROADMAP.md launch-milestone bookkeeping accurately reflects deferred state; no `(completed ...)` suffix on an unexecuted deliverable | ✓ VERIFIED (fixed by `10ac972`) | :405 `[ ]` no suffix; :402 `Plans: 1/2`; :419 `1/2 Deferred` — all consistent with each other and with `git tag -l` / `git ls-remote --tags origin` (no `v2.0.0`). launch-01 `[x]` truthful. |

**Score:** 6/8 truths VERIFIED (#2, #3, #4, #6, #7, #8). 0 FAILED. 1 PRESENT_BEHAVIOR_UNVERIFIED, deferred to Launch Milestone (#1). 1 DEFERRED by design (#5).

### Deferred Items

Items not yet met but explicitly addressed in the later Launch Milestone — not actionable Phase 5 gaps.

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | `v2.0.0` annotated tag + CI-green-on-tag verify (SC5/MKT-04) | Launch Milestone (`launch-02-PLAN.md`) | ROADMAP.md:398 (milestone deferred by design, operator-triggered); ROADMAP.md:405 bullet owns "MKT-01, MKT-04", resume signal `LAUNCH SCHEDULED`. |
| 2 | Timed ≤10-min README stopwatch dry-run (SC1/DOCS-01 launch acceptance gate) | Launch Milestone (`launch-02-PLAN.md` :49, :332, Step F.2/F.3) + 05-22-SUMMARY D5 | launch-02-PLAN.md:49 "DOCS-01 timed dry-run can run independently AFTER this plan as the launch acceptance gate"; :332 "operator timer ≤10 min — separate manual gate ... tracked in the launch log if performed"; Step F.2 post-tag verbatim `-W` re-verify; 05-22-SUMMARY D5 human_judgment gate "cannot be automated pre-tag while the remote is tagless". Also preserved in `behavior_unverified_items` frontmatter so it survives phase closure. |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `README.md` | ≥7 named sections, fresh-install-executable walkthrough, october:migrate + [pixelHead] INI + dev-master fallback (05-22 delta) | ✓ VERIFIED | `ReadmeStructureTest` 8/8 GREEN live; grep: zero `october:up`, `[pixelHead]` ×2, `:dev-master -W` ×2 |
| `docs/CUSTOM-ADAPTERS.md` | Working AcmeCart + hooks + `$require` pattern | ✓ VERIFIED | `CustomAdaptersStructureTest` 8/8 GREEN |
| `docs/screenshots/0[1-5]-*.png` | 5 real PNGs | ✓ VERIFIED | All 5 present on disk |
| `CHANGELOG.md` | `## [2.0.0]` entry | ✓ VERIFIED | `AssetsExistTest` 5/5 GREEN |
| `plugin.yaml` | Generic name/description/icon/author/homepage | ✓ VERIFIED (icon deviation D-20) | Unchanged, verified in prior cycles |
| `tests/Feature/Docs/ReadmeStructureTest.php` | Pins october:migrate; doc-gate for 05-22 delta | ⚠️ PARTIAL COVERAGE (WARNING) | Pins `october:migrate`; does NOT assert `[pixelHead]` INI or `:dev-master` presence (05-REVIEW.md WR-01) — a future revert of either would slip the gate. Content currently correct; not a blocker. |
| `.github/workflows/metapixel-qa.yml` | 2×2 CI matrix green on standalone public repo | ✓ VERIFIED (stale by 9 unpushed commits) | Last green run on `4a3b4a0`; delta since is docs/planning only, locally reproduced green |
| `.planning/ROADMAP.md` launch bullets + progress row | Bookkeeping consistent with tag/CI reality | ✓ VERIFIED (fixed by `10ac972`) | :404 `[x]` truthful; :405 `[ ]` no suffix; :402/:419 consistent |
| `.planning/REQUIREMENTS.md` MKT-04 row | Bookkeeping consistent with tag reality | ✓ VERIFIED | :112 `[ ]` + gate note; :258 `Pending` |
| `v2.0.0` git tag | Annotated, pushed to remote | ⏸ DEFERRED | Launch Milestone scope; correctly represented as pending everywhere |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `composer.json` `qa` script | `phpmd.xml` / `pint.json` / `phpstan.neon` / `phpunit.xml` | full qa chain | ✓ WIRED | All 4 run live, exit 0 |
| `README.md` install block | `ReadmeStructureTest` | doc-gate assertions | ✓ WIRED | 8/8 GREEN, pins `october:migrate` |
| `README.md` [pixelHead] INI + :dev-master fallback | `ReadmeStructureTest` | doc-gate assertions | ⚠️ PARTIAL (WARNING) | Content correct on disk but no assertion covers either string — 05-REVIEW.md WR-01; recommend adding the two assertions it drafts |
| `.planning/ROADMAP.md` progress table (:419) | Launch-milestone bullets (:404-405) | Internal cross-reference | ✓ WIRED | `1/2 Deferred` matches one `[x]` + one `[ ]` — contradiction resolved by `10ac972` |
| `.planning/REQUIREMENTS.md` MKT-04 (:112) | `.planning/ROADMAP.md` launch-02 bullet (:405) | Cross-doc consistency | ✓ WIRED | Both now say pending/operator-gated |
| SC1 stopwatch gate | `launch-02-PLAN.md` Step F.2/F.3 + :332 | Later-milestone ownership | ✓ WIRED (deferral link) | Explicit, specific scheduling — meets the Step 9b conservative-match bar |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| phpmd exits 0 on full qa file-set | `../../../vendor/bin/phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` | Exit 0, no output | ✓ PASS |
| pint clean | `../../../vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` | ✓ PASS |
| phpstan level 10 clean | `../../../vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` | ✓ PASS |
| Full pest suite + coverage gate (single run) | `../../../vendor/bin/pest --coverage --min=90` | `587 passed (2239 assertions)`, `Total: 90.5%`, exit 0 | ✓ PASS |
| README install-fidelity gate | `pest --filter=ReadmeStructure` | `8 passed (21 assertions)` | ✓ PASS |
| Custom-adapters doc gate | `pest --filter=CustomAdapters` | `8 passed (15 assertions)` | ✓ PASS |
| Marketplace assets gate | `pest --filter=AssetsExist` | `5 passed (6 assertions)` | ✓ PASS |
| CI run status | `gh run list --limit 3` | Last 3 runs all `success` (latest `28675470920` on `4a3b4a0`) | ✓ PASS |
| `v2.0.0` tag existence | `git tag -l` / `git ls-remote --tags origin` | No `v2.0.0` anywhere | ⏸ EXPECTED (deferred; bookkeeping matches) |
| `10ac972` fix scope | `git show 10ac972 --stat` + diff | 1 file, 1 insertion, 1 deletion — only ROADMAP.md:405, bullet text otherwise verbatim | ✓ PASS |
| ROADMAP/REQUIREMENTS bookkeeping consistency | grep on launch bullets, progress row, MKT-04 rows vs. tag state | All consistent post-`10ac972` | ✓ PASS |
| README `october:up` residue | `grep -c october:up README.md` | 0 | ✓ PASS |
| Debt markers on 05-22-touched files | `grep -nE 'TBD|FIXME|XXX|TODO|HACK|PLACEHOLDER' README.md docs/CUSTOM-ADAPTERS.md tests/Feature/Docs/ReadmeStructureTest.php` | exit 1, zero hits | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DOCS-01 | 05-00, 05-08, 05-09, 05-19, 05-22 | README <10min install→verified-event walkthrough | ✓ Content complete + fresh-install-executable; stopwatch measurement deferred to launch-02 (its explicitly assigned owner) | Truths #1, #6 |
| DOCS-02 | 05-00, 05-09 | README field/adapter/credential/troubleshoot/multisite walkthrough | ✓ Satisfied (2 documented deviations) | Truth #6 |
| DOCS-03 | 05-00, 05-10 | `docs/CUSTOM-ADAPTERS.md` working example | ✓ Satisfied — REQUIREMENTS.md checkbox stale ("Pending", under-reports; hygiene) | Truth #2 |
| MKT-01 | Launch Milestone (was 05-14) + UAT test 8 | Composer install on clean instances | ✓ Satisfied — executed with version-specific evidence; checkbox stale | Truth #7 |
| MKT-02 | 05-00, 05-12 | Generic `plugin.yaml` | ✓ Satisfied — checkbox stale | Truth #4 |
| MKT-03 | 05-00, 05-08, 05-12 | Icon(PNG)+5 screenshots+CHANGELOG | ✓ Mostly satisfied (PNG icon deviated per D-20) — checkbox stale | Truth #4 |
| MKT-04 | Launch Milestone (was 05-13/05-14), 05-20 bookkeeping | `v2.0.0` tag pushed; bookkeeping truthful | ⏸ Tag deferred by design / ✓ Bookkeeping truthful post-`10ac972` | Truths #5, #8 |
| MKT-05 | 05-18, 05-21 | `composer qa` exits 0; CI matrix green | ✓ Satisfied — independently re-confirmed live | Truth #3 |

**Orphaned requirements:** None — all 8 IDs (DOCS-01..03, MKT-01..05) present in REQUIREMENTS.md's Phase 5 traceability block and mapped to Phase 5 plans.

**Hygiene observation (WARNING, not a gap):** DOCS-03/MKT-01/MKT-02/MKT-03 checkboxes under-report delivered work ("Pending" despite artifacts shipped and gates GREEN). Harmless direction of drift; recommend a housekeeping pass at milestone close. Likewise ROADMAP.md's Phase 5 plan list still shows several executed plans as `[ ]` (05-08, 05-09, 05-15, 05-16, 05-19, 05-20, 05-21 — each has a SUMMARY on disk) alongside an arithmetically impossible "19/18" progress numerator; stale but completion-understating, and no truth above was verified from those checkboxes.

**Safeguard adequacy — recurrence risk stands (carry this forward):** the launch-02 "do NOT auto-stamp complete" annotation is advisory prose with no mechanical enforcement, and it has now been violated **three times** by routine tracking-update commits (`b8612fa`, `c177773`, `8b8737b`), each requiring a manual revert (`14e1ef6`, `10ac972`). The fix pattern is proven but reactive. **Recommendation (unchanged, now demonstrated necessary):** add a mechanical Pest planning-hygiene assertion that fails whenever a ROADMAP bullet containing "do NOT auto-stamp complete" is marked `[x]` while `git tag -l` lacks the tag the bullet names (`v2.0.0`). Until it exists, every future ROADMAP-touching commit re-exposes this bullet to a fourth regression; the launch-02 operator pass should re-check the bullet state before tagging.

### Anti-Patterns Found

None. `README.md`, `docs/CUSTOM-ADAPTERS.md`, `tests/Feature/Docs/ReadmeStructureTest.php` grep clean for `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`. No debt markers block this phase.

### Human Verification Required

None outstanding for Phase 5. The single stopwatch item (SC1/DOCS-01 timed dry-run) is deferred to the Launch Milestone with explicit ownership (launch-02-PLAN.md :49/:332/Step F.2, 05-22-SUMMARY D5) — see Deferred Items. It is preserved in `behavior_unverified_items` frontmatter so the obligation survives phase closure and surfaces at `LAUNCH SCHEDULED`.

## Gaps Summary

**No gaps remain.** The single blocker from cycle five — the third recurrence of the launch-02 bookkeeping regression — was fixed by commit `10ac972` (one-line revert, only ROADMAP.md touched) and independently re-verified this session: ROADMAP.md:405 reads `[ ]` with no completed suffix, internally consistent with the `Plans: 1/2` header and the `Launch Milestone | 1/2 | Deferred` progress row, and consistent with the actual tag state (no `v2.0.0` locally or on remote).

Phase 5's own automatable exit criteria are fully green, all verified first-hand:
- `composer qa` exits 0 end-to-end (phpmd, pint, phpstan L10, pest 587/587 at 90.5% coverage).
- The three README defects UAT test 7 found are fixed in code, gate-locked by `ReadmeStructureTest` (8/8 GREEN), and independently corroborated by a scoped code review (0 critical findings).
- `docs/CUSTOM-ADAPTERS.md`, 5 screenshots, CHANGELOG, generic plugin.yaml all present and gate-locked.
- MKT-01 clean-install smoke executed with version-specific evidence (UAT test 8); CI matrix 4/4 green on the last-pushed commit.

**Why `passed` rather than `human_needed`:** the only non-verified truths are (a) the `v2.0.0` tag and (b) the SC1 stopwatch dry-run, and both are explicitly owned by the Launch Milestone with specific, on-the-record scheduling — the ROADMAP defines the milestone as deferred-by-design (:398), launch-02-PLAN.md names the timed dry-run as its own launch acceptance gate executed at/after tag push (:49, :332, Step F.2), and 05-22-SUMMARY D5 records that the verbatim measurement "cannot be automated pre-tag while the remote is tagless." Per Step 9b these are launch-milestone deferrals, not Phase 5 gaps or open human-verification loops; the UAT flow already adjudicated test 7 and its code gap is closed and doc-gate-locked. The SC1 truth remains honestly marked PRESENT_BEHAVIOR_UNVERIFIED (excluded from the 6/8 verified count) and is preserved in `behavior_unverified_items` so it cannot be silently lost — it must be executed and recorded in the launch log during the operator's `LAUNCH SCHEDULED` pass.

**Carry-forwards for the Launch Milestone operator pass:** (1) push `v2.0.0` annotated tag + CI-green-on-tag + composer VCS install smoke (launch-02 Steps A-G); (2) README verbatim `-W` re-verify + drop the `:dev-master` pre-release note (Steps F.2/F.3); (3) the timed ≤10-min stopwatch dry-run, tracked in the launch log; (4) re-check the launch-02 ROADMAP bullet has not regressed a fourth time before tagging (or land the recommended mechanical hygiene test first); (5) optionally add the two missing doc-gate assertions from 05-REVIEW.md WR-01.

---

*Verified: 2026-07-04*
*Verifier: Claude (gsd-verifier)*
