---
phase: 6
slug: viewcontent-funnel-shopaholic-pdp
status: draft
nyquist_compliant: false
wave_0_complete: false
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

> Populated by planner from final task list. One row per task. `File Exists` reflects RED/GREEN test-file presence at task end.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-----------|--------|
| 6-01-XX | 01 | 1 | VIEW-XX | T-6-01 | PixelHead deferred flush no-ops on 404 redirect | unit | `./vendor/bin/pest tests/Feature/Component/PixelHeadDeferredFlushTest.php` | ❌ W0 | ⬜ pending |
| 6-02-XX | 02 | 2 | VIEW-XX | T-6-02 | ShopaholicProductAdapter::getSiteId reads `$obProduct->site` pivot only — no SiteManager/Request | unit | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` | ❌ W0 | ⬜ pending |
| 6-03-XX | 03 | 2 | VIEW-XX | T-6-03 | ProductPageWatcher.try/catch logs+skips on payload failure (no 500) | feature | `./vendor/bin/pest tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` | ❌ W0 | ⬜ pending |
| 6-04-XX | 04 | 3 | VIEW-XX | T-6-04 | ThemeAjaxHandler hybrid path rejects unknown `subject_type` alias (→ 422) | feature | `./vendor/bin/pest tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` | ❌ W0 | ⬜ pending |
| 6-05-XX | 05 | 3 | VIEW-XX | T-6-05 | ProductPixel JS soft-gate via `window.__metapixelProduct` (no cart-bonus-box false fire) | feature | `./vendor/bin/pest tests/Feature/Component/ProductPixelTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Planner MUST replace placeholder rows above with concrete task IDs once `06-PLAN.md` files exist.

---

## Wave 0 Requirements

- [ ] `tests/Feature/Component/PixelHeadDeferredFlushTest.php` — RED stubs (4-item matrix from brief)
- [ ] `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` — RED stubs (11-item matrix from brief)
- [ ] `tests/Feature/Adapter/Shopaholic/ShopaholicProductValueResolverTest.php` — RED stubs (SKU single vs multi, price source, currency source)
- [ ] `tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php` — RED stubs (unknown alias → 422, valid alias routes adapter, allowlist bypass blocked)
- [ ] `tests/Feature/Component/ProductPixelTest.php` — RED stubs (script render shape, disabled-state, JS offer-switch markers)
- [ ] `tests/Contract/Adapter/Shopaholic/ShopaholicProductAdapterContractTest.php` — RED stub (extends `EventSubjectAdapterContractTestCase`; supplies `makeAdapter()` + `makeSubject()` failing stubs; 10 inherited Phase 2 invariants run RED until ShopaholicProductAdapter ships in Plan 06-04 Task 4)
- [ ] No new framework install required — Pest 4 + PHPUnit 12 already installed.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Meta Events Manager dedup ratio ≥ 0.85 on ViewContent | VIEW-XX | Requires live Meta endpoint + real `pixel_id` + `capi_access_token` | After deploy: open Meta Events Manager → Test Events → set `test_event_code` → load PDP → switch offer 3× → confirm 1 ViewContent per fire with `server` + `browser` source merged |
| Browser fbq fires before `cms.page.beforeRenderPage` cache TTL hits | VIEW-XX | OPcache + CMS_ASSET_CACHE warm-up timing not reliably reproducible in unit tests | On staging: cold-restart php-fpm → load PDP 3× → DevTools network tab: confirm `<script>fbq('track','ViewContent',...)</script>` present each request |
| Offer-switch event_id propagates to Meta with no client-side reuse | VIEW-XX | Requires Meta backend dedup ack | DevTools network tab: confirm POST to `/?Metapixel::onFireEvent` returns NEW `event_id` per switch; confirm JS substitutes returned `event_id` into rendered fbq script (no client-generated UUIDs) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies (populated by planner)
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all RED-test-file MISSING references
- [ ] No watch-mode flags (`--watch` banned in CI)
- [ ] Feedback latency < 15 s task / < 90 s full QA
- [ ] `nyquist_compliant: true` set in frontmatter once planner fills task map

**Approval:** pending
