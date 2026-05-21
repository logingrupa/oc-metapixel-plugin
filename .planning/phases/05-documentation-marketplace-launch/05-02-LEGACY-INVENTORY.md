# 05-02 Legacy Facebook Pixel Inventory — `themes/logingrupa-naisstore/`

**Authored:** 2026-05-21
**Authored by:** Plan 05-02 Task 0
**Greps run from:** `/home/forge/nailscosmetics.lv/` (project root)
**Theme repo:** independent git repo at `themes/logingrupa-naisstore/` (remote `git@github.com:logingrupa/oc-naisstore-theme.git`, currently on branch `master`, 2 commits ahead of origin)

Authoritative source for the strip steps (Tasks 1–3) in this same plan. Every row's `strip action` column is the EXACT instruction the strip executor follows.

---

## Section 1 — Pattern 1: Inline `fbq(...)` / `fbevents.js` / `connect.facebook.net` in Twig (.htm)

Grep used:

```bash
grep -rnE 'fbq\(|fbevents\.js|connect\.facebook\.net' themes/logingrupa-naisstore/ --include='*.htm' --include='*.html'
```

| file | line | snippet (first ~60 chars) | strip action |
|------|------|---------------------------|--------------|
| `themes/logingrupa-naisstore/partials/facebook_pixel.htm` | 1–8 | `{% if this.theme.facebook_pixel_id is not empty %} <!-- Facebook Pixel Code --> <script>!function(f,b,e,v,n,t,s){if(f.fbq)... connect.facebook.net/en_US/fbevents.js ... fbq('init', ...) ... fbq('track', 'PageView') ...` | DELETE-file (entire 8-line partial) |
| `themes/logingrupa-naisstore/layouts/main.htm` | 149 | `    {% partial 'facebook_pixel' obUser=obUser%}` | delete-line 149 |
| `themes/logingrupa-naisstore/layouts/content.htm` | 104 | `    {% partial 'facebook_pixel' obUser=obUser%}` | delete-line 104 |
| `themes/logingrupa-naisstore/layouts/light.htm` | 76 | `    {% partial 'facebook_pixel' obUser=obUser%}` | delete-line 76 |
| `themes/logingrupa-naisstore/layouts/catalog_default.htm` | 100 | `    {% partial 'facebook_pixel' obUser=obUser%}` | delete-line 100 |
| `themes/logingrupa-naisstore/pages/checkout.htm` | 91–101 | `{% scripts %} <script> if (typeof _fbq !== 'undefined') { fbq('track', 'InitiatedCheckout', { content_ids:[...] }); } else { ... } </script> {% scripts %}` | delete-block 91..101 (full `{% scripts %} … {% scripts %}` wrapper containing fbq) |
| `themes/logingrupa-naisstore/pages/order-complete.htm` | 20–35 | `{# Disabled: legacy fbq('trackCustom','ViewdOrderCompleatedStatusPage') replaced by Logingrupa.Metapixelshopaholic PurchasePixel component above. {% scripts %} <script> if (typeof _fbq !== 'undefined') { fbq('trackCustom', 'ViewdOrderCompleatedStatusPage', { status:"...", order_id:"...", total_price_value:"...", old_total_price_value:"..." }); } else { console.log('No Tracking'); } </script> {% scripts %} #}` | delete-block 20..35 (commented-out + dead-code AND the v1.x-naming comment per D-23) |
| `themes/logingrupa-naisstore/pages/order-complete-proforma.htm` | 13–26 | `{% scripts %} <script> if (typeof _fbq !== 'undefined') { fbq('trackCustom', 'ViewdOrderProformaPage', { status:"...", order_id:"...", total_price_value:"...", old_total_price_value:"..." }); } else { ... } </script> {% scripts %}` | delete-block 13..26 (full `{% scripts %} … {% scripts %}` wrapper containing fbq) |

**Row count: 8.**

Note: `partials/google_analythics.htm` matches the Pattern 3 grep below (gtag/dataLayer), NOT this Pattern 1 grep. Google Analytics tracking is **out of scope** for the Facebook Pixel strip — leave `google_analythics.htm` alone.

---

## Section 2 — Pattern 2: JS source emission

Grep used (excluding `node_modules` AND the webpack-output `assets/` tree):

```bash
grep -rnE 'fbq\(|_fbq\(|trackFacebook|facebook-purchase-tracking|facebook-add-to-cart|facebook-view-content' themes/logingrupa-naisstore/ --include='*.js' --exclude-dir='node_modules' --exclude-dir='assets'
```

| file | line | snippet (first ~60 chars) | strip action |
|------|------|---------------------------|--------------|
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-add-to-cart.js` | 1–37 | `import { extractBreadcrumbCategoryString } from './breadcrumb-extractor'; export function trackFacebookAddToCart(...) { ... _fbq('track', 'AddToCart', { ... }); }` | DELETE-file (37 LOC tracking helper module) |
| `themes/logingrupa-naisstore/partials/shared/tracking/facebook-view-content.js` | 1–31 | `import { extractBreadcrumbCategoryString } from './breadcrumb-extractor'; export function trackFacebookViewContent(...) { ... _fbq('track', 'ViewContent', { ... }); }` | DELETE-file (31 LOC tracking helper module) |
| `themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js` | 1–39 | `export function sendFacebookPurchaseEvent() { ... jax.ajax('Cart::onGetPixelPurchaseData', { success(response) { ... _fbq('init', '2291486191076331', ...); _fbq('track', 'Purchase', { ... }); } }); }` | DELETE-file (39 LOC tracking helper module; hardcoded live Pixel ID `2291486191076331` is a leaked production credential — D-26 sweep concern, but strip removes it for free) |
| `themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js` | 13 | `import { trackFacebookAddToCart } from '../tracking/facebook-add-to-cart';` | delete-line 13 |
| `themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js` | 47 | `  trackFacebookAddToCart(selectedOption, form);` | delete-line 47 |
| `themes/logingrupa-naisstore/partials/shared/controls/product-detail-control.js` | 13 | `import { trackFacebookViewContent } from '../tracking/facebook-view-content';` | delete-line 13 |
| `themes/logingrupa-naisstore/partials/shared/controls/product-detail-control.js` | 84 | `  trackFacebookViewContent(label);` | delete-line 84 |
| `themes/logingrupa-naisstore/partials/product/search-result/search.js` | 33–40 | `if (typeof _fbq !== 'undefined') { _fbq('track', 'Search', { search_string: obRequest.data.search, url_path: window.location.href, }); } else { console.log('No Tracking') }` | delete-block 33..40 (preserve the surrounding `obSearchHelper.setAjaxRequestCallback((obRequest) => { ... })` flow + the `return obRequest;` on line 41) |
| `themes/logingrupa-naisstore/partials/form/checkout-form/checkout-form-validation.js` | 2 | `// import { sendFacebookPurchaseEvent } from './tracking/facebook-purchase-tracking';` | delete-line 2 (already commented; leaving a commented import to a now-deleted file is dead code + clutter) |

**Row count: 9.**

`trackFacebookAddToCart` is called once (line 47 of `add-to-cart-control.js`). `trackFacebookViewContent` is called once (line 84 of `product-detail-control.js`). `sendFacebookPurchaseEvent` from `facebook-purchase-tracking.js` is only referenced via a commented-out import in `checkout-form-validation.js` line 2 — the runtime call site was already gutted in a prior cleanup, but the dead import comment plus the deletable module both ship.

---

## Section 3 — Pattern 3: Twig dataLayer / fbq dispatcher in `.htm`

Grep used:

```bash
grep -rnE '\{% set fbq|dataLayer\s*=|gtag\(|fbq_payload' themes/logingrupa-naisstore/ --include='*.htm'
```

Hits exist only in `themes/logingrupa-naisstore/partials/google_analythics.htm` (lines 6–15: `window.dataLayer`, `gtag(...)`). These are Google Analytics, NOT Facebook Pixel.

**No Twig `{% set fbq %}` / `fbq_payload` dispatcher pattern in the theme.** (No hits.)

**Row count: 0** — no strip needed in this pattern.

---

## Section 4 — Dead v1.x `purchasePixel` component references

Grep used:

```bash
grep -rnE '\[purchasePixel\]|purchasePixel' themes/logingrupa-naisstore/pages/ themes/logingrupa-naisstore/layouts/ themes/logingrupa-naisstore/partials/
```

| file | line | snippet | strip action |
|------|------|---------|--------------|
| `themes/logingrupa-naisstore/pages/order-complete.htm` | 10–11 | `[purchasePixel]\norderSlug = "{{ :slug }}"` | delete-block 10..12 (the entire `[purchasePixel]` INI declaration including its body line and the trailing blank line; leave `[OrderPage]` on lines 6–8 and `[RetryPayment]` on line 13 intact) |
| `themes/logingrupa-naisstore/pages/order-complete.htm` | 18 | `{% component 'purchasePixel' %}` | delete-line 18 (leave the `{% partial 'order/order-complete/order-complete' obOrder=obOrder %}` on line 16 intact) |

**Row count: 2.**

**Why these MUST go in Task 2 alongside the fbq strip:**

`plugins/logingrupa/metapixel/Plugin.php` lines 108–114 register only:

```php
return [
    EventPixel::class => 'eventPixel',
    PixelHead::class  => 'pixelHead',
];
```

There is NO registration of any `purchasePixel` alias. The `[purchasePixel]` INI block on `order-complete.htm` line 10 references a component the plugin no longer ships. After this plan deploys to staging, October's component resolver will throw `Components\\ClassNotFoundException: Class 'purchasePixel' not found` and **500-error the order-complete page**. UAT Gate 1 (plan 05-03) cannot measure "zero pixel events fire on /order-complete" against a 500-erroring page — so the dead alias MUST be stripped now, not later.

`order-complete-proforma.htm` was inspected (full read): it carries the legacy `fbq('trackCustom','ViewdOrderProformaPage')` block on lines 13–26 (Section 1 above) but **does NOT contain any `[purchasePixel]` INI block or `{% component 'purchasePixel' %}` render**. It only has `[OrderPage]` on line 6. → no purchasePixel strip needed on the proforma page.

---

## Section 5 — Webpack bundle reach (`assets/js/common.js`)

Bundle entry: `themes/logingrupa-naisstore/common.js` → `assets/js/common.js` (Laravel Mix / webpack 4).

Grep used:

```bash
grep -aoE 'fbq\("track","[A-Za-z]+"' themes/logingrupa-naisstore/assets/js/common.js | sort -u
```

Unique `fbq("track","X")` strings currently bundled (3 unique events):

| bundled string | sourced from |
|----------------|--------------|
| `fbq("track","AddToCart"` | `partials/shared/tracking/facebook-add-to-cart.js` (Section 2 row 1) |
| `fbq("track","Search"` | `partials/product/search-result/search.js` (Section 2 row 8) |
| `fbq("track","ViewContent"` | `partials/shared/tracking/facebook-view-content.js` (Section 2 row 2) |

`fbq("track","Purchase"` from `facebook-purchase-tracking.js` does NOT appear in the bundle currently — its sole importer (`checkout-form-validation.js` line 2) has already been commented out, so webpack tree-shaking dropped the module before this strip.

**Bundle size before strip:** `themes/logingrupa-naisstore/assets/js/common.js` = 217,741 bytes (213 KiB) per `ls -la` at inventory time (2026-05-21).

**Rebuild command:**

```bash
cd themes/logingrupa-naisstore && pnpm run prod
```

`webpack.mix.js` defines `common.js` + `common.scss` entries with babel + sass-loader. After source-tree strip (Tasks 1–2), `pnpm run prod` MUST be re-run to regenerate `assets/js/common.js` with zero `fbq(` strings (Task 3 verification gate).

---

## Section 6 — Theme settings cleanup (`configs/fields.yaml`)

Grep used:

```bash
grep -nE 'facebook_pixel_id|facebook_domain_verification_id' themes/logingrupa-naisstore/configs/fields.yaml
```

| file | lines | field key | strip action |
|------|-------|-----------|--------------|
| `themes/logingrupa-naisstore/configs/fields.yaml` | 447–451 | `facebook_pixel_id` (label "Facebook pixel id", text input, tab "Analythics settings") | delete-block 447..451 (the 5-line yaml block: key + 4 indented properties tab/span/label/type) |
| `themes/logingrupa-naisstore/configs/fields.yaml` | 452–456 | `facebook_domain_verification_id` (label "Facebook domain verification", text input, tab "Analythics settings") | delete-block 452..456 (the 5-line yaml block) |

**Row count: 2.**

Both fields are theme-level Settings entries powering the legacy `{% if this.theme.facebook_pixel_id is not empty %}` guard in `partials/facebook_pixel.htm` (Section 1 row 1). The fields become orphan after the partial is deleted — leaving them in the yaml would clutter the backend "Analythics settings" tab with two non-functional inputs.

Preserve `google_ga4_id` on lines 457–461 (out of scope — Google Analytics).

After edit, verify YAML still parses:

```bash
python3 -c 'import yaml; yaml.safe_load(open("themes/logingrupa-naisstore/configs/fields.yaml"))'
```

---

## Total inventory row count

| Section | Rows |
|---------|------|
| Section 1 (Twig inline fbq) | 8 |
| Section 2 (JS source emission) | 9 |
| Section 3 (Twig dataLayer dispatcher) | 0 (no hits — gtag/dataLayer is GA, out of scope) |
| Section 4 (dead `purchasePixel` refs) | 2 |
| Section 5 (bundle reach) | 3 unique fbq("track",X) strings |
| Section 6 (theme settings yaml) | 2 |
| **Total table rows** | **24** |

`must_haves.truths[0]` ≥ 15 rows — satisfied (24).

---

## Strip order for Tasks 1–3

Strict order — surface-most-leaf-first to keep the source tree compiling between edits (so any operator who runs `pnpm run prod` mid-strip gets a clean transient state, not a broken module-graph):

1. **Delete the 3 tracking helper JS modules** (no inbound imports after step 2 lands):
   - `partials/shared/tracking/facebook-add-to-cart.js`
   - `partials/shared/tracking/facebook-view-content.js`
   - `partials/form/checkout-form/tracking/facebook-purchase-tracking.js`
2. **Edit callers to drop imports + call sites**:
   - `partials/shared/controls/add-to-cart-control.js` — drop line 13 import + line 47 call
   - `partials/shared/controls/product-detail-control.js` — drop line 13 import + line 84 call
   - `partials/form/checkout-form/checkout-form-validation.js` — drop line 2 commented import
   - `partials/product/search-result/search.js` — drop the `_fbq('track','Search', …)` block on lines 33–40
3. **Delete the `partials/facebook_pixel.htm` partial** (8 LOC).
4. **Delete the 4 layout includes** of `facebook_pixel` partial:
   - `layouts/main.htm:149`
   - `layouts/content.htm:104`
   - `layouts/light.htm:76`
   - `layouts/catalog_default.htm:100`
5. **Delete the inline fbq blocks in pages**:
   - `pages/checkout.htm:91..101` (InitiatedCheckout)
   - `pages/order-complete.htm:20..35` (commented-out trackCustom + v1.x-naming comment)
   - `pages/order-complete-proforma.htm:13..26` (trackCustom ViewdOrderProformaPage)
6. **Delete dead v1.x `[purchasePixel]` INI block + render in `pages/order-complete.htm`**:
   - lines 10..12 (`[purchasePixel]` block)
   - line 18 (`{% component 'purchasePixel' %}` render)
7. **Delete theme settings yaml entries** in `configs/fields.yaml`:
   - lines 447..451 (`facebook_pixel_id`)
   - lines 452..456 (`facebook_domain_verification_id`)
   - verify YAML still parses via `python3 -c 'import yaml; yaml.safe_load(...)'`
8. **Rebuild webpack bundle**: `cd themes/logingrupa-naisstore && pnpm run prod` — regenerates `assets/js/common.js` from the now-stripped source tree.
9. **Re-grep verification — MUST return zero hits before UAT Gate 1**:
   - `grep -rnE 'fbq\(|fbevents\.js|connect\.facebook\.net' themes/logingrupa-naisstore/ --include='*.htm' --include='*.js' --exclude-dir='node_modules'` → 0 hits
   - `grep -aoE 'fbq\(|_fbq\(|fbevents\.js' themes/logingrupa-naisstore/assets/js/common.js` → 0 hits
   - `grep -c 'purchasePixel' themes/logingrupa-naisstore/pages/order-complete.htm themes/logingrupa-naisstore/pages/order-complete-proforma.htm` → 0 hits

---

## Cross-repo execution boundary

The plugin lives in its own git repo (`logingrupa/oc-metapixel-plugin`). The theme lives in a separate git repo (`logingrupa/oc-naisstore-theme`) at `themes/logingrupa-naisstore/`. Tasks 1–3 modify files inside the **theme repo**; this inventory file lives in the **plugin repo's** `.planning/` tree. The inventory commit lands in the plugin repo; the strip + bundle commit (Tasks 1–3) lands in the theme repo. The two commits are not part of the same git tree — neither merges the other automatically.

The Wave 2 worktree (executor for this plan) is bound to the plugin repo's worktree branch and cannot atomically commit theme-repo edits via the same `git commit`. Either (a) the orchestrator re-spawns this plan from the project root with both repos in scope, or (b) the strip + bundle steps run as an out-of-worktree manual operation after this inventory commit lands on master. See plan 05-02-SUMMARY.md for the actual execution path taken.
