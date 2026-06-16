# Deferred items — 260616-wcp

Out-of-scope discoveries during execution. Not caused by this plan; logged per SCOPE BOUNDARY rule (do NOT fix here).

## Pre-existing test failures (5) — README/asset docs, unrelated to PixelHead/PayloadBuilder

These fail on the pristine baseline (reproduced by running only the doc/asset tests; they read README.md / lang/en / screenshots which this plan never modified). They block Pest from rendering the coverage Total table (Pest suppresses the coverage summary when any test fails).

- `ReadmeStructureTest > readme contains seven named sections`
- `ReadmeStructureTest > readme install block shows october up`
- `ReadmeStructureTest > readme install block shows vcs repositories pattern`
- `ReadmeStructureTest > readme anchors field labels from lang en` (asserts README contains lang label "Pixel ID")
- `AssetsExistTest > five screenshots present with padded prefix`

Root cause (not investigated/fixed here): README.md and/or `lang/en` field labels and `assets/` screenshots are out of sync with what these doc tests expect. This is a documentation/asset drift issue, candidate for a `/gsd-quick` docs pass.

Impact on this plan: none on the two source files changed — `classes/meta/PayloadBuilder.php` measures 100.0% and `components/PixelHead.php` measures 91.3% line coverage (both > 90%). phpstan L10 clean on full 47-file scope. All 11 tests in the two plan-touched test files pass.
