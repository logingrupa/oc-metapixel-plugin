# Deferred Items — Phase 05

## 05-15 (D-07 browser AddToCart wire)

### phpmd complexity ceiling on ThemeAjaxHandler (pre-existing root cause)

- **Discovered during:** Task 2 (Metapixel::onMarkAddToCart branch)
- **Finding:** `phpmd` reports `ExcessiveClassComplexity` (WMC 50, threshold 50)
  on `classes/adapter/theme/ThemeAjaxHandler.php` and `CyclomaticComplexity`
  (10) on `onBeforeRun`.
- **Root cause:** pre-existing `dispatchViaAdapter()` carries CyclomaticComplexity
  14 / NPath 1024 (present at HEAD before this plan — `phpmd` already exits 2 on
  the untouched tree). The class sat at WMC 49 / `onBeforeRun` at 9 before the
  plan — one under each threshold — so the plan-mandated new handler branch tips
  both to the report level.
- **Why not fixed here:** ExcessiveClassComplexity (sum of method complexities)
  cannot be reduced by extraction — only by simplifying the pre-existing
  `dispatchViaAdapter` logic, which is outside this plan's scope (SCOPE BOUNDARY:
  do not drive-by refactor pre-existing code). `phpmd` is not a green gate in
  this repo today (exit 2 at HEAD).
- **Suggested follow-up:** dedicated refactor plan to split `dispatchViaAdapter`
  (shopaholic-product vs generic-hybrid) into separate handlers, restoring
  headroom under the phpmd complexity ceiling.
