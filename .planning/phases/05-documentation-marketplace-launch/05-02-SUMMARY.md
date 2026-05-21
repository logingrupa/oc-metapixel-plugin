---
phase: 05-documentation-marketplace-launch
plan: 02
subsystem: theme-cleanup
tags: [legacy-strip, facebook-pixel, fbq, theme, inventory, cross-repo-blocker]

# Dependency graph
requires:
  - phase: 05-documentation-marketplace-launch
    plan: 00
    provides: Wave 0 RED-anchor tests + initial Phase 5 planning artifacts
provides:
  - .planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md — 6-section inventory of every legacy fbq emission site in themes/logingrupa-naisstore/ + dead v1.x purchasePixel refs + webpack bundle reach + theme settings yaml + 9-step strip order
affects: [05-03 UAT Gate 1 — blocked until strip lands in theme repo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cross-repo plan execution boundary: theme is independent git repo (logingrupa/oc-naisstore-theme) — plugin worktree cannot atomically commit theme-repo edits"
    - "Inventory-first strip pattern: enumerate every emission site in a markdown table BEFORE deleting, so each row's strip action is a discrete instruction the strip executor follows verbatim"
    - "Grep pattern matrix: 4 independent greps cover the 3 D-02 patterns (Twig inline, JS source, Twig dataLayer) PLUS the dead-v1.x purchasePixel alias scan"

key-files:
  created:
    - .planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md
  modified: []

key-decisions:
  - "Executed Task 0 (inventory authoring) only — Tasks 1–3 (theme repo strip + bundle rebuild) blocked by cross-repo execution boundary. The plan was authored assuming execution from /home/forge/nailscosmetics.lv/ project root with both plugin AND theme git repos in scope; the Wave 2 orchestrator spawned this executor in a plugin-only worktree (.claude/worktrees/agent-a7424cb7bdb3673bf) bound to the plugin repo's worktree-agent-* branch. Theme files at themes/logingrupa-naisstore/ live in an independent git repo (remote git@github.com:logingrupa/oc-naisstore-theme.git) which is neither tracked by this worktree nor merged by the orchestrator's worktree-merge step. Committing theme edits from this worktree would either (a) silently miss the theme repo entirely (the plugin's git index does not track ../../../../themes/...) or (b) modify the parent project's theme working tree without producing any commit that the worktree-merge would carry forward."
  - "Section 3 (Twig dataLayer dispatcher) intentionally has 0 rows — the grep found only google_analythics.htm (gtag/dataLayer) which is GA, not fbq. No-hit row count is the correct artifact."
  - "Section 4 (dead v1.x purchasePixel refs) shipped with 2 rows for order-complete.htm (lines 10–12 INI block + line 18 render) and 0 rows for order-complete-proforma.htm (verified via full file read — only [OrderPage] section exists)."
  - "checkout-form-validation.js line 2 (commented-out import of facebook-purchase-tracking) added to Section 2 as an explicit delete-line action even though it is already commented — D-23 sweep policy disallows commented-out dead-code references to deleted modules in the public-flip surface."
  - "Hardcoded live Pixel ID '2291486191076331' in facebook-purchase-tracking.js line 21 flagged in Section 2 as a D-26 sweep concern; strip removes it for free as a side-effect of file deletion. Inventory does NOT call for a separate D-26 history-rewrite — the credential is in the theme repo's git history, not the plugin repo's, and the theme is private + not subject to the public-flip per CONTEXT D-26 'Theme PII NOT in scope'."

patterns-established:
  - "Inventory-first strip pattern for legacy-feature removal: author a markdown table inventory committed separately BEFORE running any strip-and-rebuild steps. The inventory commit is permanent audit trail; the strip commit is reversible only via git revert. Two-commit shape per Tiger-Style 'small commits, one concern' (PROJECT.md). The shape is reusable for the v1.x reference strip across .planning/ docs (plan 05-11)."
  - "Cross-repo execution boundary signaling: when a plan's files_modified list spans multiple independent git repos, the executor MUST surface the boundary as a Rule 4 checkpoint blocker rather than silently committing into the working tree of an untracked repo. The orchestrator (NOT executor) decides whether to re-spawn with a multi-repo worktree, run the strip out-of-worktree as a manual step, or split the plan."

requirements-completed: []
requirements-partial: [DOCS-01]

# Metrics
duration: ~22min
completed: 2026-05-21
---

# Phase 05 Plan 02: Legacy Facebook Pixel Inventory + Strip Summary

**Plan 05-02 partially executed: Task 0 (inventory authoring) completed and committed to plugin worktree; Tasks 1–3 (theme repo strip + webpack bundle rebuild) blocked by a cross-repo execution boundary — the strip targets live in an independent theme git repo that this plugin-bound worktree cannot atomically commit.**

## Performance

- **Duration:** ~22 min wall (2026-05-21T10:29Z → 2026-05-21T10:51Z)
- **Started:** 2026-05-21T10:29:37Z
- **Tasks attempted:** 1 / 4 (Task 0 only)
- **Tasks blocked:** 3 / 4 (Tasks 1, 2, 3 — see "Cross-repo execution blocker" below)
- **Files created:** 1 (`.planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md`)
- **Files deleted (planned for Tasks 1–2):** 4 (3 JS tracking helpers + 1 facebook_pixel.htm partial) — NOT executed
- **Files edited (planned for Tasks 1–2):** 11 (4 layouts + 3 pages + 3 control JS callers + 1 configs/fields.yaml) — NOT executed
- **Bundle rebuild (planned for Task 3):** `pnpm run prod` — NOT executed

## Accomplishments

### Task 0 — Inventory authored

- `.planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md` shipped with 6 sections + 24 inventory table rows + 9-step strip order ordered list.
- Section 1 (Twig inline fbq): 8 rows — `partials/facebook_pixel.htm` (delete-file), 4 layout includes, 1 checkout fbq InitiatedCheckout block, 2 order-complete fbq trackCustom blocks.
- Section 2 (JS source emission): 9 rows — 3 tracking helper modules to delete + 3 caller files (add-to-cart, product-detail, search.js) to edit + 1 already-commented import in checkout-form-validation.js.
- Section 3 (Twig dataLayer): 0 rows — gtag/dataLayer hits in google_analythics.htm are GA, out of scope.
- Section 4 (dead v1.x purchasePixel): 2 rows — `pages/order-complete.htm` lines 10–12 (INI block) + line 18 (component render). Verified `order-complete-proforma.htm` does NOT contain a purchasePixel block (full file read).
- Section 5 (bundle reach): 3 unique `fbq("track","X")` strings in current `assets/js/common.js` (AddToCart, Search, ViewContent). Pre-strip bundle size: 217,741 bytes. Rebuild command documented.
- Section 6 (theme settings yaml): 2 rows — `facebook_pixel_id` (lines 447–451) + `facebook_domain_verification_id` (lines 452–456) entries in `configs/fields.yaml` to delete; `google_ga4_id` on lines 457–461 preserved (GA, out of scope).
- Strip order ordered list (9 numbered steps): delete leaf modules first → edit callers → delete partial → delete layout includes → delete page fbq blocks → delete purchasePixel refs → delete yaml fields → rebuild webpack → re-grep zero-hits gate.
- Plan's automated verification gate passes: `grep -cE '^\|'` returns 43 markdown table rows (≥ 15 threshold).

### Task 0 done-criterion check

- [x] Inventory file exists with ≥ 15 table rows total (actual: 43)
- [x] Section 4 (dead v1.x purchasePixel refs) has ≥ 2 rows (actual: 2)
- [x] Strip-order ordered list ends with "re-grep zero hits"
- [x] Inventory file committed before Task 1 attempt

### Tasks 1–3 — NOT executed (blocked, see below)

## Task Commits

Committed on branch `worktree-agent-a7424cb7bdb3673bf` (this worktree):

1. **Task 0: Inventory legacy fbq sites + write 05-02-LEGACY-INVENTORY.md** — `ee91468` (docs)

No Task 1, Task 2, or Task 3 commit shipped — see "Cross-repo execution blocker" below.

## Files Created/Modified

### Created

- `.planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md` — 6-section inventory + 24-row markdown table + 9-step strip order ordered list + cross-repo boundary note. 228 lines.

### Modified

None.

### Deleted

None (Tasks 1–2 planned deletions did NOT execute).

## Cross-repo execution blocker (Tasks 1–3 NOT executed)

### What the plan asks for

Tasks 1–3 modify 15 files under `themes/logingrupa-naisstore/`:

- DELETE 4 files: `partials/facebook_pixel.htm`, `partials/shared/tracking/facebook-add-to-cart.js`, `partials/shared/tracking/facebook-view-content.js`, `partials/form/checkout-form/tracking/facebook-purchase-tracking.js`.
- EDIT 11 files: 4 layouts (`main.htm`, `content.htm`, `light.htm`, `catalog_default.htm`), 3 pages (`checkout.htm`, `order-complete.htm`, `order-complete-proforma.htm`), 3 control JS callers (`add-to-cart-control.js`, `product-detail-control.js`, `search.js`, plus `checkout-form-validation.js` for the commented import), 1 yaml (`configs/fields.yaml`), 1 webpack bundle output (`assets/js/common.js` rebuilt).

The plan commits these as a single `feat(05-02)` commit "alongside" the Task 0 `docs(05-02)` inventory commit per Tiger-Style "small commits, one concern".

### Why it cannot land from this worktree

1. **Theme is an independent git repo** at `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/` with its own `.git/` directory and its own remote `git@github.com:logingrupa/oc-naisstore-theme.git`. Verified: `cd themes/logingrupa-naisstore && git status` reports `On branch master, 2 commits ahead of origin/master, clean`. The theme repo is NOT a git submodule of the parent project (`cat .gitmodules` → no such file) and NOT a sub-tree of the plugin repo (`git ls-files themes/` from inside this worktree → empty).

2. **This worktree is bound to the plugin repo only.** `git rev-parse --show-toplevel` from the worktree returns `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/.claude/worktrees/agent-a7424cb7bdb3673bf`. `git remote -v` returns `origin git@github.com:logingrupa/oc-metapixel-plugin.git`. The worktree's per-agent branch (`worktree-agent-a7424cb7bdb3673bf`) is created from the plugin repo's master, NOT from any composite multi-repo tree.

3. **`.planning/config.json` has no `sub_repos` configuration.** The `commit-to-subrepo` SDK route is not applicable. The parallel-executor contract assumes a single-repo worktree.

4. **The orchestrator's worktree-merge step merges only the plugin worktree branch back to the plugin's master.** Any modifications made to `themes/logingrupa-naisstore/` from inside this worktree would (a) persist on the parent project's working tree (not in any commit) OR (b) be committed into the theme repo's local master from inside a `cd themes/logingrupa-naisstore && git commit` invocation — but neither of those is what the orchestrator merges, and (b) bypasses the worktree-isolation guarantee entirely.

5. **State.md context line 145 acknowledges the boundary:** "Legacy-strip plans modify `themes/logingrupa-naisstore/` (theme is a sibling directory under `themes/`, not under `plugins/logingrupa/metapixel/`). Plan files reference theme paths with absolute project paths." This was authored at planning time — the planner knew the theme is sibling — but the worktree-spawn orchestrator did not propagate this multi-repo scope to the Wave 2 executor.

### What the executor would have done if scope allowed

The strip and bundle work is fully scoped: Tasks 1–3 read entirely from the 05-02-LEGACY-INVENTORY.md authored in Task 0. Each row's `strip action` column is a discrete `git rm` or line-range delete. No code logic to write, no decisions to make. A successor that runs from the project root with both repos in scope can execute Tasks 1–3 mechanically using the inventory as the per-step playbook.

### Recommended resolution path (operator / orchestrator decision)

Three options, ranked by least-disruption first:

1. **Re-spawn this plan from the project root** (`/home/forge/nailscosmetics.lv/`), NOT from a plugin worktree. The strip + bundle commit lands in the theme repo's `master`; this inventory commit is already on the plugin worktree's branch and will merge to the plugin's master on the next orchestrator merge. Two commits in two repos, but each commit lives where it belongs.

2. **Run Tasks 1–3 as an out-of-worktree manual operation** after this plan's plugin-side inventory commit merges. The operator opens a normal terminal at the project root, follows the 9-step strip order in `05-02-LEGACY-INVENTORY.md` verbatim, runs `pnpm run prod`, commits to the theme repo. The plugin worktree is closed via the standard merge path.

3. **Split plan 05-02 into 05-02a (inventory, plugin repo) + 05-02b (strip, theme repo).** Re-plan via `/gsd:plan-phase` so the orchestrator can spawn 05-02b against the theme repo's worktree. This is the cleanest architectural fix but the slowest.

UAT Gate 1 (plan 05-03) cannot fire until the theme-side strip + bundle commit lands. The bundle still contains `fbq("track","AddToCart")`, `fbq("track","Search")`, `fbq("track","ViewContent")` and the page-load surface still emits PageView via `partials/facebook_pixel.htm`. The dead `[purchasePixel]` INI block on `order-complete.htm` line 10 will 500-error the page on the next deploy of the plugin (post Phase 2 close + post Wave-2 merge) because Plugin.php registers only `eventPixel` + `pixelHead`.

## Decisions Made

- **Author Task 0 inventory inside the plugin worktree even though Tasks 1–3 are blocked.** The inventory file is the prerequisite for any strip execution (whether re-spawned, manual, or split-plan) — shipping it early prevents the next executor from re-running the same 4 greps. The inventory commit lives in the plugin repo's `.planning/` tree by design (per the plan's `files_modified` declaration).
- **Surface the cross-repo blocker as a SUMMARY-level note, NOT as a checkpoint-return back to the orchestrator.** The parallel-executor contract requires SUMMARY.md to be committed before any narrative output; a checkpoint return after SUMMARY commit would conflict with the "commit then return" ordering. The orchestrator reads SUMMARY.md "Cross-repo execution blocker" section directly from this artifact during the post-merge audit.
- **Do NOT attempt theme-repo edits from this worktree.** Per destructive-git-prohibition rules: `git add`/`git commit` invocations that span uncontained repos would either silently fail (theme files are not tracked from this worktree's perspective) or — worse — succeed in the parent project's working tree without producing a git commit, leaving uncommitted theme modifications drifting in the operator's working tree post-worktree-cleanup. Neither outcome is recoverable via the orchestrator's merge step.
- **Do NOT modify STATE.md or ROADMAP.md** per the parallel-execution contract — the orchestrator owns those writes after all worktree agents in this wave complete.

## Deviations from Plan

### [Rule 4 — Architectural] Cross-repo execution boundary discovered at Task 1 entry

- **Found during:** Task 0 verification step (`git status` confirmed theme files are not tracked from this worktree; `cd themes/logingrupa-naisstore && git remote -v` confirmed independent remote).
- **Issue:** Plan 05-02 `files_modified` lists 15 paths under `themes/logingrupa-naisstore/` — but the theme is an independent git repo with its own remote, NOT a subdirectory of the plugin repo this worktree is bound to. Executing the strip from inside this worktree would either silently miss the theme entirely or commit into a repo the orchestrator's merge step does not touch.
- **Fix:** Stop after Task 0; document the architectural mismatch in this SUMMARY. Tasks 1–3 remain unexecuted. The orchestrator must decide between re-spawn / manual-run / split-plan resolutions documented above.
- **Files modified:** None (Task 0 inventory file already committed).
- **Commit:** N/A — Tasks 1–3 not committed.

## TDD Gate Compliance

Plan 05-02 frontmatter declares `type: execute` (not `type: tdd`); no RED/GREEN/REFACTOR commit sequence enforcement applies. Task 0 commit is `docs(...)` per the plan's draft commit message.

## Issues Encountered

- **Worktree scope mismatch with plan target.** See "Cross-repo execution blocker" above. This is the only material issue; all other plan inputs (greps, file reads, Plugin.php component registration verification, `pnpm run prod` rebuild path) work as expected when run from the parent project's working tree.

- **Pre-existing webpack-output staleness (informational, not blocking):** `themes/logingrupa-naisstore/assets/js/common.js` shows mtime 2026-05-13 17:44 in the live theme working tree — older than several theme commits that have landed since. The post-strip rebuild in Task 3 will refresh both content and mtime. Operators running plan 05-03's UAT Gate 1 should clear browser cache + reload bundle before measuring.

## Self-Check: PASSED

**File exists on disk (worktree-relative):**

- FOUND: `.planning/phases/05-documentation-marketplace-launch/05-02-LEGACY-INVENTORY.md` (228 lines)

**File contains expected sections:**

- FOUND: `## Section 1 — Pattern 1: Inline fbq` (8 rows)
- FOUND: `## Section 2 — Pattern 2: JS source emission` (9 rows)
- FOUND: `## Section 3 — Pattern 3: Twig dataLayer dispatcher` (0 rows — gtag/GA only)
- FOUND: `## Section 4 — Dead v1.x purchasePixel component references` (2 rows)
- FOUND: `## Section 5 — Webpack bundle reach` (3 unique fbq("track",X) strings)
- FOUND: `## Section 6 — Theme settings cleanup` (2 rows)
- FOUND: `## Strip order for Tasks 1–3` ordered list (9 steps, ends with re-grep zero hits)
- FOUND: `## Cross-repo execution boundary` (artifact's own boundary note)

**Plan automated gate `grep -cE '^\|' >= 15`:** PASS (43 rows)

**Plan done criterion "Section 4 ≥ 2 rows":** PASS (2 rows)

**Commits exist:**

- FOUND: `ee91468` (Task 0 — docs(05-02): legacy Facebook Pixel inventory across themes/logingrupa-naisstore (strip prerequisite))

**Tasks 1–3:** NOT committed (blocked — see Cross-repo execution blocker).

## Next Phase Readiness

- **Plan 05-03 (UAT Gate 1 — zero pixel events on theme):** **BLOCKED** until the strip + bundle commit lands in the theme repo. Without the strip, Pixel Helper Chrome extension will still show PageView + AddToCart + Search + ViewContent events firing from the legacy bundle, and `order-complete.htm` will 500-error on next plugin deploy due to the dead `[purchasePixel]` alias.
- **Plan 05-04 (PixelHead layout wire):** Sequenced after 05-03 — blocked by the same cascade.
- **Plan 05-06 (EventPixel per-event wire):** Sequenced after 05-04 — blocked by the same cascade.
- **Recommended next action:** orchestrator decides between (1) re-spawn 05-02 from project root, (2) operator runs Tasks 1–3 manually from project root using `05-02-LEGACY-INVENTORY.md` as playbook, or (3) split plan into 05-02a (this commit) + 05-02b (theme-side, future spawn). Option 2 is fastest and shipped here as a side-effect of the inventory's "Strip order for Tasks 1–3" ordered list.

---

*Phase: 05-documentation-marketplace-launch*
*Plan: 02*
*Completed: 2026-05-21 (partial — Task 0 only; Tasks 1–3 blocked)*
