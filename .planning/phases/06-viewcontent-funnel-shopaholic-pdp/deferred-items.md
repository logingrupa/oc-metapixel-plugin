# Phase 06 — Deferred Items

Out-of-scope issues discovered during execution. Per executor deviation rules: log here rather than fixing inline.

## Pre-existing test failures (Phase 5 deferred / out of plan scope)

### `ThemeMarkupTagsTwigTest::test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires`

**Status:** Pre-existing failure unrelated to plan 06-02.

**Source:** Test at `tests/Feature/Adapter/Theme/ThemeMarkupTagsTwigTest.php:100-113`.

**Evidence:** Reproduced on a pristine `master` (a7637d6) checkout with NO 06-02 changes applied. The assertion at line 109 sees `$mThis->config['metapixel'] ?? null` returning `null` instead of the `ThemeEventCollector` singleton.

**Likely root cause (not investigated):** The test uses `Event::dispatch('cms.page.beforeRenderPage', ...)` to fire the plugin's first listener, but the listener may not be registered in the test's plugin-boot lifecycle (possibly an `autoRegister`/dispatcher binding mismatch).

**Owner:** Out of plan 06-02 scope. The plan changes a SECOND listener but does not touch the first listener or the plugin-register lifecycle. Recommend triage during Phase 6 verification or as a Phase 5 cleanup todo.

## Pre-existing test failures (TDD RED stubs for future Phase 6 plans)

- `Logingrupa\Metapixel\Tests\Contract\Adapter\Shopaholic\ShopaholicProductAdapterContractTest` — owned by plan 06-04
- `Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic\ProductPageWatcherTest` — owned by plan 06-05
- `Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic\ShopaholicProductValueResolverTest` — owned by plan 06-04
- `Logingrupa\Metapixel\Tests\Feature\Adapter\Theme\ThemeAjaxHandlerSubjectTypeTest` — owned by plan 06-06
- `Logingrupa\Metapixel\Tests\Feature\Components\ProductPixelTest` — owned by plan 06-06

All five are intentional RED stubs landed by plan 06-01 (`tests/Feature/...` — 4 of the 6 stubs are turned GREEN by 06-02; the remaining 5 go GREEN in plans 03/04/05/06).

## Pre-existing test failures (Phase 5 documentation TDD scope)

- `ReadmeStructureTest` — README.md not yet shipped (owned by plan 05-09).
- `AssetsExistTest` — assets/screenshots/CHANGELOG TDD ahead of artifact (owned by plan 05-08).

Per STATE.md: "11 pest failures are Phase 5 scope (README + screenshots + CHANGELOG, owned by plans 05-09/05-08/05-12 — TDD tests written ahead of artifact)."
