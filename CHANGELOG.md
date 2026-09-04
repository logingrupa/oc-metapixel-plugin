# Changelog

All notable changes to `logingrupa/oc-metapixel-plugin` are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

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
