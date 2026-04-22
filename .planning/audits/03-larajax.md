# Audit: Larajax AJAX Transport & Meta Pixel Integration

**Date:** 2026-04-22  
**Auditor:** File Search Specialist

---

## Question 1: Is Larajax Installed?

**FINDING: YES. REAL AND INSTALLED.**

- `/home/forge/nailscosmetics.lv/vendor/composer/installed.json`: Larajax package `larajax/larajax` is installed.
- Autoloader: `/home/forge/nailscosmetics.lv/vendor/composer/autoload_classmap.php` registers `Larajax\Classes\AjaxHelpers`, `Larajax\Classes\AjaxRequest`, `Larajax\Classes\AjaxResponse`, etc.
- PHP side: `/home/forge/nailscosmetics.lv/vendor/larajax/larajax/src/init.php` provides global `ajax()` helper function.
- JS side: `/home/forge/nailscosmetics.lv/vendor/larajax/larajax/resources/src/framework.js:17-31` initializes `window.jax` as a global object with `jax.ajax()` method.

**Status:** Plan §5 claim is **CORRECT**. Larajax is not invented.

---

## Question 2: What is the ACTUAL Current AJAX Pattern?

**FINDING: Hybrid Pattern - Both `jax.ajax()` and October CMS Component Handlers**

Theme uses Larajax's `jax.ajax()` throughout:
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/shared/controls/add-to-cart-control.js:68`: `await jax.ajax('Cart::onAdd', { data: { cart: cartData } })`
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js:10`: `jax.ajax('Cart::onGetPixelPurchaseData', { ... })`
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/idea/idea-offers/idea-offers.js`: `jax.ajax(method, ...)`
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/shared/controls/product-detail-control.js`: Multiple `jax.ajax()` calls.

Also uses October CMS classic `$.request()`:
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/product/cart-link-header-sidebar/cart-link-header-sidebar.js`: `$.request('Cart::onGetData', ...)`

**Status:** Theme is migrating to Larajax. Both patterns coexist.

---

## Question 3: PHP Routing Pattern - Larajax vs October CMS?

**FINDING: NOT a Larajax Facade. October CMS Component Handler Pattern.**

The handler format `'Cart::onAdd'` is **NOT** a Larajax facade route. It is **October CMS Component Method Syntax**:
- Namespace: `Cart` = component alias (registered in theme).
- Method: `onAdd` = public handler method on the component.

Real implementation:
- `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/components/Cart.php:65-73`: Public `onAdd()` method.
- `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/components/Cart.php:172-175`: Public `onGetData()` method.

**Larajax Integration:** October CMS components are registered with Larajax via `ComponentContainer`:
- `/home/forge/nailscosmetics.lv/vendor/larajax/larajax/src/Traits/AjaxController.php:31-52`: `callAjaxAction()` → `componentContainer.register()` → locates and boots component.
- `/home/forge/nailscosmetics.lv/vendor/larajax/larajax/src/Traits/AjaxController.php:90-100`: Validates handler name as `onXxx` format.

**Proposed Pattern in Plan §5:** `Larajax::get('meta-pixel/init', [PixelController::class, 'init'])` would require a Larajax route file (e.g., `routes/larajax.php`), which DOES NOT EXIST. This pattern is **NON-STANDARD** for this codebase.

**Correct Pattern:** Keep using `jax.ajax('Cart::onGetPixelPurchaseData', ...)` via component handler methods.

---

## Question 4: @lovata/shopaholic-cart Handler Convention

**FINDING: Dynamic Method Extension Pattern**

The Cart component does NOT have `onGetPixelPurchaseData` natively. It's added dynamically:
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:22-29`: Subscribes to Cart extension.
- Line 25: `$obCartComponent->addDynamicMethod('onGetPixelPurchaseData', function () { ... })`

This follows October CMS plugin event pattern:
1. Hook into component via event listener.
2. Use `addDynamicMethod()` to attach new handler.
3. Handler returns array (can be used by Larajax or classic `$.request()`).

**onAdd Pattern:**
- Lovata Cart component `onAdd()` at `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/components/Cart.php:65-73` reads `Input::get('cart')`.
- Theme calls: `jax.ajax('Cart::onAdd', { data: { cart: cartData } })` (add-to-cart-control.js:68).
- Returns: `Result::get()` (JSON response with status, data, etc.).

**Convention Match:** YES. Plan's proposed pattern matches current usage.

---

## Question 5: Facebook Pixel Snippet Injection

**FINDING: YES, Already Integrated. Multiple Locations.**

**Main Pixel Initialization:**
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/facebook_pixel.htm`: Conditionally injects Meta Pixel (fbq) script.
  - Line 3: `fbq` function loader from `https://connect.facebook.net/en_US/fbevents.js`.
  - Line 3: Conditional `fbq('init', '{{ this.theme.facebook_pixel_id }}')` with user email/name/phone hashing (if logged in).
  - Line 3: `fbq('track', 'PageView')` fires on every page.
  - Noscript fallback image tag included.

**Pixel ID Source:** Theme config setting `this.theme.facebook_pixel_id` (read from theme properties, not hardcoded).

**Layout Integration:**
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/layouts/content.htm`: `{% partial 'facebook_pixel' obUser=obUser %}`
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/layouts/main.htm`: `{% partial 'facebook_pixel' obUser=obUser %}`

**Tracking Events:**
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/shared/tracking/facebook-add-to-cart.js`: `_fbq('track', 'AddToCart', ...)` after cart add.
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js:10-38`: `_fbq('init', '{{ pixel_id }}')` and `_fbq('track', 'Purchase', ...)` with pixel data.
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/pages/checkout.htm`: InitiatedCheckout event.
- `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/pages/order-complete.htm`: Custom ViewdOrderCompleatedStatusPage event.

**Status:** Pixel is ALREADY LIVE. No new snippet injection needed.

---

## Question 6: CSRF Token Pattern

**FINDING: Automatic via Larajax. NO Manual Token Injection Required.**

Larajax automatically includes CSRF token in ALL AJAX requests:

**Framework Level:**
- `/home/forge/nailscosmetics.lv/vendor/larajax/larajax/resources/src/request/options.js:98-116`:
  - `getCSRFToken()`: Reads from `<meta name="csrf-token">` tag (line 99).
  - `getXSRFToken()`: Reads from XSRF-TOKEN cookie (line 103-116).
  - Both tokens added to request headers if present (lines 60-68):
    - `X-CSRF-TOKEN` header (if meta tag exists).
    - `X-XSRF-TOKEN` header (if cookie exists).

**October CMS Integration:**
- October CMS automatically inserts `<meta name="csrf-token" content="...">` in layout head.
- Larajax detects and uses it automatically.
- **No theme-side action required.**

**Evidence:**
- Theme layout has NO explicit CSRF meta tag (already provided by October CMS core).
- No CSRF form hidden inputs needed (Larajax uses headers).

**Status:** CSRF handling is TRANSPARENT. Larajax + October CMS handle it automatically.

---

## Summary Table

| Question | Finding | Status |
|----------|---------|--------|
| 1. Larajax installed? | Yes, via composer | ✓ REAL |
| 2. Current AJAX pattern? | `jax.ajax('Component::onHandler', ...)` (Larajax → October CMS components) | ✓ CORRECT USAGE |
| 3. Route pattern? | NOT Larajax facades; October CMS component methods. No routes.php needed. | ⚠ PLAN NEEDS REVISION |
| 4. Shopaholic convention? | `addDynamicMethod()` on component, returns array. Matches plan. | ✓ MATCHES |
| 5. Pixel snippet? | Already integrated in `facebook_pixel.htm` partial. Live. | ✓ ALREADY DONE |
| 6. CSRF pattern? | Automatic via Larajax framework (meta tag + header). | ✓ TRANSPARENT |

---

## Recommendation for Plan §5

**AVOID:** Custom Larajax route facade pattern (`Larajax::get(...)`).

**USE INSTEAD:**
1. Add `onGetPixelInit` handler to Cart component (or extend dynamically via plugin event).
2. Call: `jax.ajax('Cart::onGetPixelInit', { data: {...} })` from JS.
3. Handler returns pixel data (already exists as `onGetPixelPurchaseData`).
4. CSRF and response handling: automatic via Larajax framework.

This matches the theme's existing pattern and avoids inventing routes.

---

**Conclusion:** "Larajax" is REAL. Plan §5's transport mechanism is SOUND, but routing syntax needs alignment with October CMS component handler conventions, not Larajax route facades.
