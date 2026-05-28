---
phase: 6
slug: viewcontent-funnel-shopaholic-pdp
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-28
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (on PHPUnit 12) — root `phpunit.xml` |
| **Config file** | `phpunit.xml` (root) + `tests/Pest.php` |
| **Quick run command** | `./vendor/bin/pest --filter='Product\|PixelHead\|ProductPixel\|ThemeAjaxHandler'` |
| **Full suite command** | `composer qa` (pint-test → phpstan L10 → phpmd → pest --coverage --min=90) |
| **Estimated runtime** | quick ~12 s · full ~90 s |

CI matrix: full-Lovata cell runs `composer qa` with `--group=adapter` enabled. Minimal-install cell runs `pest --exclude-group=adapter`. Adapter tests in Phase 6 MUST carry `#[PHPUnit\Framework\Attributes\Group('adapter')]` at class level (plugin CLAUDE.md rule).

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/pest --filter='<TaskClass>'` (quick — one file)
- **After every plan wave:** Run quick run command above (full Phase 6 file set)
- **Before `/gsd-verify-work`:** `composer qa` MUST be green; coverage ≥ 90 % on full-Lovata cell.
- **Max feedback latency:** 15 s for task commit, 90 s for full QA.

---

## Per-Task Verification Map

> One row per task across all seven Phase 6 plans. `File Exists` reflects RED/GREEN test-file presence at task end.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-----------|--------|
| 6-01-01 | 01 | 1 | VIEW-11 | T-6-W1-R | REQUIREMENTS.md gains 11 VIEW-XX rows + traceability + Coverage Summary + Per-Phase Counts updated | doc | `grep -c '^- \[ \] \*\*VIEW-' .planning/REQUIREMENTS.md` | ✅ exists | ⬜ pending |
| 6-01-02 | 01 | 1 | VIEW-11 | T-6-W1-D | VALIDATION.md per-task map fully populated; nyquist_compliant + wave_0_complete = true | doc | `grep -q 'nyquist_compliant: true' .planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-VALIDATION.md` | ✅ exists | ⬜ pending |
| 6-01-03 | 01 | 1 | VIEW-11 | T-6-W1-D | 6 RED test stubs committed with `#[Group('adapter')]` + brief-matrix methods (fail informatively) | feature | `./vendor/bin/pest --group=adapter --filter='Product\|PixelHead\|ProductPixel\|ThemeAjaxHandler'` | ❌ W0 | ⬜ pending |
| 6-02-01 | 02 | 2 | VIEW-01 | T-6-01 | PixelHeadDeferredFlushBuffer request-scoped singleton accumulates per-event blocks; markup helper registered | unit | `./vendor/bin/pest tests/Feature/Components/PixelHeadDeferredFlushTest.php --filter=buffer` | ❌ created in 6-02-01 | ⬜ pending |
| 6-02-02 | 02 | 2 | VIEW-01 | T-6-01 | PixelHead::flushDeferredFromController short-circuits on null/404 controller; extended emit shape carries eventID | unit | `./vendor/bin/pest tests/Feature/Components/PixelHeadDeferredFlushTest.php --filter=flushDeferred` | ✅ created in 6-02-02 | ⬜ pending |
| 6-02-03 | 02 | 2 | VIEW-01 | T-6-01 | Plugin.php registers cms.page.beforeRenderPage listener; PixelHeadDeferredFlushTest 4-item matrix GREEN | feature | `./vendor/bin/pest tests/Feature/Components/PixelHeadDeferredFlushTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-03-01 | 03 | 2 | VIEW-07, VIEW-08 | T-6-04 | SupportsHybridAjax marker subinterface + UnknownSubjectTypeException + AdapterRegistry::resolveByAlias added | unit | `./vendor/bin/pest tests/Feature/Adapter/AdapterRegistryResolveByAliasTest.php` | ❌ created in 6-03-01 | ⬜ pending |
| 6-03-02 | 03 | 2 | VIEW-07 | T-6-04 | AdapterRegistry alias-index covers happy / unknown alias (throws) / idempotent re-register paths | unit | `./vendor/bin/pest tests/Feature/Adapter/AdapterRegistryResolveByAliasTest.php` | ✅ created in 6-03-02 | ⬜ pending |
| 6-04-01 | 04 | 3 | VIEW-02, VIEW-08 | T-6-02 | ShopaholicProductAdapter::getSiteId reads `$obProduct->site_list` only — falls back to `Site::getSiteIdFromContext()` (D-15); no Request/SiteManager | unit | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php --filter=siteId` | ❌ created in 6-04-01 | ⬜ pending |
| 6-04-02 | 04 | 3 | VIEW-03 | T-6-02 | ShopaholicProductValueResolver returns default-offer price_value + CurrencyHelper currency chain + SKU-{pid}[-{oid}] | unit | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-04-03 | 04 | 3 | VIEW-02, VIEW-11 | T-6-02 | phpstan.neon allowIn extended for ShopaholicProductAdapter directory; ValueResolverTest GREEN | static | `composer analyse && ./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-04-04 | 04 | 3 | VIEW-02 | T-6-02 | ShopaholicProductAdapterContractTest exits 0 — 10 Phase 2 invariants satisfied by ShopaholicProductAdapter | contract | `./vendor/bin/pest tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-05-01 | 05 | 4 | VIEW-04 | T-6-03 | ProductPageWatcher handle() pushes ThemeEventCollector + dispatches SendCapiEvent on shopaholic.product.open; Throwable boundary catch logs + skips | feature | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-05-02 | 05 | 4 | VIEW-10 | T-6-03, T-6-06 | Plugin.php boot registers ProductPageWatcher + AdapterRegistry::register ONLY when Lovata.OrdersShopaholic exists | feature | `./vendor/bin/pest tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` | ✅ created in 6-05-02 | ⬜ pending |
| 6-05-03 | 05 | 4 | VIEW-04, VIEW-10 | T-6-03 | ProductPageWatcherTest 11-item matrix GREEN; ShopaholicConditionalRegistrationTest extended with VIEW-10 case | feature | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-06-01 | 06 | 4 | VIEW-05, VIEW-06 | T-6-05 | ProductPixel component + default.htm Twig partial render window.__metapixelProduct global + offer-switch JS; ThemeEventCollector::peek added | feature | `./vendor/bin/pest tests/Feature/Components/ProductPixelTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-06-02 | 06 | 4 | VIEW-09 | T-6-04 | ThemeAjaxHandler::onBeforeRun hybrid `subject_type` branch (422 unknown alias / 422 lacks SupportsHybridAjax / 422 non-positive id / 404 missing subject) | feature | `./vendor/bin/pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-06-03 | 06 | 4 | VIEW-05, VIEW-06, VIEW-09 | T-6-04, T-6-05 | ProductPixelTest + ThemeAjaxHandlerSubjectTypeTest GREEN | feature | `./vendor/bin/pest tests/Feature/Components/ProductPixelTest.php tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | ✅ created in 6-01-03 | ⬜ pending |
| 6-07-01 | 07 | 5 | VIEW-11 | n/a | CHANGELOG.md gains v2.0.0 ### Added entry referencing ViewContent funnel + PDP integration | doc | `grep -q 'ViewContent funnel' CHANGELOG.md` | ✅ exists | ⬜ pending |
| 6-07-02 | 07 | 5 | VIEW-11 | n/a | README.md ViewContent funnel section authored; PixelHead PHPDoc lifecycle docblock added | doc | `grep -q 'ViewContent funnel' README.md` | ✅ exists | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Components/PixelHeadDeferredFlushTest.php` — RED stubs (4-item matrix from brief)
- [ ] `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` — RED stubs (11-item matrix from brief)
- [ ] `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` — RED stubs (SKU single vs multi, price source, currency source)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` — RED stubs (unknown alias → 422, valid alias routes adapter, allowlist bypass blocked)
- [ ] `tests/Feature/Components/ProductPixelTest.php` — RED stubs (script render shape, disabled-state, JS offer-switch markers)
- [ ] `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` — RED stub (extends `EventSubjectAdapterContractTestCase`; supplies `makeAdapter()` + `makeSubject()` failing stubs; 10 inherited Phase 2 invariants run RED until ShopaholicProductAdapter ships in Plan 06-04 Task 4)
- [ ] No new framework install required — Pest 4 + PHPUnit 12 already installed.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Meta Events Manager dedup ratio ≥ 0.85 on ViewContent | VIEW-01 | Requires live Meta endpoint + real `pixel_id` + `capi_access_token` | After deploy: open Meta Events Manager → Test Events → set `test_event_code` → load PDP → switch offer 3× → confirm 1 ViewContent per fire with `server` + `browser` source merged |
| Browser fbq fires before `cms.page.beforeRenderPage` cache TTL hits | VIEW-01 | OPcache + CMS_ASSET_CACHE warm-up timing not reliably reproducible in unit tests | On staging: cold-restart php-fpm → load PDP 3× → DevTools network tab: confirm `<script>fbq('track','ViewContent',...)</script>` present each request |
| Offer-switch event_id propagates to Meta with no client-side reuse | VIEW-06 | Requires Meta backend dedup ack | DevTools network tab: confirm POST to `/?Metapixel::onFireEvent` returns NEW `event_id` per switch; confirm JS substitutes returned `event_id` into rendered fbq script (no client-generated UUIDs) |
| ProductPixel JS soft-gate blocks cart-modal bonus-box false fires | VIEW-06 | DOM + theme interaction not exercisable in headless unit tests | On staging: add product to cart → open cart modal showing bonus-box (`[name="offer_id"]` present on non-PDP DOM) → switch bonus offer → DevTools network: confirm NO POST to `Metapixel::onFireEvent` (window.__metapixelProduct undefined ⇒ no fire) |
| Phase 6 minimal-install cell drops cleanly | VIEW-11 | Requires fresh OctoberCMS host with `Lovata.Toolbox` only | `composer install --no-scripts` on minimal-install fixture → `./vendor/bin/pest --exclude-group=adapter` exits 0 with 0 Phase 6 tests executed |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (populated by planner)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all RED-test-file MISSING references (6 RED stubs enumerated above)
- [x] No watch-mode flags (`--watch` banned in CI)
- [x] Feedback latency < 15 s task / < 90 s full QA
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** ready
