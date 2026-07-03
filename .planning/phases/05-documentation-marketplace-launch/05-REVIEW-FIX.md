---
phase: 05-documentation-marketplace-launch
fixed_at: 2026-07-03T15:40:00Z
review_path: .planning/phases/05-documentation-marketplace-launch/05-REVIEW.md
iteration: 2
findings_in_scope: 2
fixed: 1
skipped: 1
status: partial
---

# Phase 05: Code Review Fix Report

**Fixed at:** 2026-07-03T15:40:00Z
**Source review:** .planning/phases/05-documentation-marketplace-launch/05-REVIEW.md (post-fix re-review, 2026-07-03T14:05:00Z)
**Iteration:** 2

**Summary:**
- Findings in scope: 2 (0 Critical + 2 Warning; fix_scope `critical_warning` excludes the 9 Info findings)
- Fixed: 1 (WR-10)
- Skipped: 1 (WR-08 — carried-forward product decision, unchanged from iteration 1)

**Iteration context:** Iteration 1 (commits `742753d..c793172`, report committed as 37852e8) fixed both Criticals (CR-01 retry-aware race fence, CR-02 identity firewall) and 8 of 9 Warnings; WR-08 was skipped as a product decision. The re-review confirmed all 10 iteration-1 fixes as genuinely resolved and raised exactly one new Warning (WR-10) — a gap the CR-02 fix wave left open on the generic hybrid dispatch branch. This iteration closes WR-10 and re-documents the WR-08 skip.

**Quality gates (run after all fixes, host binaries, in-tree):**

| Gate | Result | Notes |
|------|--------|-------|
| `pint --test` | PASS | `{"tool":"pint","result":"passed"}` |
| `phpstan analyse` (level 10, phpVersion 80300) | PASS — 0 errors | 52 files |
| `phpmd Plugin.php,classes,models,console,components,middleware,controllers text phpmd.xml` | PASS — 0 violations | ThemeAjaxHandler complexity unchanged (fix adds a single delegated call; merge logic lives in ThemeAjaxRequestReader per the 93edcad extraction pattern) |
| `pest -c phpunit.xml` (full suite) | PASS — 585 tests, 2234 assertions | +2 tests vs iteration 1 (583) |
| `pest --coverage --min=90` | PASS — 90.5 % | Gate green in-tree (iteration 1's 87.0 % local reading was an environment artifact; current in-tree measurement clears the CI gate) |

## Fixed Issues

### WR-10: Generic hybrid AJAX dispatch never injects request user_data — anonymous-subject events ship empty user_data and permanently dead-letter

**Files modified:** `classes/adapter/theme/ThemeAjaxHandler.php`, `classes/adapter/theme/ThemeAjaxRequestReader.php`, `tests/Feature/Adapter/Theme/ThemeAjaxHandlerGenericAdapterServerUserDataTest.php` (new)
**Commit:** e6857cd
**Applied fix:** Added `ThemeAjaxRequestReader::injectServerUserData(array $arPayload): array` — reuses the CR-02 `collectServerUserData()` capture (Request::ip, Request::userAgent, `_fbp`/`_fbc` cookies), explicitly drops `site_id` (hybrid subjects are adapter-loaded; `getSiteId` reads from the subject per the D-15 lock, so site must never enter `user_data`), and merges the non-null values into the built payload's `data[0].user_data` with adapter-supplied non-null/non-empty values winning — mirroring `CapturesRequestUserData::injectRequestUserData` semantics exactly. `dispatchGenericAdapter()` now calls it on the `PayloadBuilder` output before `SendCapiEvent::dispatch`, closing the last AJAX branch without request-context user_data (theme-action branch: `collectServerUserData` merge at line 122; offer-switch branch: `injectRequestUserData` in `ProductPageWatcher::dispatchForOfferSwitch`). Anonymous-subject hybrid adapters (the documented CUSTOM-ADAPTERS.md guest-order pattern) no longer produce empty-user_data CAPI events that Meta rejects with subcode 2804050 into permanent `FailedEvent` dead-letters. The merge lives in the reader (classes/adapter/theme/, the documented D-16 phpstan-exclusion zone) rather than the handler, keeping the phpmd `ExcessiveClassComplexity` gate flat per the 93edcad extraction pattern. New focused test mirrors `ThemeAjaxHandlerServerUserDataTest` for this branch: (1) an all-null-user_data hybrid adapter's dispatched payload carries server-captured ip/UA/fbp/fbc and no `site_id` key in `user_data`; (2) adapter-supplied `client_ip_address`/`fbp` win over request capture while the adapter's null `client_user_agent`/`fbc` are back-filled from the request.

## Skipped Issues

### WR-08: Google AddToCart tracked before the cart add succeeds (carried forward — deliberately skipped, iteration 2)

**File:** `../../../themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:44-45`
**Reason:** Skipped again for the same three compounding blockers documented in iteration 1, all still true:
1. The file lives in a **separate git repository** (`themes/logingrupa-naisstore`) with a dirty working tree; committing there from this session risks racing foreground work. Orchestrator instruction for this iteration: do not touch the theme repo.
2. The change only takes effect after a **webpack rebuild** (`pnpm run prod`) plus a compiled-bundle commit — the rebuild would sweep in unrelated uncommitted theme state.
3. Moving Google AddToCart from click-time to success-time **changes Google Ads conversion-counting semantics** — the ordering IS the semantics; there is no fix that preserves them. Needs an explicit product decision.

**Original issue:** `onAddToCartClick()` fires `trackGoogleAddToCart(selectedOption)` synchronously on every click — including failed adds (out of stock, validation error, network failure) — while the Meta pixel correctly fires only in the success branch; Google and Meta AddToCart counts diverge and Google conversions include adds that never happened.
**Ready-to-apply fix for the developer** (move the call into the success branch of `addOfferToCart`, delete the click-time call, then rebuild + commit source and bundle together):
```js
if (response && response.status) {
  showButtonPopover(button, 'Item added to cart');
  refreshCartHeader();
  fireMetaAddToCartPixel(selectedOption.value);
  trackGoogleAddToCart(selectedOption);
}
```

## Out of scope (fix_scope = critical_warning)

IN-01, IN-02, IN-05, IN-06, IN-07, IN-08 (carried forward from iteration 1) and the three new Infos from the re-review (IN-09 stale AddToCartPixelResult docblock, IN-10 missing imports in the CUSTOM-ADAPTERS.md minimal snippet, IN-11 unbounded client-supplied `action_key` length) were not attempted per the configured scope. IN-11 in particular touches the same branch as WR-10 and would be a natural companion fix in a future `--fix all` pass.

## Iteration 1 outcomes (reference)

Fixed in iteration 1 and confirmed resolved by the re-review: CR-01 (`EventLogWriter::ownsRow` retry-aware fence, commits 742753d/37c3d8d/c793172), CR-02 (identity firewall + `collectServerUserData`, commits d8b3050/93edcad), WR-01 (docblock option, eceea1d), WR-02 (76185eb), WR-03 (50ac69f), WR-04 (08d2039), WR-05 (209a3b2), WR-06 (4d67e3b), WR-07 (c3c1fbd), WR-09 (f472383). See git history and the iteration-1 report content preserved at commit 37852e8.

---

_Fixed: 2026-07-03T15:40:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 2_
