# Changelog

All notable changes to `logingrupa/oc-metapixel-plugin` are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [2.1.8] - 2026-09-11

### Fixed

- **Request identity is attached only for the customer's browser.** A Purchase fired by a backend admin save, the 1C order exchange or a gateway webhook used to carry that caller's IP, user agent and cookies as if they were the buyer's. One shop sent the 1C server's IP on 9 out of 10 Purchases once its paid status moved to the one 1C sets. The Shopaholic watchers now attach IP, user agent, `_fbp` and `_fbc` only outside the backend and only when the user agent is a browser.

### Added

- **Buyer IP from the user account.** The order adapter fills `client_ip_address` from the order user's `last_ip_address` (RainLab.User or Buddies), so a Purchase fired without the buyer's request still carries their IP.
- **Several paid statuses.** The Paid status setting is a checkbox list. The Purchase fires the first time an order reaches any ticked status, once per order, so card and PayPal orders fire at the gateway status with the buyer's click id while bank transfers fire when the ERP marks them paid. A saved single value from before keeps working.

## [2.1.7] - 2026-09-11

### Fixed

- **Stale click ids no longer reach the Conversions API.** Meta flagged the integration for sending an `fbc` whose `fbclid` was over 90 days old. `fbevents.js` keeps extending the `_fbc` cookie on every page view, so a customer who clicked an ad months ago still carried the old value and every server event forwarded it. `FbcValue::fresh()` now parses the value, drops it when malformed or when its creation time is older than the 90 day cookie lifetime, and both merge points apply it: the hasher passthrough for adapter and listener values, and the request cookie merge in `UserDataResolveHook`. The cookie itself is never rewritten, as Meta requires.

## [2.1.6] - 2026-09-05

### Fixed

- **A failed replay keeps Meta's answer.** The row used to get the bare `graph API permanent 400` line, which overwrote the decoded Graph response the dead-letter writer had stored. Both writers now share `FailedEvent::graphErrorFrom()`, and the flash quotes Meta's `error_user_msg` (for example the missing customer information text) after the status line.
- **Rows older than 7 days are refused before any Graph call.** Meta rejects `event_time` older than 7 days, so replaying such a row could only fail. The flash says so and points to Delete.

### Changed

- **Transient Graph failures no longer log per retry attempt.** `MetaApiTransientException` implements Laravel's `ShouldntReport`, so a resolver hiccup that the queue retries in 1s does not write a warning to the log and the backend event log (one live shop had 86 such lines in a day, all recovered). The exhausted job logs a single warning from `failed()` when it dead-letters.
- **Connect timeout 5s, request budget 10s.** A stub resolver moves to its next upstream after about 5s; the previous 2s bound failed the attempt on every slow answer.

## [2.1.5] - 2026-09-05

Failed events becomes an inbox. The list opens on the rows that still need a replay, and the per-row dedup numbers are gone.

### Added

- **Status column and Replayed filter.** Every row shows *Needs replay* or *Replayed* (with the replay time on hover). The filter switch defaults to rows that need a replay; switch it on to see replayed rows, clear it to see everything.
- **Dataset quality panel.** *Check dedup* now reads Meta Dataset Quality once for the whole pixel and renders EMQ and coverage per event name above the list, with a fetched-at time. No row is touched.
- **Tooltips and a help callout.** The toolbar buttons explain what they do, and the callout above the list states the 48 hour replay window, the 7 day retention and that dataset quality describes the pixel, not the rows.
- **Daily purge covers failed events.** `metapixel:purge-event-log` deletes FailedEvent rows older than 7 days alongside the event log; Meta rejects events older than that, so those rows could never be replayed.

### Changed

- **Successful replays are stamped.** Replay writes `replayed_at` on the row in addition to clearing the HTTP status and Graph error.

### Removed

- **Per-row `dedup_pct`, `emq` and `dedup_checked_at` columns.** The Dataset Quality API reports per event name for the whole pixel, so every Purchase row carried the same pair of numbers and none of them said anything about that event. The migration drops the three columns, adds `replayed_at`, and backfills it from `updated_at` for rows that were replayed before the upgrade. The `onCheckDedup` and `onCheckDedupBatch` AJAX handlers are replaced by `onCheckDatasetQuality`.

## [2.1.4] - 2026-09-05

### Changed

- **The empty `pixel_id` warning is written once per day.** `PluginGuard` used to log `pixel_id is empty` on every request of a shop that runs without a pixel, which filled the backend event log with thousands of identical rows. The warning now goes through `Cache::add` with a 24 hour lifetime, so one line per day per host. The guard still disables the plugin softly and never throws.

## [2.1.3] - 2026-09-05

### Changed

- **Purchase `user_data` carries the shipping address.** The Lovata shipping address keys of the order property JSON (`shipping_city`, `shipping_state`, `shipping_postcode`, `shipping_country`) map to `ct`, `st`, `zp` and `country`. Pickup orders without a structured address keep null, and the hasher still drops any country value that is not an ISO 3166-1 alpha-2 code.

## [2.1.2] - 2026-09-04

### Fixed

- **The built-in account listener loads the session user before reading it.** Toolbox `UserHelper::getUser()` forwards to the auth facade's `getUser()`, which on the Laravel session guard returns only the user already loaded in this request and never reads the session. On a product page the first `metapixel.user_data.resolve` call comes from the ViewContent watcher before anything has touched the guard, so the listener saw no user and the empty result was memoised for the request: the browser Advanced Matching object and every in-request CAPI event lost the identity. The listener now calls the facade's `check()` first, which loads the session user.

## [2.1.1] - 2026-09-04

The logged-in account becomes the event identity without a host listener.

### Added

- **Built-in account identity listener.** `AccountIdentityHandler` subscribes to `metapixel.user_data.resolve` and reads the logged-in customer through Lovata Toolbox `UserHelper`, so RainLab.User and Lovata.Buddies both work with no code in the host. It fills `em`, `ph`, `fn`, `ln` and `external_id`, and only the keys a host listener left empty (the first listener wins).
- **Advanced settings.** `Send the logged-in account as the event identity` (off by default) switches the listener on. `Phone country calling code` (digits, for example `371`) is prefixed to phone numbers stored as exactly 8 national digits; without it those phones are not sent. The first entry of a comma-separated phone field is used.

### Changed

- **The hook does not fire while the plugin is disabled or for a crawler user agent.** No CAPI event ships for those requests, so `UserDataResolveHook` returns an empty identity without asking any listener for a user lookup.

## [2.1.0] - 2026-09-04

Customer identity on every event. Until now only Purchase carried em, ph, fn and ln (from the Order row); ViewContent, AddToCart, PageView and Search shipped ip, user agent, fbp and fbc alone, and the browser pixel initialised without Advanced Matching.

### Added

- **`metapixel.user_data.resolve` hook.** Fires once per request at the request boundary, before any payload is merged. Listeners receive `array &$arUserData` (raw `em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`, `external_id`) and a context array (`event_name`, `subject_type`). The plugin hashes the values, memoises them for the request and merges them into every in-request dispatch path: base PageView and the deferred collector mirror (`PixelHead`), AddToCart, ViewContent and the offer switch (watchers), Search and other theme beacons (`ThemeAjaxHandler`). Adapter-supplied values win, so Purchase keeps the Order data. A throwing listener abstains.
- **Browser Advanced Matching.** `PixelHead` renders `fbq('init', id, {em, ph, ...})` from the same hashed identity, only for keys that are present; anonymous visitors keep the plain init.
- **`UserDataHasher::hashRaw` / `hashIdentity`.** Public entry points over the per-field normalisers.

### Changed

- **Hasher normalisation follows Meta's customer-information rules per field.** Phone keeps digits only and drops leading zeros (the country code must be supplied by the caller). Names are lowercased with punctuation removed and UTF-8 letters kept. City, state and zip are lowercased with spaces and punctuation removed. Country must be a two-letter ISO 3166-1 alpha-2 code or it is dropped. External id is trimmed with its case preserved. Email is unchanged (lowercase, trimmed).
- `CapturesRequestUserData::injectRequestUserData` and `ThemeAjaxRequestReader::injectServerUserData` take the event name and subject type ahead of the payload.

## [2.0.0] - 2026-05-27

Initial public release. Generic-event-tracking marketplace plugin for OctoberCMS 4.x — Meta Pixel + Conversions API behind a Lovata-style extensible adapter pattern. Tracks any subject (Shopaholic Order, theme action, third-party cart) through one pipeline; third parties register custom adapters from their own plugin without modifying core.

### Added

- **Generic adapter pipeline.** `EventSubjectAdapter` + `ValueResolver` interface pair resolved at runtime via `AdapterRegistry` singleton. One pipeline drives every subject through `MetaClient` + `PayloadBuilder` + `UserDataHasher` + `EventLogWriter`.
- **ShopaholicAdapter.** Tracks `Lovata\OrdersShopaholic\Models\Order` — Purchase + AddToCart events with `SKU-{product_id}[-{offer_id}]` content_ids matching Catalog feed exporter conventions.
- **ThemeActionAdapter.** Twig API + Larajax handler — operators emit events from theme partials without writing PHP.
- **Server-direction `event_id` contract.** Server-generated UUIDv4 flows to browser fbq via `EventPixel` component; Meta dedupes on `event_id` match within ±10 s. EventLog UNIQUE race-fence on `(subject_type, subject_id, event_name, channel, site_id)` prevents double-send.
- **`PixelHead` component.** Drop-in head-tag base pixel — wires automatically via theme layout INI declaration + `{% component 'pixelHead' %}`. Restores PageView coverage from any theme.
- **`EventPixel` component.** Per-event server-confirmed browser pixel. Reads EventLog server-side; emits inline `fbq('track', …, {eventID:<uuid>})` only when the matching `channel='capi'` row exists and the corresponding `channel='pixel'` row is still absent.
- **3 `Event::fire` extension hooks.** `metapixel.event.before_dispatch` (halt-able payload mutation), `metapixel.event.after_dispatch` (observe-only), `metapixel.event.dead_letter` (observe-only permanent-failure alert).
- **`SendCapiEvent` queue job.** Fail-safe queued CAPI dispatch with `MetaApiTransientException` retry classification, dead-letter persistence to `FailedEvent`, and listener-isolation try/catch around every fire site.
- **Multisite Settings (`MULT-01..06`).** Per-site `pixel_id` + `capi_access_token` via Lovata Multisite trait; site-scoped credential lookup at dispatch.
- **TrustedHosts allowlist + subdomain cookie index (`HOST-01..06`).** Operator-supplied trusted_hosts allowlist plus `jeremykendall/php-domain-parser` for multi-TLD subdomain derivation. Untrusted host → cookies skipped (fail-safe).
- **`EnsureFbpFbcCookies` middleware (`COOK-01..03`).** Honors a kill-switch toggle in Settings; CR-03 fbclid validation (`[A-Za-z0-9_-]`, ≤255 chars); invalid `fbclid` → skip `_fbc`.
- **`FailedEvents` backend controller (`FAIL-01..03`).** Admin list + Replay action with dedup-status verification.
- **`PluginGuard`.** Empty `pixel_id` logs a warning and sets a disabled flag — never throws at boot, so host site cannot cascade-break.
- **Graph API pinned at `v23.0`.** No operator override; v20 expiry is 2026-09-24.
- **English + Latvian translations** for every UI surface (`LANG-01`).
- **`docs/CUSTOM-ADAPTERS.md`.** Third-party adapter authoring guide with both `AcmeCartAdapter` minimal-registration example and `OFFLINE\Mall\MallOrderAdapter` full-contract example (~50 LOC each). `EventSubjectAdapterContractTestCase` reference for marketplace contract enforcement.
- **`composer qa` toolchain.** Pint formatting, PHPStan level 10 with `phpVersion 80300` scoped disallowed-calls deny-list (banning `SiteManager`, `Request`, `request()` in `classes/queue/*`, `classes/event/*`, `classes/adapter/*`), PHPMD, and Pest 4 with ≥90 % coverage gate.
- **PHP 8.3 + 8.4 dual-version support.** CI matrix covers full-Lovata and minimal-install cells.

**ViewContent funnel (Shopaholic PDP)**

- `PixelHead` deferred flush at `cms.page.beforeRenderPage` — permits page-tier component pushes to land before the fbq script render; base PageView emission unchanged; `eventID` 4th-arg supported. Decouples the beforeRenderPage listener from the Twig render context via the new request-scoped `PixelHeadDeferredFlushBuffer` singleton plus `PixelHead::renderDeferredBlocks()` markup helper.
- `AdapterRegistry::resolveByAlias` — register-time alias index gives O(1) subject-type lookup for the hybrid AJAX route. Adapters opt into hybrid AJAX by implementing the new `SupportsHybridAjax` subinterface; unknown aliases surface as a typed `UnknownSubjectTypeException` returning HTTP 422.
- `ShopaholicProductAdapter` + `ShopaholicProductValueResolver` — subject-type alias `'shopaholic.product'`. Site context fallback for products without an explicit `site_id`; SoftDelete-aware `loadSubject` re-enforces active + site-match guards on every hybrid AJAX hit.
- `ProductPageWatcher` — subscribes Lovata's `shopaholic.product.open` event. Dispatches the ViewContent CAPI envelope and pushes a matching record onto `ThemeEventCollector` so the browser fbq twin emits with the same server-generated `event_id`.
- `[productPixel]` component (`Logingrupa\Metapixel\Components\ProductPixel`) — vendor-neutral PDP browser pixel. Emits a `window.__metapixelProduct` server-injected global plus a delegated `change`-listener for `[name="offer_id"]` DOM elements. Idempotency-guarded; soft-gated against cart-modal selectors so non-PDP pages cannot fire spurious ViewContent.
- `Metapixel::onFireEvent` hybrid `subject_type` routing — allowlist gated through `AdapterRegistry::resolveByAlias`. JS-supplied subject-type strings are byte-for-byte matched against the register-time alias index; no class FQN is deserialized from untrusted input.
