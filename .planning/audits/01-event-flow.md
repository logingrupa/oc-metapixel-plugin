# Audit 01 — Event flow + catalogue + user_data

## Findings

### §0 — Version 2 changes
- **§0 [CONFIRM]**: Plan correctly states event_id server → frontend. Code at `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/facebook_pixel.htm:2` shows fbq init already running server-side. Plan's Pattern A + B + C directions sound.
- **§0.5 [MISSING]**: Plan says Larajax. Codebase uses OctoberCMS `jax.ajax()` (theme purchase tracking line 10). Plan overspec'd transport layer — still works if plugin uses Larajax, but not mandatory for nailscosmetics.lv today.

### §1 — event_id flow
- **§1 [CONFIRM]**: Pattern A (server-rendered PageView, ViewContent, ViewCategory, InitiateCheckout) valid. Twig can output UUID on page load.
- **§1 [CONFIRM]**: Pattern B (AJAX AddToCart, AddToWishlist, Search) valid. Component returns event_id in response meta.
- **§1 [CONFIRM]**: Pattern C (backend-only Purchase) valid — must store `meta_purchase_event_id` on Order. Order model exists at `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/models/Order.php:29` with `$order_number` field (idempotency key).
- **§1 [ISSUE]**: Plan says `event_time` in seconds (microseconds not allowed). Existing purchase code at `/home/forge/nailscosmetics.lv/themes/logingrupa-naisstore/partials/form/checkout-form/tracking/facebook-purchase-tracking.js` does NOT send `event_time` field at all. Meta Conversions API defaults to NOW on server side, but drift >10sec→dedup fails. Plan must enforce `event_time: Math.floor(Date.now()/1000)` in frontend Pixel calls.

### §2 — Event catalogue
- **§2.2 [ISSUE]**: Plan assumes `content_ids: ["SKU-648-6415"]` format. Checked OfferItem at `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/item/OfferItem.php:23` and Offer model `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/models/Offer.php:36-37`. Model has `$code` and `$external_id` fields, NOT `SKU-*` prefixed. OfferItem exposes `.code` + `.external_id` directly, no SKU property. Plan assumes wrong naming — either use `$offer->code` (likely `SKU-648-6415` if admin set it that way) OR use `$offer->external_id`. Check nailscosmetics.lv Offer records to confirm pattern.
- **§2.3 [CONFIRM]**: ViewCategory event valid. First 10 offer ids fetchable from CategoryItem property list.
- **§2.9 [ISSUE]**: Purchase event assumes `order_id: "260422-0002"` format. Order model at line 29 has `.order_number` field. Confirm nailscosmetics.lv stores numbers in that format (matches live order `260422-0002`).
- **§2.9 [ISSUE]**: Plan says idempotency via `meta_purchase_event_id` column. Column does NOT exist on orders table yet — must be added in migration. Plan correctly identifies it as a new field to add (section 6, `add_meta_purchase_event_id_to_orders_table.php`).
- **§2.11 [MISSING]**: Plan lists CompleteRegistration event. No Lovata.Buddies hook subscribed today; theme does not fire user.register event. Plan needs `Event::listen('lovata.buddies.user.after.register', ...)` — correct per plan §4 but not yet live.

### §3 — user_data block
- **§3 [PARTIAL MISMATCH]**: Plan shows em, ph, fn, ln, ct, st, zp, country hashing + external_id. Buddies User model at `/home/forge/nailscosmetics.lv/plugins/lovata/buddies/models/User.php:23,30` has `$email`, `$phone` fields. Order model has no direct user email/phone — must fetch via `$order->user` relation. Buddies addresses at line 61 as `$address_list` collection (support ct, st, zp, country).
- **§3 [CRITICAL]**: external_id on user is available at `$user->id` (Buddies.user.id). For non-logged orders, plan says "session-stable anon id" but code at `facebook_pixel.htm:2` uses logged-in user only (`{% if obUser %}`). For anonymous checkout, external_id missing. Plan must specify anon id fallback (e.g., hash of IP+UA or order secret_key).
- **§3 [ISSUE — email hashing]**: Plan §3 says "lowercase + trim" before sha256. Buddies User model does not auto-lowercase on input. PayloadBuilder must call `strtolower(trim($email))` before hash. Same for phone: plan says "strip non-digits, prepend 371 if missing", but theme code at `facebook_pixel.htm:2` already does `trim|replace({"+": ""})` in Twig. Inconsistent. Plugin must own hashing, not rely on theme prep.
- **§3 [CONFIRM]**: fbp/fbc from cookies. Facebook Pixel loaded at `facebook_pixel.htm:1` (Meta's standard snippet). Cookies `_fbp`, `_fbc` set by Pixel JS automatically. Plan's EnsureFbpFbcCookies middleware fills gaps if cookies missing.
- **§3 [MISSING]**: Plan says EMQ ≥ 8 requires em + ph + client_ip_address + client_user_agent + fbp + fbc. Current checkout form (purchase tracking) sends NO user_data to Pixel at all — only cart contents. Plan must wire user_data block to every fbq('track', ...) call.

### §4 — Event hooks (Order lifecycle)
- **§4 [ISSUE]**: Plan says "Order status → Paid" determines when Purchase fires. Status model at `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/models/Status.php:44-47` defines const codes: `new`, `in_progress`, `complete`, `canceled`. There is NO `paid` or `samaksats` code in the base Shopaholic plugin. **Plan open question #1 unanswered**: which status code = "Paid" on this install? Must check backend or DB seeder for actual statuses in nailscosmetics.lv. Likely `complete` but unconfirmed.
- **§4 [CONFIRM]**: OrderStatusWatcher logic sound. Hook Order model's `afterUpdate` event, check if status flipped to Paid, fire once via idempotency field.

### §5 — Why Larajax
- **§5 [PARTIAL]**: Plan says $.request() is native OctoberCMS but theme uses `jax.ajax()`. Larajax not installed. Plan is theoretically correct on Larajax benefits but assumes it's chosen. Install decision not yet made for nailscosmetics.lv.

### §6 — Architecture
- **§6 [CONFIRM]**: Plugin folder structure matches plan. classes/meta/, classes/listeners/, classes/jobs/, controllers/, models/, updates/ — pattern aligns.

## Missing from plan

- **Anon user external_id** — no guidance on how to generate session-stable ID for non-registered checkout. Must either hash IP+UA or reuse order secret_key.
- **Offer SKU format confirmation** — plan assumes "SKU-648-6415" but code uses `.code` or `.external_id`. Must inspect actual Offer records.
- **Paid status code on this install** — plan open question #1. Default Shopaholic has `complete`, not `paid`. Must confirm in settings.
- **current fbq() call does NOT include user_data** — plan shows user_data on every event (§3 preamble), but theme purchase tracking only sends cart contents. Plugin must retrofit user_data block.
- **Consent/cookie-banner state** — plan has Consent helper class but no grep found in theme. Assume all-track for now? Or is there a cookie-banner plugin hiding?
- **Event time drift** — Pixel calls omit event_time field, risking >10s drift. Plan must spec this.

## Confirms plan correct

- **Order.order_number** — exists, stores format like "260422-0002". Idempotency column meta_purchase_event_id must be added.
- **Buddies user.id** — available as external_id source for logged-in users.
- **OfferItem exposes price, name, product.category** — all required for content_ids, content_name, content_category.
- **Larajax alternative OK** — plan's Larajax spec works but OctoberCMS $.request() or jax.ajax() also transport-capable.
- **TigerStyle fail-fast** — Lovata.Toolbox code already uses strict typing + assertions. Plugin pattern aligns.
- **Hungarian notation** — Shopaholic codebase uses $ob, $s, $i, $f prefixes. Plugin adoption correct.

---

**Red flags needing answers before sprint 1:**
1. Paid status code — is it `complete`, `paid`, or `samaksats`?
2. Offer content_id — use `.code` or `.external_id`? Or format as "SKU-{id}"?
3. Anon external_id — how to stable ID non-logged checkout?
4. Consent banner — where is state stored? Or assume no consent check?
5. Event time — add `Math.floor(Date.now()/1000)` to every fbq() call?

