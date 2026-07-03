---
phase: 5
plan: 13
plan_id: 05-13
type: execute
wave: 9
depends_on: [05-09, 05-10, 05-11, 05-12]
files_modified:
  - .planning/phases/05-documentation-marketplace-launch/05-13-SECURITY-SWEEP.md
  - .planning/STATE.md
  - .planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md
  - .planning/phases/05-documentation-marketplace-launch/05-DISCUSSION-LOG.md
  - .planning/research/PITFALLS.md
autonomous: false
threat_refs: [T-05-01, T-05-02]
requirements: []

# Plan 05-13 is a release-blocker pre-flip security sweep gate. It is intentionally NOT
# tagged with the MKT-01 requirement (B-1 fix). MKT-01 = "Composer install on clean OctoberCMS
# completes without errors" — its acceptance test is the `composer require` smoke in plan
# 05-14 Task 3 Step F, NOT the secret-history grep in this plan. The earlier tagging
# conflated "release-blocker prerequisite for the public flip that enables MKT-01" with
# "covers MKT-01 acceptance". Only plan 05-14 satisfies MKT-01.

must_haves:
  truths:
    - "`git log --all -p | grep -iE 'pixel_id\\s*[=:]\\s*[0-9]{10,}|access_token\\s*[=:]\\s*EAA[A-Za-z0-9]{20,}' | grep -vE '1234567890|000000000000000|REDACTED_FOR_DEMO|placeholder'` returns EMPTY across the full history (Pitfall 1 mitigation)"
    - "`grep -rnE 'new\\.nailscosmetics\\.lv|forge\\.laravel\\.com|\\b10\\.[0-9]|\\b192\\.168\\.' .planning/` (excluding archive/) returns ZERO operator-specific infra hits OR every remaining hit is documented + accepted in 05-13-SECURITY-SWEEP.md"
    - "`git ls-remote --tags origin 'v1*'` returns empty (D-24 lock — legacy tag stays local-only)"
    - "`git ls-remote --heads origin 'legacy/*'` returns empty (D-24 lock — legacy branch stays local-only)"
    - "git-filter-repo installed OPPORTUNISTICALLY only if Step A grep returns hits (per Open Question 3 RESOLVED in 05-RESEARCH.md — `pip install --user git-filter-repo` runs only on demand; if grep clean, filter-repo never invoked)"
    - "Sweep findings recorded in 05-13-SECURITY-SWEEP.md — every grep run + result + redaction decision + commit SHA of any cleanup"
    - "Post-sweep `.planning/` ships in public repo per D-27 (NOT gitignored, NOT split). Sweep removes operator-specific infra refs only."
  artifacts:
    - path: ".planning/phases/05-documentation-marketplace-launch/05-13-SECURITY-SWEEP.md"
      provides: "Operator-signed sweep audit trail per D-26"
    - path: ".planning/STATE.md (redacted)"
      provides: "Operator-specific infra refs redacted to 'your-staging-host.example' OR removed"
    - path: ".planning/phases/05-.../05-CONTEXT.md (redacted)"
      provides: "Same redaction strategy"
    - path: ".planning/phases/05-.../05-DISCUSSION-LOG.md (redacted)"
      provides: "Same"
    - path: ".planning/research/PITFALLS.md (redacted)"
      provides: "Same"
  key_links:
    - from: ".planning/STATE.md, 05-CONTEXT.md, 05-DISCUSSION-LOG.md, research/PITFALLS.md"
      to: "(redacted public-shipped surface)"
      via: "grep + sed redact OR delete-line"
      pattern: "new\\.nailscosmetics\\.lv|forge\\.laravel\\.com|\\b10\\.[0-9]|\\b192\\.168\\."
---

<objective>
Pre-flip security sweep per D-26 + Pitfall 1. Two scans: (1) secrets in git history; (2) operator-specific infra refs in `.planning/`. Both verified empty/redacted before plan 05-14 flips the repo public. Legacy tag + branch confirmed local-only (D-24). Without this PASS, plan 05-14 MUST NOT execute.

This plan is a release-blocker gate — NOT tagged with MKT-01 (per B-1 fix). MKT-01 acceptance lives in plan 05-14 Task 3 Step F (unauth `composer require` smoke).

Per `05-RESEARCH.md ## Open Questions (RESOLVED) Q3`, git-filter-repo is installed OPPORTUNISTICALLY at Task 1 Step A — only if the secret-history grep returns hits. If grep clean (RESEARCH 2026-05-21 baseline shows zero real secrets), filter-repo is never invoked.

Output: `05-13-SECURITY-SWEEP.md` audit log + redacted `.planning/` docs. Resume signal: `SWEEP PASS`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md
@.planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md
@.planning/phases/05-documentation-marketplace-launch/05-PATTERNS.md
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| git history → public clones | Once public, every commit mirrored to clone-aggregators + search indexes |
| `.planning/` docs → public readers | `.planning/` ships per D-27 — strip operator-specific infra refs |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-01 | Information Disclosure | real EAA-prefix CAPI token in any historical commit | mitigate | `git log --all -p` regex sweep; if hit → `git filter-repo --replace-text` + force-push BEFORE flip; current scan (2026-05-21) shows only dummy + test fixtures |
| T-05-02 | Information Disclosure | real Pixel ID (10+ digit non-dummy) in git history | mitigate | Same as T-05-01 |
| T-05-13-01 | Information Disclosure | your-staging-host.example / forge.laravel.com / private IPs in `.planning/` | mitigate | grep + redact pass; baseline 12 hits in 4 files (RESEARCH); redact each to placeholder OR delete |
| T-05-13-02 | Repudiation | legacy v1.x tag/branch accidentally pushed | mitigate | `git ls-remote --tags origin 'v1*'` + `git ls-remote --heads origin 'legacy/*'` MUST both return empty before flip |
</threat_model>

<tasks>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 1: Run security sweep recipe (opportunistic git-filter-repo) + author 05-13-SECURITY-SWEEP.md</name>
  <read_first>
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md lines 442-456 (Pitfall 1 — public-flip without history sweep)
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md lines 777-799 (Example 4 — pre-flip security sweep recipe)
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md `## Open Questions (RESOLVED)` Q3 (RESOLVED: install git-filter-repo opportunistically only on grep hit; if clean, never invoked)
    - .planning/phases/05-documentation-marketplace-launch/05-CONTEXT.md D-24 + D-26 (locked sweep scope)
    - .planning/phases/05-documentation-marketplace-launch/05-PATTERNS.md lines 633-645 (sweep recipe applied to STATE.md redaction)
  </read_first>
  <what-built>
    Plans 05-09, 05-10, 05-11, 05-12 shipped README + CUSTOM-ADAPTERS + decorator strip + planning-doc rewrite + CHANGELOG + screenshots + manifest review. Repository is candidate-for-public-flip. This plan runs the irreversibility gate.
  </what-built>
  <how-to-verify>
    Operator steps:

    Step A — Secret-history scan. From plugin root:
    ```bash
    cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel
    git log --all -p 2>&1 | grep -iE 'pixel_id\s*[=:]\s*[0-9]{10,}|access_token\s*[=:]\s*EAA[A-Za-z0-9]{20,}|capi_access_token\s*[=:]\s*EAA[A-Za-z0-9]{20,}' | grep -vE '1234567890|000000000000000|REDACTED_FOR_DEMO|placeholder' | tee /tmp/05-13-secret-hits.txt
    ```
    Expected output: empty file.

    **Opportunistic git-filter-repo install (Open Question 3 RESOLVED):**
    - If `/tmp/05-13-secret-hits.txt` is empty → SKIP filter-repo. The tool is not required and is not installed. Note `git-filter-repo: not invoked (grep clean)` in the sweep doc.
    - If ANY line is captured → STOP. Run `pip install --user git-filter-repo` (or `apt install git-filter-repo` if available). Prepare an `expressions.txt` file mapping each leaked secret to `REDACTED`. Run `git filter-repo --replace-text expressions.txt`. Coordinate with operator BEFORE force-push — operator's local clones must `git fetch --all && git reset --hard origin/master` after. Re-verify with the same grep until empty.

    Step B — Operator-infra refs in `.planning/`. From repo root:
    ```bash
    cd /home/forge/nailscosmetics.lv
    grep -rnE 'new\.nailscosmetics\.lv|forge\.laravel\.com|\b10\.[0-9]+\.[0-9]+\.[0-9]+\b|\b192\.168\.[0-9]+\.[0-9]+\b' plugins/logingrupa/metapixel/.planning/ | grep -v archive/ | tee /tmp/05-13-infra-hits.txt
    ```
    Baseline: 12 hits across 4 files (RESEARCH 2026-05-21). For each hit decide:
      - Keep (operator judges load-bearing AND not a secret) — record rationale
      - Redact (replace `your-staging-host.example` with `your-staging-host.example` — RFC 2606)
      - Delete the line (purely operator-internal)

    Apply redactions via `sed -i 's/new\.nailscosmetics\.lv/your-staging-host.example/g' <file>` OR Edit tool.

    Step C — D-24 archive-stays-local verification:
    ```bash
    git ls-remote --tags origin 'v1*' | tee /tmp/05-13-remote-v1-tags.txt
    git ls-remote --heads origin 'legacy/*' | tee /tmp/05-13-remote-legacy-branches.txt
    ```
    Both files MUST be empty. If either has output, operator-coordinated `git push origin --delete <ref>` before continuing.

    Step D — Author `.planning/phases/05-documentation-marketplace-launch/05-13-SECURITY-SWEEP.md`:

    ```
    # Pre-Flip Security Sweep — 05-13

    **Date:** YYYY-MM-DD HH:MM
    **Operator:** <name>
    **HEAD SHA at sweep start:** <sha>

    ## Step A — Secret-history grep

    Command: <command>
    Hits: 0  (or: N hits, see below)
    git-filter-repo: not invoked (grep clean)  |  installed via `pip install --user git-filter-repo` + rewrite commit SHA <...>
    Disposition: <empty | filter-repo rewrite required>

    ## Step B — Operator-infra grep

    Command: <command>
    Hits before redaction: N
    Per-hit disposition (file:line — keep|redact|delete + rationale):
    - .planning/STATE.md:42 — redact "your-staging-host.example" → "your-staging-host.example"
    - …

    Hits after redaction: 0  (or: M kept hits, all documented above)

    ## Step C — Legacy archive local-only verification

    git ls-remote --tags origin 'v1*': empty  ✓
    git ls-remote --heads origin 'legacy/*': empty  ✓

    ## Step D — Final pre-flip gates

    git status: clean
    git log --oneline -1: <smoke-validated SHA from plan 05-08>
    composer qa: exit 0

    ## Overall verdict

    SWEEP PASS  (or  SWEEP FAIL — see Steps above)
    ```

    Commit:
    ```
    chore(05-13): pre-flip security sweep — history clean, infra refs redacted (D-26)
    ```
  </how-to-verify>
  <resume-signal>
    Type `SWEEP PASS` to advance to plan 05-14. If FAIL, describe finding + cleanup commit SHA. If history rewrite occurred, also confirm operator's local clones were re-fetched.
  </resume-signal>
</task>

</tasks>

<verification>
- `05-13-SECURITY-SWEEP.md` exists, signed, with empty/zero results in Steps A/B/C
- `.planning/STATE.md`, `05-CONTEXT.md`, `05-DISCUSSION-LOG.md`, `research/PITFALLS.md` redacted per per-hit dispositions
- `git ls-remote --tags origin 'v1*'` empty
- `git ls-remote --heads origin 'legacy/*'` empty
- composer qa exit 0
- Resume signal `SWEEP PASS`
- git-filter-repo install decision (invoked vs skipped) documented in sweep doc
</verification>

<success_criteria>
- Zero real secrets in git history (or filter-repo rewrite executed + verified)
- Zero operator-infra refs in `.planning/` outside archive/ OR every kept hit documented
- Legacy archive confirmed local-only
- Repository is in candidate-for-public-flip state
</success_criteria>

<output>
Create `.planning/phases/05-documentation-marketplace-launch/05-13-SUMMARY.md` when done — note Step A/B/C results, redacted file paths, sweep commit SHA, "READY FOR PUBLIC FLIP (plan 05-14)" handoff.
</output>
</content>
