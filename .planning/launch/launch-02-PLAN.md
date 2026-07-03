---
phase: 5
plan: 14
plan_id: 05-14
type: execute
wave: 10
depends_on: [05-13]
files_modified:
  - composer.json
  - .planning/phases/05-documentation-marketplace-launch/05-14-LAUNCH-LOG.md
autonomous: false
threat_refs: [T-05-04]
requirements: [MKT-01, MKT-04, MKT-05]

must_haves:
  truths:
    - "composer.json `license` field decided + set (operator picks MIT or proprietary per Deferred Idea + Open Question 2 — default recommendation MIT)"
    - "GitHub repo logingrupa/oc-metapixel-plugin flipped from private to public (`gh repo edit --visibility public --accept-visibility-change-consequences` or browser UI)"
    - "Annotated tag v2.0.0 created from master HEAD at the smoke-validated commit (D-21): `git tag -a v2.0.0 -m 'v2.0.0 — generic-event-tracking marketplace plugin'`"
    - "Tag pushed to origin: `git push origin v2.0.0`"
    - "`git for-each-ref refs/tags/v2.0.0 --format='%(objecttype)'` returns `tag` (annotated — NOT lightweight)"
    - "`git describe --tags --exact-match HEAD` returns `v2.0.0` (Pitfall 6 mitigation — tag points at HEAD)"
    - "Composer VCS install from `/tmp/test-install` UNAUTHENTICATED clone succeeds (Pitfall 2 mitigation): scratch dir + composer init + VCS block + composer require exit 0"
    - "CI matrix re-verified GREEN on the v2.0.0 tag commit (MKT-05 — composer qa exit 0 on Run A full-Lovata AND Run B minimal)"
    - "05-14-LAUNCH-LOG.md captures every step, command, SHA, screenshot of GitHub visibility setting, public-clone install proof"
  artifacts:
    - path: ".planning/phases/05-documentation-marketplace-launch/05-14-LAUNCH-LOG.md"
      provides: "Operator-signed launch audit trail"
    - path: "composer.json (license field set)"
      provides: "Operator-decided license per Open Question 2"
    - path: "git tag v2.0.0 (annotated, pushed)"
      provides: "MKT-04 — annotated tag from master at smoke-validated commit"
    - path: "GitHub repo visibility = public"
      provides: "MKT-01 path — buyer composer require works without auth"
  key_links:
    - from: "GitHub repo visibility = public"
      to: "composer require logingrupa/oc-metapixel-plugin"
      via: "Composer VCS repository pattern (D-25)"
      pattern: "(buyer install path)"
    - from: "git tag v2.0.0"
      to: "v2.0.0 annotated artifact"
      via: "git tag -a + git push origin v2.0.0"
      pattern: "annotated tag"
---

<objective>
Final atomic launch sequence: decide license, flip GitHub repo to public, create + push annotated v2.0.0 tag, verify Composer VCS install works unauthenticated, confirm CI matrix green on the tag commit. This is the irreversible launch action. After this plan, the plugin is publicly installable.

Purpose: MKT-01 + MKT-04 + MKT-05 deliverables. Launch is a single coordinated sequence with named pre-flight checks (Pitfall 6 — wrong-SHA tag; Pitfall 2 — unauth install fail). DOCS-01 timed dry-run can run independently AFTER this plan as the launch acceptance gate.

Output: composer.json license set, GitHub repo public, v2.0.0 tag pushed, `05-14-LAUNCH-LOG.md` audit trail with install proof. Resume signal: `LAUNCH COMPLETE`.
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
@.planning/phases/05-documentation-marketplace-launch/05-13-SUMMARY.md
@plugins/logingrupa/metapixel/composer.json
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| github settings UI → public visibility | Atomic, observable to all internet — clones permanent within seconds |
| git tag → operator credentials | `git push origin v2.0.0` requires operator's GitHub auth |
| composer VCS install → buyer | Composer fetches from `https://github.com/logingrupa/oc-metapixel-plugin` over HTTPS |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-04 | Tampering | composer VCS install fetched over HTTPS | accept | Composer rejects http:// VCS by default; URL pinned to GitHub HTTPS |
| T-05-14-01 | Repudiation | wrong-SHA tag created (Pitfall 6) | mitigate | Pre-flight check Step B verifies `git log --oneline -1` matches the smoke-validated SHA from 05-08-SUMMARY.md BEFORE tag creation |
| T-05-14-02 | Denial of Service | private-repo composer require fails for buyer (Pitfall 2) | mitigate | Step F unauth-install smoke test from /tmp scratch verifies before signaling launch complete |
| T-05-14-03 | Information Disclosure | annotated tag message accidentally embeds operator-specific info | accept | Tag message locked per D-21: `v2.0.0 — generic-event-tracking marketplace plugin` |
</threat_model>

<tasks>

<task type="checkpoint:decision" gate="blocking">
  <name>Task 1: Operator decides composer.json license</name>
  <decision>
    Set composer.json `license` field for the v2.0.0 launch.
  </decision>
  <context>
    Current state: `"license": "proprietary"`. D-25 flips the repo public; Open Question 2 in RESEARCH defers the license re-evaluation to this plan. RESEARCH recommendation: `MIT` for ecosystem-standard composer-installable plugins. Operator may choose `proprietary` to retain commercial control even with public source.
  </context>
  <options>
    <option id="mit">
      <name>MIT</name>
      <pros>Ecosystem-standard for OctoberCMS marketplace plugins (Lovata plugins use various — verify); permissive; encourages adoption; widely understood</pros>
      <cons>Allows third-party forks + commercial redistribution; no requirement for attribution beyond MIT-standard copyright notice</cons>
    </option>
    <option id="proprietary">
      <name>proprietary</name>
      <pros>Retains commercial control even with public source code; matches current composer.json state; signals "license terms via separate agreement"</pros>
      <cons>Some Composer tooling warns on proprietary; potential buyer friction; ambiguous semantics for public-source-but-restricted scenarios</cons>
    </option>
    <option id="apache-2.0">
      <name>Apache-2.0</name>
      <pros>Permissive like MIT but includes explicit patent grant; common in commercial open-source</pros>
      <cons>Slightly heavier than MIT; less common in October ecosystem</cons>
    </option>
  </options>
  <resume-signal>
    Select: `mit`, `proprietary`, or `apache-2.0`.
  </resume-signal>
</task>

<task type="auto">
  <name>Task 2: Set composer.json license per operator decision + commit</name>
  <read_first>
    - plugins/logingrupa/metapixel/composer.json (current state)
    - operator's decision from Task 1 resume signal
  </read_first>
  <action>
    Edit `composer.json`. Set the `license` field value per operator decision: `MIT`, `proprietary`, or `Apache-2.0` (exact SPDX identifier — use the canonical SPDX casing). Verify with `php -r 'json_decode(file_get_contents("composer.json"),true);'` exit 0.

    If the chosen license is MIT or Apache-2.0, also create a `LICENSE` file at plugin root with the standard SPDX text (operator confirms exact copyright holder name; default `Logingrupa`). For `proprietary`, no LICENSE file is required — composer accepts the SPDX identifier alone.

    Commit:
    ```
    chore(05-14): set composer.json license to <chosen> (+ LICENSE file if MIT/Apache-2.0)
    ```
  </action>
  <verify>
    <automated>cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel && php -r '$j=json_decode(file_get_contents("composer.json"),true); echo isset($j["license"]) ? "ok-license-".$j["license"] : "fail"; echo PHP_EOL;'</automated>
  </verify>
  <done>
    composer.json license field set; composer.json remains valid JSON. LICENSE file present if MIT/Apache-2.0. Commit landed.
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: Pre-flight check + flip GitHub repo to public + tag v2.0.0 + push + verify unauth install</name>
  <read_first>
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md lines 802-836 (Example 5 — public flip + tag flow)
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md lines 525-540 (Pitfall 6 — annotated tag from wrong commit)
    - .planning/phases/05-documentation-marketplace-launch/05-RESEARCH.md lines 459-475 (Pitfall 2 — composer VCS install fails because repo still private)
    - .planning/phases/05-documentation-marketplace-launch/05-08-SUMMARY.md (smoke-validated commit SHA — must equal current HEAD or cleanup commits documented)
    - .planning/phases/05-documentation-marketplace-launch/05-13-SUMMARY.md (sweep PASS confirmation)
  </read_first>
  <what-built>
    composer.json license set (Task 2). All Phase 5 plans 05-00 through 05-13 complete. Sweep clean. Repository is candidate-for-launch.
  </what-built>
  <how-to-verify>
    Step A — Pre-flight from plugin root:
    ```bash
    cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel
    git switch master
    git pull origin master
    git status  # MUST be clean
    git log --oneline -5  # cross-reference top commit against 05-08-SUMMARY.md smoke-validated SHA
    composer qa  # MUST exit 0 — last green chain before tag
    ```
    If HEAD differs from the smoke-validated SHA from plan 05-08, that's because plans 05-09..05-13 added cleanup commits AFTER smoke. Confirm those commits are docblock/manifest/doc-only (no behavior change) by inspecting `git log --stat <smoke-sha>..HEAD` — every changed file should be in {README.md, CHANGELOG.md, composer.json, docs/, .planning/, lang/, plugin.yaml, or docblock-only PHP edits}. If a non-doc PHP behavior change is present, run an abbreviated smoke (PageView only, fastest path) before tagging.

    Step B — Sanity check legacy archive stays local (D-24):
    ```bash
    git ls-remote --tags origin 'v1*'   # MUST be empty
    git ls-remote --heads origin 'legacy/*'   # MUST be empty
    ```

    Step C — Flip GitHub repo to public (D-25 atomic action):
    ```bash
    gh repo edit logingrupa/oc-metapixel-plugin --visibility public --accept-visibility-change-consequences
    ```
    OR via browser: GitHub → repo Settings → General → Danger Zone → Change repository visibility → Make public → confirm.
    Verify:
    ```bash
    gh repo view logingrupa/oc-metapixel-plugin --json visibility
    # expected: {"visibility":"public"}
    ```
    Open `https://github.com/logingrupa/oc-metapixel-plugin` in an incognito browser window — must load without sign-in.

    Step D — Create annotated tag v2.0.0:
    ```bash
    git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"
    ```
    Verify annotated (not lightweight):
    ```bash
    git for-each-ref refs/tags/v2.0.0 --format='%(objecttype)'   # expected: tag
    git describe --tags --exact-match HEAD                       # expected: v2.0.0
    ```

    Step E — Push tag to origin:
    ```bash
    git push origin v2.0.0
    ```
    Verify:
    ```bash
    git ls-remote --tags origin v2.0.0   # expected: non-empty, refs/tags/v2.0.0
    ```

    Step F — Unauth-install smoke (Pitfall 2 mitigation):
    ```bash
    cd /tmp && rm -rf test-install && mkdir test-install && cd test-install
    cat > composer.json <<'EOF'
    {
      "name": "test/install-verify",
      "minimum-stability": "stable",
      "repositories": [
        {"type":"vcs","url":"https://github.com/logingrupa/oc-metapixel-plugin"}
      ],
      "require": {
        "logingrupa/oc-metapixel-plugin": "^2.0"
      }
    }
    EOF
    composer install --no-interaction --prefer-dist
    # expected: success, no auth prompt
    ```
    Verify `vendor/logingrupa/oc-metapixel-plugin/composer.json` exists in the scratch dir and matches the just-tagged v2.0.0 version (`composer show logingrupa/oc-metapixel-plugin` shows v2.0.0).

    Step F.2 — README verbatim re-verify (closes UAT test 7 defect (1) — install command unresolvable on a tagless remote):
    After `git push origin v2.0.0`, run the README's EXACT primary command inside a fresh clean-room October root — the same clean-room shape as UAT test 7 (`/home/forge/metapixel-test7`, October v4.3.1):
    ```bash
    cd /tmp && rm -rf test7-reverify && composer create-project october/october test7-reverify "^4.0" --no-interaction
    cd test7-reverify
    # add the VCS repositories block from the README Install section to composer.json, then:
    composer require logingrupa/oc-metapixel-plugin -W --no-interaction
    # expected: resolves the stable v2.0.0 tag with NO "Could not find a version ... matching your minimum-stability (stable)" error
    composer show logingrupa/oc-metapixel-plugin   # expected: 2.0.0 (stable, not dev-master)
    ```
    Confirm the verbatim `-W` command (no `:dev-master`) resolves the stable tag on a fresh `minimum-stability=stable` root.

    Step F.3 — Drop the README pre-release note (paired with F.2):
    Once the verbatim `-W` command resolves the stable v2.0.0 tag, DELETE the README "Pre-release install" note (Install section) and the quick-start step 3 `:dev-master` fallback sentence — both are only correct while the remote is tagless. Commit:
    ```
    docs(05-14): drop README pre-release :dev-master note — stable v2.0.0 tag now resolves the verbatim -W command
    ```

    Step G — Re-verify CI matrix on the v2.0.0 tag commit (MKT-05):
    Push of the tag triggers `.github/workflows/metapixel-qa.yml` on GitHub Actions. Wait for both Run A (full-Lovata) and Run B (minimal) cells to complete green:
    ```bash
    gh run list --workflow=metapixel-qa.yml --limit 5
    gh run view <run-id>  # confirm all matrix cells green
    ```

    Step H — Author `.planning/phases/05-documentation-marketplace-launch/05-14-LAUNCH-LOG.md`:

    ```
    # Launch Log — v2.0.0

    **Date:** YYYY-MM-DD HH:MM
    **Operator:** <name>
    **Smoke SHA:** <from 05-08>
    **Tagged SHA:** <git rev-parse HEAD>
    **Post-smoke cleanup commits (docs/manifest only):** <list of SHAs + filenames>

    ## Pre-flight
    git status: clean ✓
    composer qa: exit 0 ✓
    git ls-remote --tags origin 'v1*': empty ✓
    git ls-remote --heads origin 'legacy/*': empty ✓

    ## Public flip
    Command: gh repo edit logingrupa/oc-metapixel-plugin --visibility public ...
    gh repo view JSON visibility: "public" ✓
    Incognito clone test: loaded without auth ✓

    ## Annotated tag
    git tag -a v2.0.0 -m "v2.0.0 — generic-event-tracking marketplace plugin"
    git for-each-ref refs/tags/v2.0.0 --format='%(objecttype)': tag ✓
    git describe --tags --exact-match HEAD: v2.0.0 ✓
    git push origin v2.0.0: ok ✓
    git ls-remote --tags origin v2.0.0: present ✓

    ## Unauth-install smoke
    /tmp/test-install composer.json: <content>
    composer install --no-interaction: success, no auth prompt ✓
    composer show logingrupa/oc-metapixel-plugin version: 2.0.0 ✓

    ## CI matrix on v2.0.0 tag
    metapixel-qa.yml workflow run: <run-id>
    Run A (full-Lovata, PHP 8.3): green ✓
    Run A (full-Lovata, PHP 8.4): green ✓
    Run B (minimal, PHP 8.3): green ✓
    Run B (minimal, PHP 8.4): green ✓

    ## License decision
    composer.json license: <mit | proprietary | Apache-2.0>
    LICENSE file: present | not required (proprietary)

    ## Overall verdict

    LAUNCH COMPLETE ✓
    ```

    Commit the launch log:
    ```
    docs(05-14): v2.0.0 launch log — public, tagged, CI matrix green
    ```

    Step I — Optional follow-up: announce v2.0.0 via GitHub Release (`gh release create v2.0.0 --notes-file CHANGELOG.md`) — operator discretion. Not required for MKT-04.
  </how-to-verify>
  <resume-signal>
    Type `LAUNCH COMPLETE` once Steps A through H pass and the launch log is committed. If any step fails, describe the failure + recovery plan (revert tag, re-flip private, etc).
  </resume-signal>
</task>

</tasks>

<verification>
- composer.json license set; commit landed
- GitHub repo visibility = public
- git tag v2.0.0 annotated + pushed to origin
- Composer VCS install from /tmp/test-install exits 0 without auth
- README verbatim `composer require logingrupa/oc-metapixel-plugin -W` re-verified on a fresh clean-room October root post-tag — resolves stable v2.0.0 with no minimum-stability error (closes UAT test 7 defect (1))
- README "Pre-release install" `:dev-master` note dropped once the verbatim `-W` command resolves the stable tag
- CI matrix Run A + Run B both green on v2.0.0 tag commit
- 05-14-LAUNCH-LOG.md committed
- Resume signal `LAUNCH COMPLETE`
</verification>

<success_criteria>
- MKT-01 met: composer require from unauthenticated machine succeeds
- MKT-04 met: annotated tag v2.0.0 pushed; legacy archive confirmed local-only
- MKT-05 met: composer qa exit 0 on both CI matrix cells
- Public clone is real — buyers can install
- DOCS-01 timed dry-run can now be executed independently as the launch acceptance gate (operator timer ≤10 min — separate manual gate, NOT this plan's responsibility but tracked in the launch log if performed)
</success_criteria>

<output>
Create `.planning/phases/05-documentation-marketplace-launch/05-14-SUMMARY.md` when done — note license chosen, tag SHA, public-flip timestamp, CI run ID, unauth-install proof file path, final phase status: COMPLETE.
</output>
