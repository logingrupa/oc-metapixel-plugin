# Meta Pixel v3 Implementation: Q1-Q3 Answers

## Q1: Which Offer field should Meta Pixel use as content_id? `code` or `external_id`?

**Verdict:** Use neither—use **`offer_id` (mapped as `SKU-{product_id}` or `SKU-{product_id}-{offer_id}`)**. This matches the Meta catalog feed exactly.

**Proof:**
- Facebook catalog exporter outputs `g:id` with `offer_id` values: `/home/forge/nailscosmetics.lv/plugins/logingrupa/facebookcatalogshopaholic/classes/helper/GenerateXMLForFacebookCatalog.php:302` — `$this->obXMLWriter->writeElement('g:id', array_get($arOffer, 'offer_id'));`
- Catalog helper builds offer_id as: `/home/forge/nailscosmetics.lv/plugins/logingrupa/facebookcatalogshopaholic/classes/helper/ExportCatalogFacebookHelper.php:356` — `'offer_id' => 'SKU-' . $obOffer->product->id . '-' . $obOffer->id`
- Current purchase tracking **already uses this**: `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php:137-149` builds `SKU-{product_id}` (single-offer) or `SKU-{product_id}-{offer_id}` (multi-offer).
- Checkout InitiatedCheckout event passes `content_ids` as these SKU IDs: `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/pages/checkout.htm:93-94`

**Action:** No change needed—Meta Pixel v3 should continue using the SKU ID format already in place. This guarantees feed-pixel alignment.

---

## Q2: Is there a lead form on the site? Which plugin?

**Verdict:** **Yes. Salon application form at `/saloniem/pieteikt-salonu` (NOT Renatio FormBuilder, native PHP handler).**

**Proof:**
- Page `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/pages/salon/application-form.htm:13-74` defines `onSend()` handler that sends mail with subject `'Salona pieteikums - ' . $data['salon_name']`
- Receives: name, email, phone, salon_name, salon_address, number_of_masters, current_product_list, nail_extensions, other_treatment_list, profile_link
- Mails to: rolands.zeltins@gmail.com, zane.zelta@gmail.com, naisofiss@gmail.com
- This is a **lead capture** (salon inquiry form), not an ecommerce form.
- Renatio FormBuilder exists (`/plugins/renatio/formbuilder/`) but is not used for this form.

**Action:** Add Meta Lead event tracking to this form's `onSend()` handler. Fire `fbq('track', 'Lead')` on success before mail sends.

---

## Q3: Is there a cookie/consent banner?

**Verdict:** **No consent banner exists.** Site uses only session/auth cookies (not tracking-specific consent).

**Proof:**
- Theme partials, layouts, footer: no cookie banner element found.
- No consent plugin in `/plugins/`.
- Only functional cookies detected: Auth session management, user storage (`/plugins/lovata/toolbox/classes/storage/CookieUserStorage.php`), 1C auth cookies.
- Facebook Pixel fires unconditionally: `/themes/logingrupa-naisstore/partials/facebook_pixel.htm:1-5` — no consent check.

**Action:** **Assume all-track (no gating).** Meta Pixel v3 can fire all events (PageView, Lead, Purchase, ViewContent) without consent checks. If stakeholder adds GDPR consent banner later, add consent state check before fbq() calls.

---

**Summary for v3 implementation:**
1. content_id = `SKU-*` format ✅ (already in use, feed-aligned)
2. Lead event = salon form at `/saloniem/pieteikt-salonu` ✅ (add fbq('track', 'Lead') on onSend success)
3. No consent gating needed ✅ (fire all events unconditionally)
