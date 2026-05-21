---
phase: 5
slug: documentation-marketplace-launch
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-21
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Authoritative source: `05-RESEARCH.md § Validation Architecture` (lines 909–969).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (`pestphp/pest ^4.1`) + PHPUnit 12 + Larastan ^3.0 (PHPStan level 10) |
| **Config file** | `phpunit.xml` (root) + `phpstan.neon` + `pint.json` + `phpmd.xml` |
| **Quick run command** | `pest --filter='Docs\|Plugin\|Assets'` |
| **Full suite command** | `composer qa` (= pint-test → analyse → phpmd → test-cov --min=90) |
| **Estimated runtime** | ~120 seconds (quick subset ~10 s) |

---

## Sampling Rate

- **After every task commit:** `pest --filter='Docs\|Plugin\|Assets'` (Wave 0 doc-structure tests only — fast)
- **After every plan wave:** `composer qa` (full pint → phpstan → phpmd → pest-cov chain)
- **Before `/gsd:verify-work`:** `composer qa` green on Run A AND Run B (CI matrix on tag push)
- **Max feedback latency:** 120 seconds full suite, 10 s for Wave 0 subset

---

## Per-Task Verification Map

> Filled out per plan during planning. Anchored in `05-RESEARCH.md § Phase Requirements → Test Map`.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD planner | TBD | TBD | DOCS-01 | — | README sections + lang-key labels | unit + grep | `pest tests/Feature/Docs/ReadmeStructureTest.php` | ❌ W0 | ⬜ pending |
| TBD planner | TBD | TBD | DOCS-02 | — | Symptom→log→fix table present | unit | `pest --filter ReadmeTroubleshootTable` | ❌ W0 | ⬜ pending |
| TBD planner | TBD | TBD | DOCS-03 | — | 3 hook examples + OFFLINE Mall + ContractTestCase usage | unit + grep | `pest tests/Feature/Docs/CustomAdaptersStructureTest.php` | ❌ W0 | ⬜ pending |
| TBD planner | TBD | TBD | MKT-01 | — | composer require on clean install exits 0 | smoke + manual | `/tmp/test-install` composer VCS install | ❌ manual | ⬜ pending |
| TBD planner | TBD | TBD | MKT-02 | — | plugin.yaml generic + lang-key driven | unit | `pest tests/Feature/Plugin/PluginYamlSanityTest.php` | ❌ W0 | ⬜ pending |
| TBD planner | TBD | TBD | MKT-03 | T-05-03 (screenshot leak) | 5 PNG screenshots + CHANGELOG.md | unit | `pest tests/Feature/Docs/AssetsExistTest.php` | ❌ W0 | ⬜ pending |
| TBD planner | TBD | TBD | MKT-04 | T-05-01 (secret history) | v2.0.0 annotated tag pushed | manual | `git tag -v v2.0.0` + `git ls-remote --tags origin v2.0.0` | ❌ manual | ⬜ pending |
| TBD planner | TBD | TBD | MKT-05 | — | composer qa exits 0 on Run A + Run B | CI matrix | `.github/workflows/metapixel-qa.yml` | ✅ Phase 1 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Docs/ReadmeStructureTest.php` — covers DOCS-01 + DOCS-02
- [ ] `tests/Feature/Docs/CustomAdaptersStructureTest.php` — covers DOCS-03
- [ ] `tests/Feature/Docs/AssetsExistTest.php` — covers MKT-03
- [ ] `tests/Feature/Plugin/PluginYamlSanityTest.php` — covers MKT-02
- [ ] *(Optional)* `tests/Feature/Docs/NoV1xReferencesTest.php` — covers D-23 strip
- [ ] Framework install: NONE — Pest 4 + Larastan already shipped Phase 1

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Buyer 10-min dry-run on clean OctoberCMS 4.x | DOCS-01 | Wall-clock timing on real install requires human operator | Operator stopwatch + EventLog tail + Meta Test Events screenshot — Success Criterion 1 launch acceptance gate |
| Three UAT gates — zero-events, PageView-only, event_id-sync | D-03, D-05 | Cross-tool verification (Pixel Helper + Test Events + EventLog DB) needs human-confirmed pass/fail | 05-03, 05-05, 05-07 plan tasks — three-source verification per gate, named pass criteria in each gate plan |
| Smoke produces 3 event classes × 2 channels | D-07 | Real orders on `new.nailscosmetics.lv` + Meta Test Events visual inspection | `SELECT count(*), event_name, channel FROM logingrupa_metapixel_event_log GROUP BY event_name, channel` returns ≥6 rows; Meta Test Events shows Purchase + PageView + ViewContent with "Deduplicated" label |
| Public-flip irreversibility check | MKT-04 / D-25 | Composer install from unauthenticated machine cannot be automated in CI without leaking auth tokens | `/tmp/test-install` composer VCS install from cold cache exits 0 |
| Pre-flip secret-history grep | D-26 | History rewrite is destructive and must be operator-acknowledged | `git log --all -p \| grep -iE 'pixel_id.*=.*[0-9]{10,}\|access_token.*=.*EAA' \| grep -vE '1234567890\|000000000000000\|REDACTED_FOR_DEMO'` returns empty |
| Screenshot dummy-row verification | MKT-03 / D-18 | Visual review of PNG content cannot be automated reliably | Operator confirms every PNG in `docs/screenshots/` shows only `pixel_id = 000000000000000` + `access_token = REDACTED_FOR_DEMO_DO_NOT_USE` |
| Annotated tag at correct SHA | MKT-04 / D-21 | Tag annotation + push requires operator credentials | `git describe --tags --exact-match HEAD == v2.0.0` AND `git for-each-ref refs/tags/v2.0.0 --format='%(objecttype)' == 'tag'` (annotated, not lightweight) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120 s
- [ ] `nyquist_compliant: true` set in frontmatter (set after planner fills Per-Task Verification Map)

**Approval:** pending
