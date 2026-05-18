# Logingrupa.Metapixel — Plugin Standards

Inherits parent `/home/forge/nailscosmetics.lv/CLAUDE.md` (Hungarian notation, Lovata.Toolbox patterns, Tiger-Style fail-fast, OctoberCMS v4 + Laravel 12 stack). This file adds plugin-specific rules.

## Identity

- **Namespace:** `Logingrupa\Metapixel`
- **OctoberCMS plugin identifier:** `Logingrupa.Metapixel`
- **Composer package:** `logingrupa/oc-metapixel-plugin`
- **PHP support:** `^8.3 || ^8.4` dual — avoid 8.4-only syntax (no property hooks, no asymmetric visibility, no `array_find`/`array_any`/`array_all`/`array_find_key`, no `#[\Deprecated]`)

## Architecture

Adapter pattern. Plugin core (`MetaClient`, `PayloadBuilder`, `UserDataHasher`, `EventLogWriter`) is generic. Per-subject behavior lives in `classes/adapter/<vendor>/`. Third parties register custom adapters from their `Plugin::boot()`.

```
classes/adapter/EventSubjectAdapter.php   # interface (subject metadata + Meta dispatch routing)
classes/adapter/ValueResolver.php          # interface (value/contents/currency)
classes/adapter/AdapterRegistry.php        # service-container singleton
classes/adapter/shopaholic/                # ShopaholicOrderAdapter (only dir importing Lovata\OrdersShopaholic\*)
classes/adapter/theme/                     # ThemeActionAdapter (Twig + Larajax API)
```

## Locked decisions (carried from v1.1.1, do NOT re-derive)

- **event_id direction:** server → frontend only. Server-generated UUIDv4. Never reverse.
- **EventLog UNIQUE race-fence:** `(subject_type, subject_id, event_name, channel, site_id)`. `EventLogWriter::record()` returns false on collision OR DB failure (fail-safe).
- **subject_type opaque alias:** `'shopaholic.order'`, NOT class FQN. Adapter contract returns alias string.
- **getSiteId from subject only:** `EventSubjectAdapter::getSiteId(object $obSubject): ?int` MUST read from subject, never from request context. PHPStan disallowed-calls bans `SiteManager::*`, `request()`, `Request::*` inside `classes/queue/`, `classes/event/`, `classes/adapter/`.
- **CR-02 TrustedHosts:** operator-supplied allowlist (Settings textarea) + `jeremykendall/php-domain-parser ^6.4` for multi-TLD subdomain index. Naive `explode('.')` is wrong + exploitable. Untrusted host → skip cookies (fail-safe).
- **CR-03 fbclid validation:** `[A-Za-z0-9_-]` charset, ≤255 chars. Invalid → skip `_fbc`.
- **PluginGuard pattern:** empty `pixel_id` → `Log::warning` + disabled flag, NEVER throw at boot (would cascade-break host site).
- **content_ids format (Shopaholic adapter):** `SKU-{product_id}[-{offer_id}]` matching Facebook Catalog feed exporter.
- **Graph API:** pinned to `v23.0`. v20 expires 2026-09-24. No operator override.
- **Multisite Settings:** `Multisite` trait on `pixel_id` + `capi_access_token` only. `$propagatable = []` (empty whitelist).

## Code style

- **Hungarian notation** (Lovata.Toolbox): `$ob`, `$ar`, `$i`, `$s`, `$f`, `$b`. PHPMD `ShortVariable min=4`. Avoid `$mId`, `$tmp` — prefer `$obSubjectAdapter`, `$arPurchasePayload`.
- **Self-explanatory class names:** describe purpose, not shape. `EventPixel` (server-confirmed browser pixel per event), NOT `SubjectPixel`.
- **Laravel short docblocks:** one-line summary + `@param` + `@return`. No multi-paragraph narrative.
- **No comment pollution:** zero `// CR-XX` / `// REFAC-XX` / `// Phase N` / `// Plan N` markers in code. Workflow refs belong in commits/PRs, not source.
- **No `assert()`** anywhere. Production `zend.assertions=0` silently no-ops. Use explicit `throw`. Enforced by `spaze/phpstan-disallowed-calls`.
- **No `declare(strict_types=1)` enforcement.** Optional per file.
- **Tiger-Style fail-fast:** throw at boundaries. `catch` only to log-and-rethrow OR dead-letter-persist. Every `catch` documents reason.
- **DRY + SRP:** adapters do NOT mix value-resolution + event-dispatch + payload-build.

### Model property convention

October model property names follow Laravel-standard names that October itself ships and overrides the parent Hungarian rule for these specific properties: `$table`, `$fillable`, `$jsonable`, `$casts`, `$rules`, `$customMessages`, `$attributeNames`, plus October relationship arrays (`$hasOne`, `$hasMany`, `$belongsTo`, `$belongsToMany`, `$morphTo`, `$morphOne`, `$morphMany`, `$morphToMany`, `$morphedByMany`, `$attachOne`, `$attachMany`). Rationale: october/boost rules supersede Hungarian for October-defined property names — October's docblock examples, scaffolding (`create:model`), and YAML config generators all assume Laravel-standard names; renaming them to `$arFillable` breaks IDE autocompletion, breaks PHPStan larastan inheritance resolution at level 10 for the Validation trait, and silently breaks any third-party plugin reading the model via reflection. Local variables and methods (`$obSubject`, `$arPayload`, `getEventName()`) stay Hungarian per the parent rule.

JSON columns use `$jsonable`, not the `'array'` cast. October's idiomatic JSON-in-text pattern is `protected $jsonable = ['column']` whenever the migration column is `text` or `longText`. The Eloquent `'array'` cast is functionally equivalent for round-trip but bypasses October's HasJsonable trait and reads as a Laravel-Eloquent idiom rather than an October idiom. Reference: `models/FailedEvent.php` declares `$jsonable = ['payload']` mirroring `updates/CreateMetapixelFailedEventsTable.php` `$obTable->longText('payload')`. Scalar casts (`int`, `bool`, `datetime`) continue to use `$casts`.

Internal append-only log / dead-letter models (`EventLog`, `FailedEvent`) intentionally skip `October\Rain\Database\Traits\Validation` and define no `$rules` array. Rationale: both tables are written exclusively by server-side helpers (`EventLogWriter::record`, `SendCapiEvent::writeFailedEvent`) that validate inputs at the helper boundary (Tiger-Style fail-fast — see EventLogWriter's `subject_id > 0` guard plus MetaClient's `MissingPixelConfigException` / `MissingCapiTokenException`). There is no user-input boundary that could deliver a malformed row. The one user-input model in this plugin (`Settings`) extends Lovata.Toolbox CommonSettings, which carries its own RainLab.Translate-aware validation pipeline. Any future model with a user-input boundary (e.g. an admin-facing operator-supplied row) MUST add the Validation trait + `$rules`.

## Build philosophy (locked)

- **No over-engineering.** Build only for current need. No defensive abstractions for hypothetical future cases.
- **No BC shims to v1.x.** Operators on legacy stay on `legacy/v1.1.1` branch. v2.0 is install-fresh-only.
- **No dead code, no unused functions.** Interface methods land only when first concrete caller lands.
- **Fresh code, NOT v1.x port.** v2.0 adapters re-derive logic following modern October 4 + Laravel 12 + Lovata.Toolbox idioms. Reuse v1.x DECISIONS, not v1.x code.
- **Simple > clever.** Five readable lines beat one clever line.

## Extensibility contract

Third parties hook the plugin via, in order of preference:

1. **`AdapterRegistry::register($sSubjectClass, $sAdapterClass)`** from their `Plugin::boot()` — register an adapter for any subject class.
2. **`Event::listen('metapixel.event.before_dispatch', ...)`** — halt-able payload mutation hook (third arg `$halt = true`; listener returning `false` vetoes dispatch). MUST NOT mutate `event_id` or `event_time` (dedup contract anchor).
3. **`Event::listen('metapixel.event.after_dispatch', ...)`** — observe-only successful-dispatch tap.
4. **`Event::listen('metapixel.event.dead_letter', ...)`** — observe-only permanent-failure alert hook.
5. **`App::bind(MetaClientInterface::class, ...)`** — HTTP client swap (testing or alternative transport).
6. **`Component::extend(PixelHead::class, ...)` + `addDynamicMethod(...)`** — LAST RESORT. Use ONLY when an Event::fire hook does not exist for your use case. Unbounded surface (every method can be replaced) — third parties must scope dynamic methods with an `onMetapixel*` prefix to avoid collisions.

Additional 5 `Event::fire` hooks deferred to v2.1 (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup). Add when a real third-party use case surfaces.

## Tooling (composer qa)

```
composer qa  →  pint-test → phpstan analyse (level 10, phpVersion 80300) → phpmd → pest --coverage --min=90
composer deps → composer-dependency-analyser  (enforces Lovata import boundary)
```

Coverage gate ≥ 90 % on full-Lovata CI matrix cell. Minimal-install cell excludes adapter tests via `pest --exclude-group=adapter`. Adapter tests are tagged with `#[PHPUnit\Framework\Attributes\Group('adapter')]` at the class level. Rationale: overlapping `<testsuite>` directories in `phpunit.xml` triggered "Cannot add file ... already added to test suite" warnings under PHPUnit 12 + Pest 4, flipping the qa exit code to 1 despite all tests passing. Group attributes are framework-native (PHPUnit + Pest) and orthogonal to directory layout; `pest()->group()->in()` in `tests/Pest.php` only tags Pest-style closures (`test()` / `it()`), not class-based tests that extend `MetapixelTestCase`.

## Reference

- `.planning/PROJECT.md` — milestone goal + carry-forward decisions
- `.planning/ROADMAP.md` — 5 phases + Architecture-at-a-glance (directory tree, data flow, Twig + extension examples)
- `.planning/REQUIREMENTS.md` — 61 v2.0 REQ-IDs grouped by category
- `.planning/milestones/v1.1.1-ROADMAP.md` — v1.x history (archived)
- `legacy/v1.1.1` git branch — v1.x source preserved
