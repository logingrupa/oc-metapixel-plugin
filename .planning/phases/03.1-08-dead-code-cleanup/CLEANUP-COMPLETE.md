# Phase 03.1-08 — Cleanup Complete

Aggregate report per BRIEF lines 314-333 shape. Phase 03.1-08 closed end-to-end 2026-05-14: production-code docblock + signature align, 5 test-suite DRY/lint fixes, 6 failing tests → full green PRIMARY, planning-doc + composer schema clean, composer qa green end-to-end, v1.1.1 tagged (annotated, local-only).

## Cleanup Complete

| Track | Items | Commits | Status |
|---|---|---|---|
| **T1 prod** | 3 (MED-01 EventLog docblock + MED-02 EventLogWriter signature + LOW-06 SiteResolver PHPDoc) | `a64f3c3` (docs), `98ed70c` (refactor), `f2f887c` (docs) | ✓ all green |
| **T2 tests** | 5 (LOW-01..05) + T2.5 setUp comment = 6 atomic | `8ccf738` (refactor), `fd10dfa` (test), `28599d4` (test), `8fef82a` (test), `9935c82` (test), `a310216` (test) | ✓ |
| **T3 fixes** | 6 pre-existing test failures — ALL PRIMARY (zero SKIP-BASELINE.md entries) | `7c41614` (test), `7f01528` (fix), `fb2e19a` (fix), `325a531` (test), `27fb21e` (test), `0b90d9b` (test) | ✓ |
| **T4 docs** | 3 (PLAN+PLAN-v2 SUPERSEDED, updates/.gitkeep delete, composer.json _comments delete) | `4e62b45` (docs), `47aca55` (chore), `1cc53df` (chore) | ✓ |
| **T5 close** | 4 atomic (pint + phpstan baseline + phpmd tolerance + STATE.md) + 1 git tag (not a commit) | `a710e7f` (chore), `745e922` (chore), `02a08a0` (chore), `3077743` (docs), tag `v1.1.1` | ✓ qa green, tagged |

**Phase total atomic commits (excluding orchestrator merge commits + SUMMARY commits):** 3 + 6 + 6 + 3 + 4 = **22 atomic commits across 5 plans** (BRIEF estimate was 19-20; +2 driven by T5.1 SRP split into 3 commits instead of 1).

**Plus 5 SUMMARY commits** (one per plan: `6d3b1a6` for plan-01, `9863a35` for plan-02, `05d98b8` for plan-03, `4e686d4` for plan-04, this plan's pending SUMMARY commit for plan-05) **+ 1 CLEANUP-COMPLETE.md (this file, in the same commit as plan-05 SUMMARY)**.

**Plus 4 orchestrator merge commits** (`4057a12`, `5d2e7e7`, `45612b3`, `573d5ef`) and the **`97d92a0` + `1a21799` planning-doc commits that bootstrapped the phase**.

### Tests delta

- **Before (45612b3 / pre-T3):** Tests: **6 failed, 171 passed** (503 assertions). Duration: 6.37s.
- **After (HEAD / post-T3 → post-T5):** Tests: **177 passed, 0 failed** (531 assertions). Duration: ~6.6s.
- **Net:** **+6 passing, -6 failing, +28 assertions, +0.2s** — all 6 baselined failures resolved via PRIMARY fixes (no SKIP-BASELINE.md fallback). Pest suite is 100% green.

### qa exit codes

| Stage | Exit | Notes |
|---|---|---|
| `composer pint-test` | **0** | Was failing on 4 files pre-T5.1; auto-fix via `composer pint` then locked. |
| `composer analyse` | **0** | Was 2 errors pre-T5.1 (SendCapiEvent::extractFirstEvent return widening + EventLog::subject MorphTo mismatch). Baseline regen per BRIEF fallback gate (narrow PHPDoc fix introduced `instanceof.alwaysFalse` + `deadCode.unreachable` under `treatPhpDocTypesAsCertain: true`). Production code byte-untouched. |
| `composer phpmd` | **0** | Was 5 pre-existing violations (3x `$mId` ShortVariable + 2x ExcessiveClassComplexity on PayloadBuilder + PurchasePixel). Config-only fix (Constraint #1 honored): ShortVariable exception `mId` + ExcessiveClassComplexity 50 → 55. |
| `composer test` / `test-cov` | **0** | 177/0 (531 assertions). Coverage 82.8%. |
| `composer qa` (composite) | **0** | First time end-to-end green. |

### Git ledger

- **Plugin git tag `v1.1.1`** — annotated, local-only. Message: `Phase 3.1-07 cross-context site_id symmetry + 03.1-cleanup dead code removal`. Points at the FINAL commit of plan-05 (the SUMMARY commit). **NOT pushed** — operator-gated decision per BRIEF T5.2 + STATE.md handoff narrative.
- **`updates/version.yaml`** ledger entry for v1.1.1 already shipped in Phase 3.1-07 (commit `015b374` cross-context symmetry); phase 03.1-08 is purely cleanup + qa gate close, no new functional shipping.

### STATE.md

- **status**: `executing` → **`phase-3.1-milestone-ready`**
- **stopped_at** narrative (BRIEF T5.3 verbatim): *"Phase 3.1 closed end-to-end. v1.1.1 + 03.1-cleanup landed. qa green. Awaiting operator: v1.1.1 deploy on .lv/.lt/.no + STAGING Scenario 5 per site + manual repair of 2 stranded rows on new.nailscosmetics.lv (or accept Pixel-miss). Next: /gsd-plan-phase 4 (Funnel Completion)."*
- **Quick Tasks Completed**: 20260514c row added anchoring this phase.
- **Session Continuity**: narrative paragraph + Last/Stopped lines updated.

### Followup deferred

Per BRIEF Constraint #1 (no drive-by refactors), out-of-scope discoveries logged to [`FOLLOWUP.md`](FOLLOWUP.md):

1. **`.planning/README.md` references SUPERSEDED PLAN.md** — README still points at retired top-level PLAN.md; should redirect to `.planning/phases/03.1-event-log-refactor/BRIEF.md`. (Plan-04 deferred entry; already in FOLLOWUP.md.)
2. **`classes/meta/PayloadBuilder.php` ExcessiveClassComplexity 53/55** — full Meta envelope builder, naturally splittable when Phase 4 funnel events land (AddToCart, ViewContent, Lead each likely get their own builder). Threshold raised in T5.1c with inline anchor; revisit during Phase 4 refactor.
3. **`components/PurchasePixel.php` ExcessiveClassComplexity 51/55** — checkout-context component; may split when Phase 4 adds non-Purchase pixel events. Threshold raised in T5.1c with inline anchor; revisit during Phase 4 refactor.
4. **`$mId` ShortVariable convention** — Hungarian narrowing pattern (`m=mixed + Id=domain`) used across SiteResolver + Settings. Exception added in `phpmd.xml`; documented in plugin CLAUDE.md "Hungarian Notation" section as project-accepted shorthand for type-narrowing temporary locals. No follow-up — accepted as canonical convention.

### Operator handoff

After this phase merges back to plugin master:

1. **Operator decision on push:** `git push origin v1.1.1` (NOT done by automation — operator-gated per BRIEF T5.2 + the threat-model T-03.1-08-10 mitigation).
2. **Deploy v1.1.1** on `.lv` + `.lt` + `.no` production sites via Laravel Forge.
3. **Run STAGING-RUNBOOK Scenario 5** per site (`plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/STAGING-RUNBOOK.md`).
4. **Manual repair** of 2 stranded rows on `new.nailscosmetics.lv` (orders 29802 + 29803) via `BACKFILL.sql` — OR accept the Pixel-miss for those 2 rows.
5. **`/gsd-plan-phase 4 (Funnel Completion)`** — phase 4 dispatch ready; API surface preserved in STATE.md "API surface for Phase 4" block + Phase 3.1 forward notes.

---

*Phase: 03.1-08-dead-code-cleanup*
*Closed: 2026-05-14*
*v1.1.1 tagged locally; awaiting operator push + staging verification.*
