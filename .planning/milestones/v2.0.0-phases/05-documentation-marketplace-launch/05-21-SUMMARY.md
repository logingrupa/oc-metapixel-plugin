---
phase: 05-documentation-marketplace-launch
plan: 21
subsystem: ci
tags: [github-actions, ci, gateway-auth, security-sweep, gap-closure, marketplace-launch]
requires:
  - .github/workflows/metapixel-qa.yml (monorepo-path workflow, broken on standalone origin)
  - OCTOBER_AUTH_JSON repository secret (operator-supplied October gateway license)
  - .planning/launch/launch-01-SECURITY-SWEEP.md (Step A complete, Step B deferred)
provides:
  - Green 4-cell CI matrix (php 8.3/8.4 x full-lovata/minimal) on the public standalone repo
  - 163 commits published to origin/master (previously 154+ unpushed, remote stale since 2026-05-21)
  - Security-sweep Step B executed — staging host, pixel ID, test-event codes redacted from tracked non-archive .planning/
  - phpstan scanDirectories for October modules + Lovata plugins (benefits local + CI + operator installs)
affects:
  - Launch Milestone v2.0.0 tag (CI-green prerequisite now satisfied; tag stays gated on LAUNCH SCHEDULED)
tech-stack:
  added: []
  patterns:
    - CI host-app topology — qa binaries in host October app vendor, invoked ../../../vendor/bin/* from plugin dir
    - larastan app discovery via symlinked plugin-vendor larastan (PHP __DIR__ resolves symlinks to host app root)
    - COMPOSER_AUTH env var for location-independent gateway credential in CI
key-files:
  created: []
  modified:
    - .github/workflows/metapixel-qa.yml
    - phpstan.neon
    - pint.json
    - composer-dependency-analyser.php
    - .planning/launch/launch-01-SECURITY-SWEEP.md
    - .planning/ (33 files redacted, non-archive)
decisions:
  - "REDACT-FIRST honored: security-sweep Step B executed before the outward push; history analysis found no live token anywhere and already-public strings add no new exposure class, so a plain push (no force, no history rewrite) was justified."
  - "CI mirrors the local-dev topology exactly: host October app owns the toolchain vendor; larastan reaches bootstrap/app.php through the plugin-vendor symlink — the same mechanism local dev has used all along."
  - "phpstan + coverage gates run in the full-lovata cell only: Lovata packages ship no composer autoload, so their classes are unresolvable in the minimal cell BY DESIGN; source is identical across cells; the minimal cell proves the runtime minimal-install shape via pest --exclude-group=adapter."
  - "composer deps gate dropped from CI (coordinator-approved): shipmonk resolves symbols via composer classmaps, which skip October-convention lowercase-dir classes as PSR-4-noncompliant and cannot attribute Lovata symbols at all — the Lovata-boundary check is structurally inoperable; proper onboarding is a deferred follow-up."
  - "rector/rector excluded from the CI plugin vendor: its scope-prefixed vendor stubs fatal reflection passes; rector is not part of the qa chain."
metrics:
  duration: ~2 h (across two executor sessions + 7 CI iterations)
  completed: 2026-07-03
  tasks: 3
  files: 38
status: complete
---

# Phase 5 Plan 21: Standalone-Repo CI Green + Publish Summary

Closed the actionable half of Gap 2 (UAT test 9, MKT-04): the public repo's CI matrix is green on HEAD for the first time ever (previous history: 3 runs, all failures, stale since 2026-05-21), and all local commits are published. The operator chose REDACT-FIRST at the push checkpoint, so the deferred security-sweep Step B ran before publication. The `v2.0.0` tag remains correctly gated on LAUNCH SCHEDULED.

## What Shipped

**Task 1 — Workflow rewritten for standalone-plugin-at-root (`31460a5`, prior session)**
Dropped the monorepo `working-directory`/`../../../vendor` paths that could never exist on the public origin; reconstructs a host OctoberCMS 4.x app around the checked-out plugin; authenticates gateway.octobercms.com from `secrets.OCTOBER_AUTH_JSON`; keeps the 2x2 matrix, `permissions: contents: read`, and the coverage gate.

**Task 2 — Operator gateway secret (checkpoint honored)**
`OCTOBER_AUTH_JSON` verified present in the repo's Actions secrets via `gh api` before proceeding. Secret value never printed, never committed.

**Task 3 — REDACT-FIRST, push, CI iterated to green**

*Security-sweep Step B (`7c88e6a`):* redacted across 33 tracked non-archive `.planning/` files — staging hostname → `your-staging-host.example` (27 files), real pixel ID → `<pixel-id-redacted>` (11), Meta test-event codes → `<test-event-code-redacted>` (6). Archive untouched per D-26. Post-redaction grep: 0 hits. `launch-01-SECURITY-SWEEP.md` status PARTIAL → COMPLETE with the execution record.

*History-exposure analysis (documented in the sweep doc):* remote was `41bdf3c`; local HEAD 154 commits ahead. No live CAPI token anywhere in the unpushed range (case-sensitive `EAA` scan clean — the single hit was git SHA `eaab74b…`). Staging host (24 files), pixel ID (14 files), and all operator emails were ALREADY public in the pushed history with identical HEAD counts — no new exposure class. Only the throwaway, non-auth test-event codes were new to the remote; accepted per the sweep doc's own Step A classification. Plain push justified; no force-push, no history rewrite.

*Push + CI iterations (all root causes fixed, never worked around):*

| Iter | Commit | Wall cleared |
| ---- | ------ | ------------ |
| 1 | `7c88e6a` | pushed; run confirmed workflow executes on standalone layout |
| 2 | `0e005bb` | gateway repo not registered + auth written to wrong composer home → `COMPOSER_AUTH` env + global `repositories.octobercms` |
| 3 | `79667ae` | composer 2.2+ `allow-plugins` blocked October installer plugins → allow-list (global, host app, plugin dir) |
| 4 | `aad2ff4` | enabling installers relocated October into the plugin's linted tree (pint scanned 707 files) → `installers=false` in plugin vendor + defensive pint excludes |
| 5 | `c0bd59e` | larastan `Undefined constant LARAVEL_VERSION` → host-vendor topology: toolchain installed in host app; plugin-vendor larastan/spaze swapped to host symlinks (the exact local-dev mechanism, traced on the live host); gates invoked `../../../vendor/bin/*` |
| 6 | `32d94a3` | phpstan 199 unknown classes (October modules + Lovata ship no composer autoload) → `scanDirectories: ../../../modules, ../../../plugins/lovata` — verified green locally first |
| 7 | `594e07f`, `7754485` | analyser fatal on rector's scoped stubs → rector out of CI plugin vendor; then deps gate dropped entirely (structurally inoperable for October plugins — coordinator decision) |

**Final run 28674577778 on `7754485`: all 4 cells SUCCESS** — pint, phpstan level 10, phpmd, pest `--coverage --min=90` (full cell) / pest `--exclude-group=adapter` (minimal cell), on PHP 8.3 and 8.4.

## Deviations from Plan

### Auto-fixed / scope decisions within the plan's iterate-to-green mandate

**1. [Rule 3 - Blocking] pint.json defensive excludes** — modules/plugins/themes/storage/bootstrap/config added so no install layout can pull third-party code into lint scope. No-op locally (dirs never exist in the plugin tree).

**2. [Rule 3 - Blocking] phpstan.neon scanDirectories** — required for CI symbol discovery; identical topology locally, verified level-10 green locally before push. Also documents WHY (October ClassLoader vs static analysis).

**3. [Rule 3 - Blocking] composer-dependency-analyser.php ignore-list guard** — dev-tooling ignores now conditioned on actual require-dev presence, so a vendor without rector does not fail on unmatched ignores.

**4. [Coordinator decision] deps gate removed from CI** — the plan's Task 1 text listed deps in the chain, but the canonical `composer qa` chain (composer.json + CLAUDE.md) never included it, it has never run successfully in any environment, and it is structurally inoperable for October plugins (see Deferred Follow-ups).

**5. [Coordinator decision] phpstan scoped to full-lovata cell** — Lovata classes are unresolvable in the minimal cell by design; the minimal cell proves the runtime shape instead.

## Deferred Follow-ups

- **shipmonk/composer-dependency-analyser onboarding**: to make `composer deps` functional it needs `disableComposerAutoloadPathScan()` (the plugin's psr-4 `""` maps the whole repo incl. vendor), a custom classmap source for October-convention lowercase-dir classes (composer's ClassMapGenerator skips them as PSR-4-noncompliant), and an attribution strategy for Lovata packages (they declare no composer autoload, so no symbol can map to them). Until then the Lovata import boundary is enforced by phpstan disallowed-calls + code review.
- **Test-fixture unknown classes** (`AdapterRegistryFixtureParent/Child`, `BootOrderFixtureSubjectA/B`) would need `ignoreUnknownClasses` in the analyser config when onboarding.

## Verification

- `gh api …/actions/secrets` lists `OCTOBER_AUTH_JSON` (Task 2 done criteria).
- Post-redaction `grep -rIl` for all four sweep strings in non-archive `.planning/`: 0 files.
- `git ls-remote origin master` == local HEAD after each push; no force-push at any point.
- Run 28674577778: 4/4 jobs `success` via `gh run watch` + `gh api …/jobs` (must-have truth: CI matrix watched to green on HEAD).
- Local qa parity held throughout: `pint --test`, `phpstan analyse` (with scanDirectories), `phpmd` all green locally with the identical `../../../vendor/bin` invocations before each push.
- Explicitly NOT done (out of scope, operator-gated): `v2.0.0` tag, repo-visibility change (already public).

## Self-Check: PASSED

- .github/workflows/metapixel-qa.yml — FOUND
- phpstan.neon scanDirectories — FOUND
- Commits 31460a5, 7c88e6a, 0e005bb, 79667ae, aad2ff4, c0bd59e, 32d94a3, 594e07f, 7754485 — FOUND in git log
- CI run 28674577778 conclusion success x4 — CONFIRMED
