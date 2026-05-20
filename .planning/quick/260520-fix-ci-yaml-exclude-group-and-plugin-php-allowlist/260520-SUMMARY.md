---
phase: quick-260520
plan: 01
status: complete
commits:
  - 1b82f4d  # fix(ci): use --exclude-group=adapter for minimal install Run B
  - db676b4  # fix(deps): allowlist Plugin.php for Lovata DEV_DEPENDENCY_IN_PROD
date: 2026-05-20
files_modified:
  - .github/workflows/metapixel-qa.yml
  - composer-dependency-analyser.php
---

## Outcome

Two CI/QA regressions closed in one atomic commit on master.

1. `.github/workflows/metapixel-qa.yml` Run B now invokes
   `../../../vendor/bin/pest --configuration phpunit.xml --exclude-group=adapter`
   in place of the silent no-op `--exclude-testsuite='Metapixel Adapter Tests'`.
   The literal string `Metapixel Adapter Tests` is gone from the workflow so the
   stale-suite guard assertion in `CiWorkflowMatrixTest::test_stale_testsuite_string_does_not_appear_in_workflow`
   stays green.
2. `composer-dependency-analyser.php` gains an explicit per-package
   `ignoreErrorsOnPackageAndPath($sLovataPackage, __DIR__.'/Plugin.php', [DEV_DEPENDENCY_IN_PROD])`
   call covering the `Lovata\OrdersShopaholic\Models\{CartPosition,Order}` imports
   in Plugin.php's AdapterRegistry registration block. Path-scoped to the file
   directly so `updates/*` and `config/*` continue to be flagged if they ever
   import Lovata.

## Diffs

### .github/workflows/metapixel-qa.yml (Run B step)

```diff
-      - name: composer qa (minimal — Run B, no coverage gate, exclude Adapter testsuite)
+      - name: composer qa (minimal — Run B, no coverage gate, exclude adapter group)
         if: matrix.install == 'minimal'
         working-directory: plugins/logingrupa/metapixel
         run: |
           composer run-script pint-test
           composer run-script analyse
           composer run-script phpmd
           composer run-script deps
-          # Minimal install excludes the Adapter testsuite (Lovata-coupled tests).
-          ../../../vendor/bin/pest --configuration phpunit.xml --exclude-testsuite='Metapixel Adapter Tests'
+          # Minimal install excludes the Lovata-coupled adapter tests via PHPUnit group attribute.
+          # Phase 3 migrated adapter test classes to #[PHPUnit\Framework\Attributes\Group('adapter')];
+          # group-based exclusion is the canonical filter (the prior testsuite-based form referenced
+          # a directory layout that phpunit.xml no longer ships).
+          ../../../vendor/bin/pest --configuration phpunit.xml --exclude-group=adapter
```

### composer-dependency-analyser.php (allowlist block)

```diff
 $arLovataPaths = [
     __DIR__.'/classes/adapter/shopaholic',
     __DIR__.'/classes/event/adapter/shopaholic',
 ];
 foreach ([
     'lovata/shopaholic-plugin',
     'lovata/ordersshopaholic-plugin',
     'lovata/buddies-plugin',
 ] as $sLovataPackage) {
     foreach ($arLovataPaths as $sLovataPath) {
         $obConfig->ignoreErrorsOnPackageAndPath(
             $sLovataPackage,
             $sLovataPath,
             [ErrorType::DEV_DEPENDENCY_IN_PROD],
         );
     }
+
+    // Plugin.php imports Lovata\OrdersShopaholic\Models\{CartPosition,Order} in the AdapterRegistry
+    // registration block. Path-scope the file directly so only Plugin.php at the plugin root is
+    // allowlisted — sibling files (updates/*, config/*) stay under the analyser's lens.
+    $obConfig->ignoreErrorsOnPackageAndPath(
+        $sLovataPackage,
+        __DIR__.'/Plugin.php',
+        [ErrorType::DEV_DEPENDENCY_IN_PROD],
+    );
 }
```

## Validation

### CiWorkflowMatrixTest

```
 PASS  CiWorkflowMatrixTest
 ✓ matrix declares php 83 and 84
 ✓ matrix declares full lovata and minimal install modes
 ✓ run a full lovata invokes coverage with min 90 gate
 ✓ run b minimal uses exclude group adapter not stale testsuite
 ✓ stale testsuite string does not appear in workflow
 Tests: 5 passed (7 assertions)
 Duration: 0.34s
```

### ComposerDependencyAnalyserScopeTest

```
 PASS  ComposerDependencyAnalyserScopeTest
 ✓ config uses ignore errors on package and path for shopaholic plugin
 ✓ config uses ignore errors on package and path for ordersshopaholic plugin
 ✓ config uses ignore errors on package and path for buddies plugin
 ✓ allowlist entries cover adapter shopaholic path
 ✓ allowlist entries cover event adapter shopaholic path
 ✓ no bare ignore errors on package for shopaholic plugin
 ✓ no bare ignore errors on package for ordersshopaholic plugin
 ✓ plugin php is covered by lovata allowlist for top level imports
 Tests: 8 passed (11 assertions)
 Duration: 0.42s
```

### Sanity

- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/metapixel-qa.yml'))"` — parses cleanly.
- `php -l composer-dependency-analyser.php` — no syntax errors.
- `composer deps` not runnable locally (plugin's own `vendor/bin` is uninstalled in this checkout);
  GitHub Actions runs `composer install` in each matrix cell per the workflow's per-job step,
  so the analyser will exercise the new allowlist there.

## Iterations Required

1. First YAML edit kept a `--exclude-testsuite='Metapixel Adapter Tests'` reference inside the
   inline rationale comment — the stale-suite guard regex flagged the substring. Rewrote the
   comment to describe Phase 3's migration without quoting the dead testsuite name.
2. First analyser edit added `__DIR__.'/Plugin.php'` to the `$arLovataPaths` array. The
   `ComposerDependencyAnalyserScopeTest` regex (`/ignoreErrorsOnPackageAndPath[^;]*Plugin\.php/s`)
   requires `Plugin.php` inside a single semicolon-bounded call statement; the array path
   declaration is itself a `;`-terminated statement, so the regex never matched the path
   when consumed inside the foreach. Restructured to add an explicit per-package
   `ignoreErrorsOnPackageAndPath(..., __DIR__.'/Plugin.php', ...)` call alongside the existing
   loop, keeping the path-scoped semantics exact.

## Commits

Split per SRP — one concern per commit:

- `1b82f4d fix(ci): use --exclude-group=adapter for minimal install Run B`
  → `.github/workflows/metapixel-qa.yml` (+6 / -3)
- `db676b4 fix(deps): allowlist Plugin.php for Lovata DEV_DEPENDENCY_IN_PROD`
  → `composer-dependency-analyser.php` (+9 / -0)

Two files total, 15 insertions, 3 deletions across two atomic commits on master. No pushes.
