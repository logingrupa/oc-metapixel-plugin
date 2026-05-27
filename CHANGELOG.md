# Changelog

All notable changes to `logingrupa/oc-metapixel-plugin` are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

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
