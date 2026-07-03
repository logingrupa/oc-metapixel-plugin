---
phase: 05-documentation-marketplace-launch
reviewed: 2026-07-03T00:00:00Z
depth: standard
files_reviewed: 2
files_reviewed_list:
  - README.md
  - tests/Feature/Docs/ReadmeStructureTest.php
findings:
  critical: 0
  warning: 1
  info: 3
  total: 4
status: issues_found
---

# Phase 05: Code Review Report (scoped re-review — gap-closure plan 05-22 delta)

**Reviewed:** 2026-07-03T00:00:00Z
**Depth:** standard
**Files Reviewed:** 2
**Status:** issues_found

## Summary

Scoped re-review of the 05-22 gap-closure delta (commits `d4a733a`, `7b8c124`, `16e1e07` on top of base `023e147`): the `october:up` → `october:migrate` fix, the `[pixelHead]` INI-declaration snippet addition, and the `:dev-master` pre-release install fallback. This is a delta-only review; the unrelated files covered by the earlier full-phase review are out of scope here and are not re-litigated.

All three README technical claims were verified directly against the OctoberCMS core source shipped in this repo (`modules/system/console/OctoberUp.php`, `OctoberMigrate.php`, `October\Rain\Halcyon\Processors\SectionParser`, `Cms\Classes\HasComponentHelpers::findComponentByName`) and against live Composer/PHP behavior:

- `october:up` is confirmed a deprecated no-op (`$this->error(...)`) in the installed core; `october:migrate` is the only command that actually runs `UpdateManager::update()`. The README fix is correct everywhere it appears (install block, quick-start step 4, troubleshoot table). Zero remaining `october:up` references.
- The `[pixelHead]` INI-declaration requirement is technically accurate: `Controller::renderComponent()` resolves components only via `$this->layout->components[$name]` / `$this->page->components[$name]`, which are populated exclusively from the INI section parsed by `SectionParser`. A bare `{% component 'pixelHead' %}` with no matching INI declaration silently renders nothing, exactly as the README states. Confirmed against this repo's own production layout (`themes/logingrupa-naisstore/layouts/main.htm:26`), which already declares `[pixelHead]`.
- The `:dev-master` composer stability fallback is accurate for the current state of the actual `origin` remote (`git ls-remote origin` returns only `refs/heads/master`, zero tags pushed), so a fresh root with the default `minimum-stability: stable` genuinely has no stable version to resolve today, and pinning `:dev-master` is the correct escape hatch. (Note: local-only tags `v1.1.1` and `v2.0.0-rc.1` exist but are not pushed to `origin` — if `v1.1.1` is ever pushed before a stable `v2.0.0`, the plain `-W` require would silently resolve to the legacy v1.x release instead of erroring; this is a latent risk worth a mental note for whoever manages tag pushes, not a defect in the current README text.)

No BLOCKER-level defects found. One test-coverage gap (WARNING) and three minor documentation/wording nits (INFO) are listed below.

## Warnings

### WR-01: Two of the three delta doc-fixes have no regression test (doc gate incomplete)

**File:** `tests/Feature/Docs/ReadmeStructureTest.php:82-90` (existing gate, for contrast)
**Issue:** This test file exists specifically to pin README wording against regression (see `test_readme_install_block_shows_vcs_repositories_pattern`, `test_readme_install_documents_fresh_install_prerequisites`, etc.). The delta only updated the *existing* assertion — `test_readme_install_block_shows_october_up` was renamed to `test_readme_install_block_shows_october_migrate` (commit `d4a733a`). The other two documentation fixes shipped in this same gap-closure round have **zero** test coverage:
  - The `[pixelHead]` INI-declaration requirement added in commit `7b8c124` (README.md:136-153) — no assertion checks for the literal `[pixelHead]` bracketed INI declaration anywhere in the test file. If someone reverts the Theme-walkthrough snippet back to the old one-line `{% component 'pixelHead' %}` (as it was pre-`023e147`), no test fails.
  - The `:dev-master` pre-release install fallback added in commit `16e1e07` (README.md:57-63, 75) — no assertion checks for `dev-master` anywhere in the test file.

  `grep -rln "dev-master\|pixelHead" tests/` outside this file returns nothing either — there is no other test covering these two claims.
**Fix:** Add two assertions mirroring the existing pattern, e.g.:
```php
public function test_readme_theme_walkthrough_shows_pixelhead_ini_declaration(): void
{
    $sReadme = $this->loadReadme();
    $this->assertStringContainsString(
        '[pixelHead]',
        $sReadme,
        'README Theme walkthrough must show the `[pixelHead]` INI declaration — the bare Twig tag alone silently no-ops (DOCS-01 gap-closure 05-22).',
    );
}

public function test_readme_documents_dev_master_prerelease_fallback(): void
{
    $sReadme = $this->loadReadme();
    $this->assertStringContainsString(
        ':dev-master',
        $sReadme,
        'README must document the `:dev-master` pre-release install fallback for a fresh October root (minimum-stability=stable, no v2.0.0 stable tag yet) — gap-closure 05-22.',
    );
}
```

## Info

### IN-01: Unexplained `##` line in the `[pixelHead]` layout snippet

**File:** `README.md:139`
**Issue:** The Theme-walkthrough code block opens with a bare `##` line before `description = "Default layout"`. This is not required October CMS layout syntax — PHP's `parse_ini_string` happens to treat it as a harmless comment (verified locally with `php -r`), but no real `.htm` layout in this codebase (e.g. `themes/logingrupa-naisstore/layouts/main.htm`, which itself already declares `[pixelHead]` in production) uses a `##` marker, and October has no documented convention that assigns it meaning in this context. A reader unfamiliar with October internals could reasonably assume `##` is required syntax (it superficially resembles RainLab.Translate's unrelated `## lang: xx` content-file marker) and either cargo-cult it or get confused when omitting it changes nothing.
**Fix:** Drop the `##` line, or replace it with an explanatory comment if a placeholder was intended:
```twig
description = "Default layout"

[pixelHead]
==
```

### IN-02: Code fence language mismatch for the mixed INI+Twig layout snippet

**File:** `README.md:138-153`
**Issue:** The block is fenced as ```` ```twig ```` but the first four lines (`description = "..."`, `[pixelHead]`, and the `==` separator) are October's INI/config section, not Twig — only the portion after the second `==` is Twig markup. Labeling the whole block `twig` produces inaccurate syntax highlighting on GitHub and slightly undercuts the surrounding prose that explicitly explains the file has three distinct sections separated by `==`.
**Fix:** Use an unlabeled fence (```` ``` ````) for the full-file example, since no single language covers INI + Twig, or split it into two fenced blocks (`ini` then `twig`) matching the file's real section boundary.

### IN-03: Test-failure message cites an unverified October version number

**File:** `tests/Feature/Docs/ReadmeStructureTest.php:88`
**Issue:** The assertion message reads "October 4.3 deprecated the old migrate-on-install command to a no-op." The October core actually vendored in this repo reports `System::VERSION = '4.2'` (`modules/system/facades/System.php:23`) and already ships `october:up` as a full no-op deprecation stub (`modules/system/console/OctoberUp.php`) — i.e., the deprecation predates or equals 4.2, not 4.3. This string is never shown to end users (it only surfaces on PHPUnit assertion failure), so it carries no functional risk, but the specific version claim is not substantiated by anything in this repo and reads as speculative.
**Fix:** Either drop the version number (`"October deprecated the old migrate-on-install command to a no-op..."`) or verify the exact version against October's own changelog before citing it.

---

_Reviewed: 2026-07-03T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
