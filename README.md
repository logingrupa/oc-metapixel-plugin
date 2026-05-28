# Logingrupa.Metapixel

Generic-event-tracking marketplace plugin for OctoberCMS 4.x — Meta Pixel + Conversions API behind a Lovata-style extensible adapter pattern. Tracks any subject (Shopaholic Order, theme action, third-party cart) through one pipeline; third parties register custom adapters from their own plugin without modifying core.

## ViewContent funnel (Shopaholic PDP)

The plugin closes the Meta conversion funnel at offer-level grain:

```
ViewContent (offer SKU)  →  AddToCart (offer SKU)  →  Purchase (offer SKU per line item)
```

Operators with Lovata.Shopaholic + Lovata.OrdersShopaholic installed get ViewContent firing automatically on PDP render plus on every offer-selector change, with zero theme code.

### Install

Add the `[productPixel]` component to the same layout or page that hosts Shopaholic's `[ProductPage]` component. The October layout INI block looks like this:

```ini
[ProductPage]
slug = "{{ :slug }}"

[productPixel]
==
```

Place `[productPixel]` between the page metadata block and `[ProductPage]` (order is not strictly enforced, but a top-of-layout placement matches the existing `[pixelHead]` head-tag pattern).

No properties to configure. The component is a no-op when `[ProductPage]` is not active OR when `PluginGuard::isDisabled()` (empty `pixel_id` Setting).

### How it works

1. Lovata fires `Event::fire('shopaholic.product.open', [$obProduct])` inside `ProductPage::getElementObject` after active + site guards pass.
2. `ProductPageWatcher` (auto-subscribed when Lovata.OrdersShopaholic is installed) builds the ViewContent payload, dispatches `SendCapiEvent` to the queue, and pushes a record onto `ThemeEventCollector`.
3. At `cms.page.beforeRenderPage`, `PixelHead` drains the collector into the deferred-flush buffer. The browser then receives an inline `<script>fbq("track", "ViewContent", {...}, {eventID: ...})</script>` carrying the same `event_id` the server CAPI dispatch sent — Meta dedupes the pair automatically.
4. When the operator's theme changes the `[name="offer_id"]` element on the PDP (any `<select>`, radio, or hidden input — Shopaholic canonical name), the inline JS in `[productPixel]` posts to `Metapixel::onFireEvent` with `subject_type: 'shopaholic.product'`, `subject_id: <product_id>`, and `offer_id: <offer_id>`. The server resolves the alias via `AdapterRegistry::resolveByAlias`, re-validates the subject via `ShopaholicProductAdapter::loadSubject` (active + site-match enforced), dispatches CAPI, and returns a fresh inline `fbq` script for the browser to fire.

### Soft-gate behavior

The offer-switch JS soft-gates on `window.__metapixelProduct` — a server-injected global. It fires only when `[productPixel]` rendered (= PDP context). Cart-modal bonus-box `[name="offer_id"]` selectors on non-PDP pages do NOT trigger spurious ViewContent.

If you are debugging a cart bonus-box selector and expect ViewContent to fire, that absence is intentional: PDP and offer-switch share the canonical Shopaholic form-field name `[name="offer_id"]`, but only PDP renders should emit ViewContent. The soft-gate enforces this without requiring operators to namespace their selectors.

### Test events

Set `test_event_code` in Settings → both the server CAPI payload and the browser fbq carry it. Verify the dedup contract in Meta Events Manager → Test Events panel: every ViewContent should appear once for the server CAPI source and once for the browser pixel source with the same `event_id`. Meta merges the pair into a single attributed event.

### Customization touchpoints

For advanced operators, see `tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php` for the full watcher contract and `tests/Feature/Components/ProductPixelTest.php` for the component-render and soft-gate assertions. Third-party plugins targeting non-Shopaholic catalog sources can register a custom adapter via `AdapterRegistry::register('vendor.product', VendorProductAdapter::class)` from their own `Plugin::boot()` — the same hybrid AJAX route then works without core changes.
