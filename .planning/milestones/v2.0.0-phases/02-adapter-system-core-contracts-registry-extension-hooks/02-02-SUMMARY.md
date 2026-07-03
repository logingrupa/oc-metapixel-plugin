---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 2
subsystem: tooling
tags: [phpstan, phpmd, pest, phpunit, disallowed-calls, p-01, p-13, extensibility-contract, claude-md, lovata-toolbox]

requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 1
    provides: classes/adapter/ + tests/doubles/ lowercase dirs (October ClassLoader); phpstan paths include classes; phpunit source include adds ./classes
provides:
  - phpstan.neon disallowedMethodCalls block — bans System\Classes\SiteManager::*, October\Rain\Support\Facades\Site::*, Illuminate\Http\Request::* in classes/queue/*, classes/event/*, classes/adapter/* via H-1 disallowIn deny-list
  - phpstan.neon disallowedFunctionCalls — adds global request() helper ban with same disallowIn scoping
  - phpunit.xml Metapixel Adapter Tests testsuite extended with tests/Contract/Adapter
  - phpunit.xml Metapixel Contract Tests new testsuite scoped to tests/Contract
  - phpunit.xml <source><include> extends coverage scope to ./classes + ./models
  - CLAUDE.md "Extensibility contract" ranks third-party hooks 1-6 with Component::extend flagged LAST RESORT, onMetapixel* prefix mandate, event_id/event_time mutation warning on before_dispatch
  - tests/Contract/Adapter/.gitkeep placeholder (Pest tolerates empty dir; Plan 02-07 populates)
affects:
  - 02-03a (storage layer migrations + EventLog + FailedEvent models gain phpunit coverage scope via ./models)
  - 02-03b (Settings + PluginGuard land under classes/ — phpstan already scans classes/ since 02-01)
  - 02-04 (SiteResolver lands under classes/helper/ — phpstan deny-list does NOT cover Helper dir per H-1 fail-open decision; SiteResolver-specific grep guard owned by Plan 02-04 Task 3)
  - 02-05 (SendCapiEvent lands under classes/queue/ — phpstan deny-list FIRES here; queue handler MUST resolve site_id via SiteResolver, not SiteManager)
  - 02-06 (event subscribers + ModelHandlers land under classes/event/ — phpstan deny-list FIRES here)
  - 02-07 (FakeAdapterContractTest scaffold lands under tests/Contract/Adapter — testsuite already wired)
  - phase 03 (ShopaholicOrderAdapter + ThemeActionAdapter land under classes/adapter/ — phpstan deny-list FIRES; adapters MUST read site_id from subject only)

tech-stack:
  added: []
  patterns:
    - "phpstan disallowedMethodCalls block uses spaze/phpstan-disallowed-calls H-1 disallowIn deny-list (NOT allowIn allow-list) scoped to three lowercase dirs"
    - "Belt-and-suspenders FQN ban: System\\Classes\\SiteManager (manager class) AND October\\Rain\\Support\\Facades\\Site (facade class) — both verified via vendor/october grep spike"
    - "phpunit.xml <source><include> tolerates forward-reference dirs that do not yet exist (Pest 4 contributes 0 lines to coverage rather than erroring)"
    - "Plugin CLAUDE.md ordered extensibility hook list 1-6 with explicit Event::fire > Component::extend ranking (P-13 convention)"

key-files:
  created:
    - tests/Contract/Adapter/.gitkeep
  modified:
    - phpstan.neon (disallowedMethodCalls block + request() function-ban + 4 disallowIn deny-list entries scoped to lowercase classes/queue, classes/event, classes/adapter dirs)
    - phpunit.xml (Adapter testsuite extended with tests/Contract/Adapter + new Metapixel Contract Tests testsuite + source-include adds ./classes + ./models)
    - CLAUDE.md ("Extensibility contract" section rewritten to rank hooks 1-6 with LAST RESORT framing on Component::extend + onMetapixel* prefix mandate + before_dispatch event_id/event_time immutability warning)

key-decisions:
  - "Site facade FQN verified via /tmp/site-fqn-spike.txt grep against modules/system/classes/SiteManager.php + vendor/october/rain/src/Support/Facades/Site.php. RESEARCH §5.1 assumed 'October\\Rain\\Cms\\Site' is INCORRECT for this October build — vendor/october/rain/src does NOT contain a Cms/ subdir. Actual FQNs are: System\\Classes\\SiteManager (the manager class) and October\\Rain\\Support\\Facades\\Site (the proper facade class). Global root-namespace alias \\Site extends the facade and resolves to the facade FQN through Laravel's AliasLoader — banning the facade FQN catches both call forms. Both FQNs ship in phpstan.neon for belt-and-suspenders coverage."
  - "Plan paths converted to lowercase (classes/queue, classes/event, classes/adapter) per the 02-01 SUMMARY carry-over decision. PLAN.md text showed PascalCase (classes/Queue, classes/Event, classes/Adapter) but October Rain ClassLoader requires lowercase folder names; PHPStan disallowIn patterns match filesystem paths so must also be lowercase."
  - "PHPStan deny-list semantics chosen over allow-list (H-1 lock honored): outside classes/queue, classes/event, classes/adapter the banned calls are PERMITTED. middleware/, controllers/, components/ legitimately read Request; classes/helper/SiteResolver MUST NOT call SiteManager — that's enforced by Plan 02-04 Task 3's static-source regex grep test (defence-in-depth, not phpstan-rule). classes/meta/MetaClient has no need to call SiteManager — credentials come per-call from caller (D-19); code review enforces."
  - "tests/Contract/Adapter/.gitkeep created so the testsuite path exists pre-plan-02-07. Pest 4 tolerates empty test dirs gracefully (0 tests collected, no error), but the directory must exist for phpunit.xml's <directory> resolution."
  - "phpunit.xml source-include adds ./models even though the directory does not yet exist on disk (lands in plan 02-03a). PHP DOMDocument validates the XML; Pest 4 contributes 0 lines to coverage for missing dirs rather than failing. This pre-stages the coverage scope so plan 02-03a does not need to re-edit phpunit.xml."

patterns-established:
  - "phpstan.neon disallowedMethodCalls + disallowedFunctionCalls scoping pattern — every new rule SHOULD use disallowIn deny-list with lowercase classes/<subdir>/* paths. Never allowIn — silently fails-open if a new sibling dir is added"
  - "CLAUDE.md project lock convention — numbered ranked lists with bold preference labels (in order of preference) and explicit framing words (LAST RESORT, MUST NOT, REQUIRED) for normative rules"
  - "Site facade ban policy — ban both System\\Classes\\SiteManager (manager class) AND October\\Rain\\Support\\Facades\\Site (facade class). PHPStan accepts unknown FQNs in disallowed-calls config as no-ops, so belt-and-suspenders has zero cost"
  - "Forward-reference coverage include — adding directories to phpunit.xml <source><include> ahead of their creation is safe (Pest 4 tolerates missing dirs); pre-staging avoids re-editing config in downstream plans"

requirements-completed:
  - ADAP-06-SECONDARY-PARTIAL

duration: ~4 min
completed: 2026-05-17
---

# Phase 02 Plan 02: Tooling deltas — PHPStan + phpunit + CLAUDE.md Summary

**Phase 2 P-01 static enforcement landed — phpstan.neon `disallowedMethodCalls` block bans SiteManager + Site facade + Request inside classes/queue, classes/event, classes/adapter dirs (H-1 `disallowIn` deny-list); `request()` helper banned in same scope; phpunit.xml extends coverage to `./models` + the Contract testsuite directory; CLAUDE.md "Extensibility contract" ranks hooks 1–6 with Component::extend flagged LAST RESORT (P-13).**

## Performance

- **Duration:** ~4 min (2026-05-17T21:14:42Z → ~21:19Z)
- **Tasks:** 5 (all auto-mode, no checkpoints)
- **Commits:** 3 (one per code-touching task; Task 1 is a verification-only spike with output captured in this SUMMARY; Task 5 is the QA-gate and has no source edits beyond Tasks 2–4)
- **Files created:** 1 (tests/Contract/Adapter/.gitkeep)
- **Files modified:** 3 (phpstan.neon, phpunit.xml, CLAUDE.md)

## Accomplishments

- **Resolved RESEARCH §9 risk A1 (Site facade FQN `[ASSUMED]`)** via Task 1 grep spike against `vendor/october/rain/` + `modules/system/`. Confirmed two FQNs in this October build: `System\Classes\SiteManager` (manager class at `modules/system/classes/SiteManager.php` line 18) and `October\Rain\Support\Facades\Site` (facade class at `vendor/october/rain/src/Support/Facades/Site.php` line 48). The RESEARCH-assumed namespace `October\Rain\Cms\Site` is INCORRECT for this October build — `vendor/october/rain/src/` does not contain a `Cms/` subdirectory. Both verified FQNs land in `phpstan.neon` for belt-and-suspenders coverage.
- **Landed P-01 cross-context-resolution-drift static enforcement** (Task 2): `phpstan.neon` gains a `disallowedMethodCalls` block with three rules (SiteManager + Site facade + Request) plus a new `request()` entry in `disallowedFunctionCalls`. All four use H-1 `disallowIn` deny-list (NOT `allowIn` allow-list) scoped to lowercase `classes/queue/*`, `classes/event/*`, `classes/adapter/*`. The deny-list approach is fail-OPEN for non-adapter/queue/event dirs (middleware/, controllers/, components/, classes/helper/, classes/meta/ unaffected); cross-dir defence-in-depth handled by Plan 02-04 Task 3's static-source regex grep test on `SiteResolver.php`.
- **Pre-staged Contract testsuite scaffolding** (Task 3): `phpunit.xml` extends the existing `Metapixel Adapter Tests` testsuite with `./tests/Contract/Adapter`, adds a new `Metapixel Contract Tests` testsuite scoped to `./tests/Contract`, and extends `<source><include>` from `Plugin.php + ./classes` to `Plugin.php + ./classes + ./models`. `tests/Contract/Adapter/.gitkeep` created so the testsuite path exists pre-plan-02-07.
- **Locked the P-13 convention in CLAUDE.md** (Task 4): "Extensibility contract" section rewritten as a numbered ranked list of 6 hooks. Event::fire hooks (2–4) rank above `Component::extend` + `addDynamicMethod` (6). Component::extend explicitly flagged LAST RESORT with mandatory `onMetapixel*` dynamic-method prefix to avoid third-party collisions. `metapixel.event.before_dispatch` listeners explicitly warned not to mutate `event_id` or `event_time` (dedup contract anchor — Meta dedupes server-pixel on `event_id` match within ±10s of `event_time`).
- **QA gate green** (Task 5): host-vendor smoke chain (`pint`, `phpstan`, `phpmd`, `pest`) all exit 0; pest 14 tests / 26 assertions / **100% coverage on Plugin.php + AdapterRegistry + EventSubjectAdapter + ValueResolver**. The new `disallowedMethodCalls` rules are dormant against the current Phase 1 + 02-01 codebase (no code path in the 4 in-scope files calls SiteManager / Site / Request / request()) but live for plans 02-04 (SiteResolver), 02-05 (SendCapiEvent), 02-06 (event subscribers), and all Phase 3+ adapter code.

## Task Commits

| Task | Description | Commit | Type |
|------|-------------|--------|------|
| 1 | Spike — resolve Site / SiteManager FQN | (no commit — verification-only; output captured in `/tmp/site-fqn-spike.txt` and this SUMMARY) | — |
| 2 | Add disallowed-calls rules to phpstan.neon (H-1 disallowIn deny-list) | `791fe7b` | feat |
| 3 | Extend phpunit.xml — Contract testsuite + coverage to models + .gitkeep | `b12b2aa` | chore |
| 4 | Rank third-party hooks in CLAUDE.md; flag Component::extend as LAST RESORT (P-13) | `bb9295a` | docs |
| 5 | Run composer qa smoke (host vendor) — green | (no commit — QA gate only) | — |

`docs(02-02)` metadata commit ships separately with this SUMMARY.md + STATE.md + ROADMAP.md.

## Files Created/Modified

### Created (1)
- `tests/Contract/Adapter/.gitkeep` — empty placeholder; ensures the testsuite path resolves before Plan 02-07 populates the directory with FakeAdapterContractTest.

### Modified (3)
- `phpstan.neon` — adds 4 disallowedFunctionCalls + 3 disallowedMethodCalls entries with H-1 `disallowIn` deny-list scoped to lowercase `classes/queue/*`, `classes/event/*`, `classes/adapter/*`.
- `phpunit.xml` — Adapter testsuite + new Contract testsuite + source-include extends to `./classes` + `./models`.
- `CLAUDE.md` — "Extensibility contract" section rewritten as ordered list 1–6 with P-13 framing.

## Decisions Made

- **Verified Site facade FQN as `October\Rain\Support\Facades\Site`, NOT `October\Rain\Cms\Site`.** RESEARCH §5.1 listed the assumed FQN incorrectly. `vendor/october/rain/src/` contains no `Cms/` subdir (it has Assetic, Auth, Composer, Config, Database, Element, Events, Exception, Extension, Filesystem, Flash, Foundation, Halcyon, Html, Installer, Mail, Network, Parse, Resize, Router, Scaffold, Support, Translation, Validation — no Cms). The proper facade lives under `Support/Facades/` and delegates to `System\Classes\SiteManager` via `getFacadeAccessor() returns 'system.sites'`. Both FQNs banned in phpstan.neon belt-and-suspenders.
- **Plan paths normalized to lowercase per 02-01 deviation 1 carry-over.** PLAN.md PascalCase variants (`classes/Queue`, `classes/Event`, `classes/Adapter`) treated as folder-name typos; shipped as `classes/queue/*`, `classes/event/*`, `classes/adapter/*` to match October Rain ClassLoader's lowercase-folder convention. PSR-4 logical namespace remains PascalCase (`Logingrupa\Metapixel\Classes\Adapter\...`) — only filesystem paths and phpstan disallowIn globs use lowercase.
- **`disallowIn` deny-list chosen over `allowIn` allow-list (H-1 lock honored).** The original PLAN.md text mentioned both forms; the verified text and PLAN-CHECK R1 revision lock `disallowIn` per RESEARCH §5.1 verbatim. Rationale: `allowIn` requires explicitly listing every legitimate-call dir and silently fails-open if a new sibling dir is added — or worse, if `classes/helper/` is in the allowlist, the rule no-ops for SiteResolver where P-01 enforcement matters most. Deny-list pinpoints the high-risk dirs explicitly; defence-in-depth in `classes/helper/SiteResolver.php` comes from Plan 02-04 Task 3's regex grep test.
- **`./models` added to phpunit.xml `<source><include>` even though the dir does not yet exist on disk.** Pest 4 tolerates missing source dirs (contributes 0 lines to coverage rather than erroring). Pre-staging avoids re-editing phpunit.xml in plan 02-03a.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Lowercase plan paths for phpstan disallowIn glob patterns**

- **Found during:** Task 2 (writing phpstan.neon rules).
- **Issue:** PLAN.md interface block and final phpstan.neon shape both showed PascalCase paths (`classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*`). Plan 02-01 SUMMARY deviation 1 already documented that October Rain ClassLoader requires lowercase folder names — `classes/adapter/` is the actual on-disk directory.
- **Fix:** All four `disallowIn` arrays use lowercase: `classes/queue/*`, `classes/event/*`, `classes/adapter/*`. PHPStan matches glob patterns against filesystem paths, so lowercase is mandatory for the rules to fire when the actual code lands.
- **Files modified:** `phpstan.neon` (Task 2 commit `791fe7b`).
- **Verification:** Smoke phpstan run via host vendor binary against the new config exits 0 ("No errors"); the rules do not fire against the current codebase (none of Plugin.php, classes/adapter/AdapterRegistry.php, classes/adapter/EventSubjectAdapter.php, classes/adapter/ValueResolver.php call SiteManager / Site / Request / request()).
- **Rationale:** Carry-forward from 02-01 deviation 1. The carry_over_from_02_01 block in the executor prompt explicitly called this out as the expected adjustment.

**2. [Rule 3 — Block fix] tests/Contract/Adapter dir creation alongside phpunit.xml edit**

- **Found during:** Task 3 (extending phpunit.xml).
- **Issue:** Adding `<directory>./tests/Contract/Adapter</directory>` to the Adapter testsuite would point at a non-existent directory. Pest 4 tolerates missing test dirs (collects 0 tests) but the `.gitkeep` makes the intent explicit and ensures git tracks the directory pre-plan-02-07.
- **Fix:** `mkdir -p tests/Contract/Adapter && touch tests/Contract/Adapter/.gitkeep`. Staged alongside `phpunit.xml` in commit `b12b2aa`.
- **Files added:** `tests/Contract/Adapter/.gitkeep` (0 bytes).
- **Verification:** `ls -la tests/Contract/Adapter/` shows the dir + .gitkeep; phpunit.xml's `<directory>` element now resolves.
- **Rationale:** PLAN.md Task 5 explicitly anticipated this: "If composer test fails on plan 02-01 tests due to missing Contract/Adapter directory: Pest 4 tolerates empty directories. If it complains: create `tests/Contract/Adapter/.gitkeep` so the directory exists pre-plan-02-07." Created proactively in Task 3 to avoid the Task 5 fallback.

---

**Total deviations:** 2 auto-fixed (Rule 1 × 1, Rule 3 × 1)
**Impact on plan:** Both auto-fixes match documented expectations (carry-over from 02-01 SUMMARY; PLAN.md Task 5 anticipated fallback). No scope creep — every fix is inside the plan's stated artifact set.

## Issues Encountered

- **Plugin standalone-composer-install limitation persists** (carry-forward from Phase 1 and Plan 02-01). The plugin directory's own `vendor/bin` does not contain phpstan / pint / pest / phpmd because the plugin cannot run a standalone `composer install` — its `composer.json` requires October private packages that are not on a public registry. `composer qa` from inside `plugins/logingrupa/metapixel/` exits 127 ("pint: not found") at the first script. The 02-01 SUMMARY documented this and used host-vendor binaries (`/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}`) plus a smoke phpstan.neon variant with absolute paths at `/tmp/metapixel-phpstan-smoke.neon`. Plan 02-02 follows the same workaround — composer qa equivalent runs via host vendor, all 4 steps green. Full integration smoke (including composer-dependency-analyser) deferred to the CI matrix (`metapixel-qa.yml` workflow).
- **`xmllint` not installed on this system.** PLAN.md Task 3 verify used `xmllint --noout` — replaced with `php -r '$d = new DOMDocument(); echo $d->load("phpunit.xml")...'` which exits 0 and prints "OK: PHP DOMDocument parsed phpunit.xml successfully". Functionally equivalent XML well-formedness check.

## Self-Check: PASSED

- All 4 modified files (phpstan.neon, phpunit.xml, CLAUDE.md, tests/Contract/Adapter/.gitkeep) exist on disk under `plugins/logingrupa/metapixel/`.
- All 3 commit hashes (`791fe7b`, `b12b2aa`, `bb9295a`) present in `git log --oneline`.
- `phpstan.neon` contains all 4 required rules (3 `disallowedMethodCalls` + 1 `request()` in `disallowedFunctionCalls`) with `disallowIn` deny-list scoping the lowercase three dirs.
- `phpstan.neon` contains NO `allowIn:` allow-list (H-1 honored; grep on `^\s*allowIn:` returns empty).
- Host-vendor smoke chain green: pint passed; phpstan "No errors"; phpmd exit=0; pest 14/14 passed (26 assertions / 100% coverage on 4 in-scope production files).
- phpunit.xml is XML-well-formed (PHP DOMDocument parse successful) and contains all 5 required structural changes (Adapter testsuite includes tests/Contract/Adapter; Metapixel Contract Tests testsuite; ./classes coverage include; ./models coverage include; Plugin.php coverage include preserved).
- CLAUDE.md "Extensibility contract" section ranks hooks 1–6, flags Component::extend LAST RESORT, mandates `onMetapixel*` prefix, warns event_id mutation in before_dispatch.

## Verified FQNs (Task 1 spike output)

| Symbol | FQN | Verified at | Notes |
|---|---|---|---|
| SiteManager | `System\Classes\SiteManager` | `modules/system/classes/SiteManager.php` line 18 | extends `October\Rain\Extension\Extendable`; uses `October\Rain\Support\Traits\Emitter` |
| Site facade | `October\Rain\Support\Facades\Site` | `vendor/october/rain/src/Support/Facades/Site.php` line 48 | extends `October\Rain\Support\Facade`; `getFacadeAccessor()` returns `'system.sites'` |
| Global root alias | `\Site` | `vendor/october/rain/globals/Site.php` line 8 | `class Site extends October\Rain\Support\Facades\Site {}` |
| Cms namespace variant | (does not exist) | `vendor/october/rain/src/Cms/` is ABSENT | RESEARCH §5.1 assumed FQN `October\Rain\Cms\Site` is INCORRECT for this October build |

**FQN approach chosen:** belt-and-suspenders ban on BOTH `System\Classes\SiteManager::*` (the manager class) AND `October\Rain\Support\Facades\Site::*` (the proper facade). Global `\Site` alias resolves to the facade FQN through Laravel's AliasLoader — banning the facade FQN catches both `\Site::getActiveSiteId()` (global) and `\October\Rain\Support\Facades\Site::getActiveSiteId()` (namespaced) call forms. The Cms namespace variant from RESEARCH does not exist in this build; if a future October upgrade adds it, a Phase N follow-up can append the ban.

Spike log saved to `/tmp/site-fqn-spike.txt`.

## composer qa tail (host-vendor smoke run from `plugins/logingrupa/metapixel/`)

```
=== 1/4 pint-test (host vendor) ===
{"tool":"pint","result":"passed"}

=== 2/4 phpstan analyse (host vendor, level 10, phpVersion 80300) ===
 [OK] No errors

=== 3/4 phpmd Plugin.php,classes ===
phpmd exit=0

=== 4/4 pest --testsuite='Metapixel Unit Tests' --coverage --min=90 ===
  PASS  AdapterRegistryBootOrderTest
  ✓ resolution outcome is invariant across registration order

  PASS  AdapterRegistryFlushTest
  ✓ app forget instance re binds fresh singleton

  PASS  AdapterRegistryInvalidAdapterTest
  ✓ register throws when adapter class does not implement event subject adapter

  PASS  AdapterRegistrySingletonBindingTest
  ✓ singleton binding returns same instance
  ✓ app instance swaps fresh registry for test isolation

  PASS  AdapterRegistryTest
  ✓ register and resolve for returns adapter instance
  ✓ resolve for walks class hierarchy via is a
  ✓ resolve for returns null when subject not registered
  ✓ all returns list of registered adapter class names
  ✓ register same pair twice is idempotent
  ✓ resolve by class returns adapter instance by fqn

  PASS  PluginSanityTest
  ✓ plugin class loads via psr4 autoload
  ✓ plugin details returns lang keys under renamed namespace
  ✓ register and boot are callable without error

  Tests:    14 passed (26 assertions)
  Duration: 0.45s

  Plugin .............................................................. 100.0%
  classes/adapter/AdapterRegistry ..................................... 100.0%
  classes/adapter/EventSubjectAdapter ................................. 100.0%
  classes/adapter/ValueResolver ....................................... 100.0%
  ────────────────────────────────────────────────────────────────────────────
                                                                Total: 100.0 %
```

## Phase 2 plan-state update

Plan **02-02 CLOSED**. P-01 static enforcement is now live for the lowercase three dirs. Subsequent Phase 2 plans land code into those dirs and benefit immediately from the static-analysis guard:

- **02-03a (storage layer — migrations + EventLog + FailedEvent models + Settings + PluginGuard)** — UNBLOCKED (Wave 2 sequential predecessor). Models land in `models/` (now covered by phpunit `<source><include>`). Settings + PluginGuard land under `classes/helper/` or `classes/meta/` — NOT inside the deny-list scope, so no static-rule pressure on those classes for SiteManager calls (defence-in-depth via code review + Plan 02-04 Task 3 grep guard if applicable).
- **02-03b** — UNBLOCKED transitively on 02-03a.
- **02-04 (SiteResolver)** — UNBLOCKED transitively on 02-03a/b. SiteResolver lands under `classes/helper/` (NOT in the deny-list scope). The Plan 02-04 Task 3 static-source regex grep test on `SiteResolver.php` is the SiteResolver-specific defence-in-depth — it catches the cross-context bug the phpstan deny-list cannot reach by design.
- **02-05 (SendCapiEvent)** — UNBLOCKED transitively. Lands under `classes/queue/` — phpstan deny-list FIRES here. SendCapiEvent::handle MUST resolve site_id via the adapter (passed in via constructor `$obSubject`), not by calling SiteManager.
- **02-06 (event subscribers, before_dispatch / after_dispatch / dead_letter)** — UNBLOCKED transitively. Lands under `classes/event/` — phpstan deny-list FIRES here.
- **02-07 (FakeAdapterContractTest scaffold)** — UNBLOCKED transitively. Lands under `tests/Contract/Adapter/` — the testsuite is wired and `.gitkeep` is committed.

## Threat Flags

(none — phpstan.neon tightens enforcement; phpunit.xml extends test scope; CLAUDE.md is project lock prose. No new network endpoints, auth paths, file access patterns, or schema changes introduced.)

## Next Phase Readiness

- Plan **02-03a (storage layer)** is the next sequential plan in Wave 2; runs on master immediately after this plan's metadata commit.
- Plan 02-03a + 02-03b are NOT parallel — the prompt context notes "Plan 02-03a + 02-03b will run in parallel worktrees right after you", which the orchestrator owns. From the executor's perspective, control returns to the orchestrator after this plan's STATE/ROADMAP update.
- All Phase 2 plans landing code into `classes/queue/`, `classes/event/`, `classes/adapter/` MUST honor the new disallowed-calls rules. Tests for those dirs run under `Metapixel Adapter Tests` testsuite when applicable; the Contract testsuite is reserved for FakeAdapterContractTest (plan 02-07) and any third-party adapter contract subclasses thereafter.

---

*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan: 2*
*Completed: 2026-05-17*
