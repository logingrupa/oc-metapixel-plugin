# Launch Log — v2.0.0

**Date:** 2026-07-04
**Operator:** authorized via `LAUNCH SCHEDULED` signal (gh account: roulendz); executed agent-driven
**Smoke SHA:** not recorded in 05-08-SUMMARY.md — substitute check performed: all commits between smoke (2026-07-03 UAT approval) and tag verified doc/planning-only via `git diff --name-only` (non-planning files touched: LICENSE, README.md, composer.json license field, tests/Feature/Docs + tests/Unit/Tooling test expectations) + full `composer qa` re-run green locally immediately before tagging
**Tagged SHA:** `797ebb6` (tag object `3c79919345365323ecf354ac853fda62295fdcec`)
**Post-smoke cleanup commits (docs/manifest only):** milestone-close docs (f53c497, c08e955, 3179d98, d55fb91), license (797ebb6 — composer.json license field + LICENSE + ComposerJsonShapeTest expectation), README note drop (11450ef, post-tag per plan F.3)

## Pre-flight (Step A)
git status: clean ✓
composer qa: exit 0 ✓ (589 tests passed, 2241+ assertions, coverage 90.5% ≥ 90% gate; run with host vendor binaries on PATH — plugin-local vendor carries no tool binaries)
Note: first qa attempt failed 1 test — `ComposerJsonShapeTest::test_license_is_proprietary` broke on the MIT flip; expectation updated to `test_license_is_mit` in the same license commit, chain re-run green.

## Legacy archive local-only (Step B, D-24)
git ls-remote --tags origin 'v1*': empty ✓
git ls-remote --heads origin 'legacy/*': empty ✓

## Public flip (Step C, D-25)
Command: `gh repo edit logingrupa/oc-metapixel-plugin --visibility public`
(installed gh predates `--accept-visibility-change-consequences` flag and the `visibility` JSON field — verified via `isPrivate` instead)
gh repo view --json isPrivate: `{"isPrivate":false}` ✓
Unauthenticated HTTP check: `curl https://github.com/logingrupa/oc-metapixel-plugin` → 200 ✓

## Annotated tag (Steps D+E)
`git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"` ✓
git for-each-ref refs/tags/v2.0.0 --format='%(objecttype)': `tag` (annotated, not lightweight) ✓
git describe --tags --exact-match HEAD: `v2.0.0` ✓ (Pitfall 6 — tag at HEAD)
git push origin v2.0.0: ok ✓
git ls-remote --tags origin v2.0.0: `3c79919… refs/tags/v2.0.0` ✓

## Unauth-install smoke (Step F, Pitfall 2)
Scratch dir (isolated COMPOSER_HOME, VCS block + `^2.0` require, minimum-stability stable):
- Composer resolved `logingrupa/oc-metapixel-plugin[v2.0.0]` UNAUTHENTICATED — repo access + stable tag resolution proven ✓
- Full install in bare scratch stops at `lovata/toolbox-plugin ^2.2 could not be found` — EXPECTED: Lovata packages ship via the authenticated October gateway (`php artisan project:set <license>`), not Packagist. README Install section documents this ordering (gateway before require). Not a repo-visibility failure — Pitfall 2 target disproven.

## README verbatim re-verify (Step F.2 — closes UAT test 7 defect (1))
Clean-room root: `/home/forge/metapixel-test7` (October v4.3.1, gateway configured, minimum-stability=stable, previously pinned `:dev-master` from tagless-era UAT)
Command (verbatim README): `composer require logingrupa/oc-metapixel-plugin -W --no-interaction`
Result: `Using version ^2.0` — resolved **v2.0.0 stable**, replaced dev-master pin, NO minimum-stability error ✓
`composer show logingrupa/oc-metapixel-plugin`: `versions : * v2.0.0` ✓

## README pre-release note drop (Step F.3)
Deleted Install-section "Pre-release install" block + quick-start step 3 `:dev-master` fallback sentence.
Doc-gate test `test_readme_documents_dev_master_prerelease_fallback` (tagless-era guard) flipped to `test_readme_ships_stable_install_without_dev_master_fallback` (asserts verbatim `-W` command present + `:dev-master` absent). Docs suite 28/28 green.
Commit: `11450ef` (pushed to master; post-tag doc-only change — tag content unaffected, per plan sequencing)

## CI matrix on v2.0.0 tag commit (Step G, MKT-05)
metapixel-qa.yml run: 28685242400 (push of tag commit 797ebb6)
composer qa (PHP 8.3 / full-lovata): green ✓
composer qa (PHP 8.4 / full-lovata): green ✓
composer qa (PHP 8.3 / minimal): green ✓
composer qa (PHP 8.4 / minimal): green ✓
(annotation noise only: actions/checkout@v4 Node 20 deprecation notice — non-blocking)

## License decision (Task 1)
composer.json license: `MIT`
LICENSE file: present (copyright holder: Logingrupa, 2026)
Decision basis: plan Task 1 + RESEARCH default recommendation MIT; operator AFK at checkpoint after issuing `LAUNCH SCHEDULED` — default applied and recorded. Revisit in v2.0.1 if operator prefers proprietary/Apache-2.0.

## Overall verdict

LAUNCH COMPLETE ✓

- MKT-01 ✓ — unauthenticated resolution of stable v2.0.0 proven; buyer path (gateway + VCS block + verbatim `-W`) verified end-to-end on clean-room root
- MKT-04 ✓ — annotated v2.0.0 tag pushed; legacy archive confirmed local-only
- MKT-05 ✓ — composer qa exit 0 on all 4 CI matrix cells at the tag commit
- UAT test 7 defect (1) ✓ — verbatim README install command resolves stable tag post-launch
