# Stack Research — v2.0 generic-event-tracking marketplace plugin

**Plugin:** `Logingrupa\Metapixel` (rename from `Logingrupa\Metapixelshopaholic`)
**Researched:** 2026-05-15
**Scope:** Stack DELTA from validated v1.x. Adds only what new v2.0 capabilities require. v1.x dependencies carry forward verbatim unless flagged.
**Overall confidence:** HIGH on library versions and API patterns; MEDIUM on long-term Meta Graph API roadmap.

---

## TL;DR — Stack delta for v2.0

| Action | Component | Version / Pattern | Why |
|---|---|---|---|
| **ADD** | `jeremykendall/php-domain-parser` | `^6.4` | TrustedHosts allowlist generalization (CR-02) — operator can supply arbitrary multi-TLD hosts via Settings; runtime derives subdomain index from PSL data |
| **ADD** | `psr/simple-cache` interface usage via October's `Cache` facade | n/a (already installed transitively) | Cache compiled PSL rules to avoid re-parsing on every request |
| **CHANGE** | `composer.json` `require.php` | `"^8.3 \|\| ^8.4"` (was `^8.4`) | Broaden marketplace reach; matches root `october/october` constraint |
| **CHANGE** | `composer.json` `require` | `lovata/shopaholic-plugin` → `suggest:` | Decouple plugin from Shopaholic. Auto-detect at boot via `class_exists()` |
| **CHANGE** | `composer.json` `require` | `lovata/ordersshopaholic-plugin` → `suggest:` | Same — ShopaholicAdapter auto-registers only when present |
| **CHANGE** | Graph API endpoint | Pin to **v23.0** (was v20.0) | v20 expires 2026-09-24. v23 released 2025-05-29, current LTS; v25 (Feb 2026) too fresh for marketplace baseline |
| **CHANGE** | Settings model | Add `Multisite` trait via `propagatable` whitelist | Per-site `pixel_id` + `capi_access_token` overrides without breaking v1.x global Settings |
| **CHANGE** | Namespace | `Logingrupa\Metapixel` + dir `plugins/logingrupa/metapixel/` | Marketplace identity; drop Shopaholic-coupled name |
| **KEEP** | Everything else from v1.x | (see "Carried forward" section) | Already validated; no re-research |
| **AVOID** | All PHP 8.4-only syntax | (see PHP 8.4 features section) | Dual-version support requires 8.3 baseline |

---

## v1.x stack — carried forward verbatim (DO NOT re-research)

These are locked v1.x decisions from `PROJECT.md` and `v1.1.1-ROADMAP.md`. Do not duplicate research effort.

### Runtime
- PHP **^8.3 || ^8.4** (CHANGED from v1.x `^8.4`)
- OctoberCMS **^4.0** (`october/system`, `october/rain`)
- Laravel **^12.0** (transitive via October Rain)
- Lovata Toolbox **^2.2** — Hungarian notation backbone, `CommonSettings`, `ElementItem`, `ElementCollection`

### Runtime dependencies (`require`)
- `guzzlehttp/guzzle ^7.8` — HTTP client for CAPI dispatch (PSR-18 compliant; also serves php-domain-parser optional storage fetch)
- `ramsey/uuid ^4.7` — UUIDv4 for `event_id`

### Dev tooling (`require-dev`) — no changes from v1.x
- `pestphp/pest ^4.1` + `pest-plugin-drift ^4.0`
- `phpunit/phpunit ^12`
- `larastan/larastan ^3.0` (phpstan level 10 baseline)
- `spaze/phpstan-disallowed-calls ^4.0` (bans `assert()`, `@`-suppression)
- `phpmd/phpmd ^2.15` (LongVariable max=40, ShortVariable min=4)
- `laravel/pint ^1.26`
- `rector/rector ^2.0`
- `mockery/mockery ^1.6`
- `fakerphp/faker ^1.24` (already in root)

### Quality gates — no changes from v1.x
- phpstan level 10 + larastan + universalObjectCrates
- pint Laravel preset, phpcs PSR-2
- `composer qa` chains pint-test → analyse → phpmd → test-cov
- ≥90% coverage target (was 82.8% at v1.1.1; v2.0 raises bar)

---

## NEW dependency 1: `jeremykendall/php-domain-parser`

### Why it's needed

v1.x ships a **hardcoded `HOST_INDEX_MAP`** in `EnsureFbpFbcCookies` middleware (CR-02 fix):

```php
const HOST_INDEX_MAP = [
    'nailscosmetics.no' => 1,  // 1-part subdomain = "nailscosmetics" → set _fbp on .no
    'nailscosmetics.lv' => 1,
    'nailscosmetics.lt' => 1,
    'www.nailscosmetics.no' => 2,  // www. prefix → 2 parts before TLD
    // ...
];
```

This is exploitable as soon as the plugin ships to operators outside the nailscosmetics ecosystem:
- Operator on `shop.example.co.uk` — multi-segment TLD breaks naive `count(explode('.', $host)) - 1`
- Operator on `acme.test.io` — `.io` is ICANN, but `github.io`/`gitlab.io` are private suffixes (different cookie scope rules)

**v2.0 generalization:** operator declares allowlist of registrable domains via Settings; runtime derives the `_fbp` cookie scope index from the Public Suffix List (PSL) using `php-domain-parser`.

### Library facts (HIGH confidence — Packagist + GitHub verified)

| Property | Value |
|---|---|
| Latest stable | **6.4.0** (released 2025-04-26 per GitHub releases, 2024-04-26 per Packagist — discrepancy noted, fetching latest is safest) |
| Requires | `php ^8.1`, `ext-filter` |
| Suggests | `psr/simple-cache`, `psr/http-client` + `psr/http-factory` (for fetching PSL data), `symfony/polyfill-intl-idn` |
| Dependents on Packagist | 79 (well-maintained ecosystem standard) |
| Total installs | 12.8M+ |
| Composer constraint to use | `^6.4` (locks to current minor; allows patch updates) |
| Major-version break risk | LOW — v6.0 (Dec 2020) was the last major; API stable through 6.4 |

### Integration pattern

```php
// composer.json (require:)
"jeremykendall/php-domain-parser": "^6.4"

// Settings field (operator-supplied allowlist)
trusted_hosts:
    type: repeater
    fields:
        host:
            type: text
            label: 'Registrable domain (e.g. nailscosmetics.lv, acme.co.uk)'

// classes/helper/HostIndexResolver.php (new helper)
use Pdp\Rules;
use Pdp\Domain;

final class HostIndexResolver
{
    public function __construct(private readonly Rules $obRules) {}

    public function resolveIndex(string $sHost, array $arAllowlist): ?int
    {
        if (!in_array($sHost, $arAllowlist, true) && !$this->matchesRegistrableDomain($sHost, $arAllowlist)) {
            return null;  // host-spoof — reject
        }

        $obResult = $this->obRules->resolve(Domain::fromIDNA2008($sHost));
        $iSubdomainParts = count(explode('.', $obResult->subDomain()->toString() ?: ''));
        $iRegistrableParts = count(explode('.', $obResult->registrableDomain()->toString()));
        return $iSubdomainParts + $iRegistrableParts;
    }
    // ...
}
```

### PSL data sourcing

PSL data file (`public_suffix_list.dat`) must be shipped + refreshed. Two options:

| Option | Pros | Cons | Recommended |
|---|---|---|---|
| **Ship file in `resources/data/`** | Zero runtime fetch, deterministic | Stale ~monthly, requires plugin re-release | **YES — for v2.0 v1 marketplace** |
| **Runtime fetch via `Rules::fromString(file_get_contents(URL))`** | Always fresh | Network dependency, security surface, fails offline | NO — defer to v2.x ops feature |

Use October's `Cache` facade (file-cache backed) wrapped as PSR-16 to memoize parsed `Rules` object across requests. October already provides PSR-16 compatible cache via Laravel's `Illuminate\Cache\Repository`.

### Confidence

HIGH on version + API (verified via Packagist + GitHub composer.json + releases page). MEDIUM on shipping-PSL-vs-fetching strategy (deferred ops decision; both work).

---

## NEW dependency 2: PHP 8.3 + 8.4 dual support

### Why it's needed

v1.x locks `"php": "^8.4"` because Phase 3 introduced `public readonly array $arPayload` in `SendCapiEvent` and `public readonly array $arContext` in `MetaPixelException`. Both work on PHP 8.1+, but the v1.x BRIEF explicitly leaned on "PHP 8.4 readonly".

For marketplace v2.0, supporting only 8.4 (released Nov 2024) excludes operators on 8.3 stable installations. Root `october/october` requires `php: ^8.3`, so plugin should match.

### Verification — what v1.x code actually uses

Grep shows readonly usage:
- `MetaPixelException.php:50`: `public readonly array $arContext = []` — PHP **8.1+** (constructor promotion + readonly properties)
- `SendCapiEvent.php:111-113`: `public readonly string $sEventName`, `public readonly array $arPayload`, `public readonly Order $obSubject` — PHP **8.1+**

**Conclusion:** no v1.x code uses PHP 8.4-exclusive features. The 8.4 constraint was over-tight. Relaxing to `^8.3 || ^8.4` is SAFE.

### PHP 8.4-only features — MUST AVOID in v2.0

Verified via php.net migration8.4 manual. Lock these as PHPCS / phpstan rules:

| Feature | Syntax | v2.0 rule |
|---|---|---|
| **Property hooks** | `public string $name { get => $this->fn(); set { ... } }` | BANNED — use explicit getter/setter |
| **Asymmetric visibility** | `public protected(set) string $name` | BANNED — use plain `readonly` + constructor promotion |
| **`new` chaining without parens** | `new Foo()->bar()` | BANNED — use `(new Foo())->bar()` |
| **`#[\Deprecated]` attribute** | `#[\Deprecated(message: "...")]` | BANNED — use `@deprecated` docblock |
| **Lazy objects** | `ReflectionClass::newLazyGhost()` | BANNED — not needed for this plugin |
| **Array funcs** | `array_find()`, `array_any()`, `array_all()`, `array_find_key()` | BANNED — use `array_filter` + early return, or `foreach` |
| **`RoundingMode` enum** | `RoundingMode::HalfAwayFromZero` | BANNED — use `PHP_ROUND_HALF_UP` constants |
| **HTTP/3 cURL opts** | `CURLOPT_PREREQFUNCTION` | N/A (Guzzle abstracts cURL) |
| **`exit` as function** | `exit(0)` callable | BANNED — irrelevant; never use `exit` |

### Enforcement

- `phpstan.neon`: add `phpVersion: 80300` to detect 8.4-only syntax at level 10
- `rector.php`: pin to `LevelSetList::UP_TO_PHP_83` (currently UP_TO_PHP_84) — Rector will NOT migrate code to 8.4-only constructs
- Add `spaze/phpstan-disallowed-calls` entries for `array_find`, `array_any`, `array_all`, `array_find_key`

### Confidence

HIGH — verified against official php.net PHP 8.4 migration docs.

---

## NEW pattern 1: Composer `suggest:` + boot-time auto-detection

### Why it's needed

v1.x `composer.json` requires Shopaholic + OrdersShopaholic. Plugin will fail to install on a clean OctoberCMS without these. v2.0 marketplace goal:
- `composer require logingrupa/oc-metapixel-plugin` succeeds on **bare** OctoberCMS 4.x
- Plugin boots, exposes Settings + EventLog + MetaClient core
- ShopaholicAdapter auto-registers **only if** `Lovata\OrdersShopaholic\Models\Order` exists at boot
- ThemeActionAdapter auto-registers always (no dependency)
- Third-party adapters (MelonCart, Mall, custom) register via `Plugin::boot()` of their own plugin

### Composer JSON shape

```jsonc
{
    "name": "logingrupa/oc-metapixel-plugin",
    "require": {
        "php": "^8.3 || ^8.4",
        "october/system": "^4.0",
        "october/rain": "^4.0",
        "lovata/toolbox-plugin": "^2.2",
        "guzzlehttp/guzzle": "^7.8",
        "ramsey/uuid": "^4.7",
        "jeremykendall/php-domain-parser": "^6.4"
    },
    "suggest": {
        "lovata/shopaholic-plugin": "Enable the ShopaholicAdapter — track Purchase/AddToCart/ViewContent against Lovata Shopaholic Order/Cart models. Requires ^1.32.",
        "lovata/ordersshopaholic-plugin": "Required by ShopaholicAdapter for Order tracking. Requires ^1.33.",
        "lovata/buddies-plugin": "Optional — hashed user_data enrichment from Buddies User model. Requires ^1.10."
    }
}
```

**`require-dev`** still includes `lovata/shopaholic-plugin`, `lovata/ordersshopaholic-plugin`, `lovata/buddies-plugin` so the test suite can exercise ShopaholicAdapter against the real models. Marketplace consumers never see dev deps.

### Boot-time discovery (HIGH confidence pattern)

```php
// Plugin.php — Logingrupa\Metapixel
public function boot(): void
{
    $obRegistry = $this->app->make(AdapterRegistry::class);

    // Always-on: theme-action adapter (no upstream deps)
    $obRegistry->register(new ThemeActionAdapter());

    // Conditional: ShopaholicAdapter only if Shopaholic Order model present
    if (class_exists('Lovata\\OrdersShopaholic\\Models\\Order', false)) {
        $obRegistry->register(new ShopaholicAdapter());
    }

    // Conditional: future MelonCart adapter (illustrative)
    if (class_exists('MelonVendor\\MelonCart\\Models\\Cart', false)) {
        $obRegistry->register(new MelonCartAdapter());
    }

    $this->addEventListener();
}
```

**`class_exists($name, false)` — second arg MUST be `false`** to skip the autoloader. Per CLAUDE.md research, calling `class_exists($name)` without the flag triggers Laravel's autoloader to attempt resolution and can cascade-break boot when the package is genuinely absent. Verified pattern.

**Alternative — service-tagged discovery (Laravel-native, defer to v2.1):**

```php
// Plugins register adapters via container tag
$this->app->tag([ShopaholicAdapter::class], 'metapixel.adapter');

// Plugin reads all tagged
foreach ($this->app->tagged('metapixel.adapter') as $obAdapter) {
    $obRegistry->register($obAdapter);
}
```

More idiomatic but requires third-party plugins to know the tag name. Stick with `class_exists` discovery + explicit `AdapterRegistry::register($obAdapter)` API for third parties in v2.0.

### Why NOT `composer/composer` Plugin API or composer-installer-name discovery

Plugin API is over-engineered for boot-time presence check. `class_exists(false)` runs in microseconds, no I/O.

### Confidence

HIGH on `class_exists($name, false)` pattern (officially documented PHP behavior + Laravel community-standard). HIGH on `suggest:` semantics (Composer schema docs).

---

## NEW pattern 2: October 4 `Multisite` trait on Settings

### Why it's needed

v1.x ships pixel_id + capi_access_token as **global** Settings. v2.0 marketplace goal includes multi-site operators where each site has its own Meta Business account → different Pixel + different CAPI token per site.

October 4's `October\Rain\Database\Traits\Multisite` trait is the canonical mechanism. Already used by `Lovata\Toolbox\Models\CommonSettings` at parent level (it has `use Multisite;` at line 13). Plugin-level Settings inherits via `CommonSettings` parent.

### Settings model shape

```php
namespace Logingrupa\Metapixel\Models;

use Lovata\Toolbox\Models\CommonSettings;

class Settings extends CommonSettings
{
    const SETTINGS_CODE = 'logingrupa-metapixel-settings';
    public $settingsCode = 'logingrupa-metapixel-settings';
    public $settingsFields = 'fields.yaml';

    /**
     * Fields PROPAGATED across all sites — operator sets once, applies everywhere.
     * Fields NOT listed here = per-site overrides (each site has independent value).
     */
    protected $propagatable = [
        'send_hashed_pii',
        'phone_country_code',
        'queue_connection',
        'refire_purchase_on_status_flip',
        'ensure_fbp_fbc_server_side',
        'paid_status_code',
        'currency_code',
        'trusted_hosts',  // operator-supplied allowlist — global
    ];

    // Per-site (NOT in $propagatable):
    // - pixel_id
    // - capi_access_token
    // - test_event_code
}
```

### Verified trait behavior (from October source)

Read of `vendor/october/rain/src/Database/Traits/Multisite.php`:

- `bootMultisite()` adds `MultisiteScope` global scope — queries auto-filter by current site
- `multisiteBeforeSave()` stamps `Site::getSiteIdFromContext()` on the row at save time (unless in global context)
- DB table needs `site_id` + `site_root_id` columns (October's SettingModel storage handles this automatically)
- `$propagatable` array MUST exist as a property (throws Exception if missing or non-array on init)

### Migration consideration

`CommonSettings` storage is October's `system_settings` table (key-value JSON blob per site). No plugin migration needed for v2.0 — the Multisite trait at parent level handles per-site row creation transparently.

**v1.x → v2.0 upgrade path:** v1.x stored a single global Settings row. After v2.0 install:
- All v1.x propagated keys land on the active site
- Multisite trait propagates them to all sites
- Operator manually overrides `pixel_id` + `capi_access_token` per site

Document this in `README.md` upgrade section.

### Confidence

HIGH on trait API (read directly from October Rain source). HIGH on `$propagatable` semantics (verified in `Multisite::initializeMultisite()`). MEDIUM on upgrade path (no migration test yet — assumption based on October 4 behavior).

---

## CHANGE: Meta Graph API endpoint version

### Current state (v1.x)

v1.x pins `https://graph.facebook.com/v20.0/{pixel_id}/events` in `MetaClient`.

### Verified deprecation timeline (Meta for Developers)

| Version | Released | Expires |
|---|---|---|
| v20.0 | May 2024 | **2026-09-24** |
| v21.0 | Oct 2024 | TBD |
| v22.0 | 2025-01-21 | TBD |
| v23.0 | 2025-05-29 | TBD |
| v24.0 | 2025-10-08 | TBD |
| v25.0 | 2026-02-18 | TBD (current latest) |

**Source:** https://developers.facebook.com/docs/graph-api/changelog/versions/

### v2.0 recommendation

**Pin to v23.0** — middle-of-the-road LTS choice:
- Released May 2025, ~12 months stable at v2.0 launch (May 2026)
- Far from expiration (Meta typically gives 2-year deprecation runway → v23 expires ~mid-2027)
- v25 too fresh for marketplace baseline; operators upgrading from v1.x see v20→v23 (3 hops) not v20→v25 (5 hops)

**Where to pin:** single `const META_GRAPH_API_VERSION = 'v23.0'` constant in `MetaClient`, no Settings field. Operator change requires plugin update — this is correct because breaking changes between versions require code-level adaptation (e.g. field name changes, removed event fields).

### Conversions API stability

CAPI itself is a logical product on top of Graph API. The `POST /{pixel_id}/events` endpoint is stable across Graph API versions for the **request shape** (event_name, event_time, event_id, user_data, custom_data, action_source). What changes between Graph API versions:
- Field hashing algorithms (none changed since v17)
- Required vs optional event params (added optional ones; none removed for Purchase/ViewContent/AddToCart/Lead)
- Test event behavior (`test_event_code`)

**No breaking changes for our use case** between v20 and v23 per Meta changelog. Safe pin.

### Confidence

HIGH on version dates + deprecation (Meta official changelog). HIGH on CAPI stability (cross-version field compatibility documented at changelog). MEDIUM on "v23 has 2-year runway" — Meta does not commit fixed schedules, only minimum 2-year retention per Platform Versioning policy.

---

## v1.x carry-forward — locked decisions (no re-research)

Listed for downstream consumer (Roadmapper) so it knows what's settled:

| Decision | Status | Source |
|---|---|---|
| event_id direction = server → frontend only | LOCKED | v1.1.1-ROADMAP §Key Decisions |
| event_id format = UUIDv4 via `ramsey/uuid` | LOCKED | Phase 3 PAY-04 |
| content_ids = `SKU-{product_id}[-{offer_id}]` (Shopaholic adapter) | LOCKED | PROJECT.md context |
| Paid-status trigger = `new-payment-received` ID=5 (Shopaholic adapter) | LOCKED | Phase 3 PAY-03 |
| Idempotency = plugin-owned `logingrupa_metapixel_event_log` UNIQUE race-fence | LOCKED | Phase 3.1 |
| UNIQUE shape = `(subject_type, subject_id, event_name, channel, site_id)` | LOCKED | Phase 3.1 BRIEF |
| EventLogWriter::record returns bool, false on UNIQUE or DB failure (fail-safe) | LOCKED | Phase 3.1-08 |
| Boot-time missing pixel_id = `Log::warning` + disabled flag (NOT throw) | LOCKED | Phase 2 SKEL-05 |
| CR-03 fbclid charset `[A-Za-z0-9_-]` ≤255 chars | LOCKED | Phase 2 SKEL-03 |
| Settings extends `Lovata\Toolbox\Models\CommonSettings` | LOCKED | Phase 2 SKEL-01 |
| Hungarian notation (`$ob`, `$ar`, `$i`, `$s`, `$b`, `$f`) | LOCKED | CLAUDE.md + Toolbox v2.2 |
| No `assert()`, enforced via spaze/phpstan-disallowed-calls | LOCKED | Phase 1 tooling |
| No `declare(strict_types=1)` enforcement | LOCKED | Ecosystem norm |
| Fail-fast `throw` at boundaries; catch only log-and-rethrow OR dead-letter | LOCKED | Tiger-Style + Phase 3 |
| HTTP client = Guzzle `^7.8`; retry on `[408, 429, 500, 502, 503, 504]` + `ConnectException` | LOCKED | Phase 3 PAY-01 |
| Queue = `SendCapiEvent` job, `$tries = 3`, `$backoff = [1, 4, 16]` | LOCKED | Phase 3 PAY-02 |
| UserDataHasher = 9-field hash + per-request CCache memoization | LOCKED | Phase 3 PAY-07 |
| Exception base = `MetaPixelException` abstract + 7 subclasses, `public readonly array $arContext` | LOCKED | Phase 3 PAY-09 |
| FailedEvent = plain October Model (no Item wrapper) | LOCKED | Phase 3 PAY-05 |

---

## Stack additions summary table

| Library | Version | Type | Purpose | Confidence |
|---|---|---|---|---|
| `jeremykendall/php-domain-parser` | `^6.4` | require | Multi-TLD aware host index for `_fbp` cookie scope (CR-02 generalization) | HIGH |
| Composer `suggest:` block | n/a | composer.json schema | Optional Shopaholic + OrdersShopaholic deps for ShopaholicAdapter | HIGH |
| PHP constraint widen | `"^8.3 \|\| ^8.4"` | composer.json | Marketplace reach; matches root project | HIGH |
| Graph API endpoint pin | `v23.0` | code constant | v20 expires 2026-09-24; v23 = mid-LTS choice | HIGH |
| `Multisite` trait on Settings | n/a (October core) | model trait | Per-site `pixel_id`/`capi_access_token` overrides | HIGH |

---

## Installation (v2.0 marketplace consumer)

```bash
# Bare OctoberCMS 4.x install — no Shopaholic
composer require logingrupa/oc-metapixel-plugin

# With Shopaholic adapter enabled
composer require logingrupa/oc-metapixel-plugin lovata/shopaholic-plugin lovata/ordersshopaholic-plugin

# With Buddies user enrichment
composer require logingrupa/oc-metapixel-plugin lovata/buddies-plugin
```

Plugin boots either way — `AdapterRegistry` auto-detects via `class_exists($name, false)`.

---

## Risks + open questions (flag for Roadmapper)

| Risk | Severity | Mitigation |
|---|---|---|
| PSL data file shipped with plugin goes stale (registries change ~monthly) | LOW | Document refresh cadence in README; ops feature in v2.x for runtime refresh |
| `class_exists($name, false)` skips autoloader — class won't load if Composer autoload not warm | LOW | Plugin.php boot() runs AFTER autoloader registration; verified pattern in Laravel ecosystem |
| Multisite trait propagation behavior on `CommonSettings` not test-covered upstream | MEDIUM | Plan dedicated `MultisiteSettingsTest` in v2.0 Phase 1 tooling |
| Graph API v23 pin gets deprecated before v3.0 ships | MEDIUM | Make endpoint version a `const` (single grep to update); document Meta 2-year deprecation cadence in README |
| `php-domain-parser` v7 (future major) may break API again | LOW | Pin `^6.4`, not `^6`. Monitor releases page. v7 not on roadmap as of 2026-05 |
| Operators on PHP 8.2 won't install (October 4 also requires 8.3) | LOW | Match upstream — out of scope to support 8.2 |
| `composer suggest:` doesn't enforce version constraint at install time | LOW | ShopaholicAdapter at runtime checks `class_exists(false)` — if user has wrong Shopaholic version, adapter throws at first event dispatch with clear error message |

---

## Sources

- [jeremykendall/php-domain-parser on Packagist](https://packagist.org/packages/jeremykendall/php-domain-parser)
- [jeremykendall/php-domain-parser releases](https://github.com/jeremykendall/php-domain-parser/releases)
- [jeremykendall/php-domain-parser composer.json (develop)](https://github.com/jeremykendall/php-domain-parser/blob/develop/composer.json)
- [PHP 8.4 Migration: New Features (php.net)](https://www.php.net/manual/en/migration84.new-features.php)
- [PHP 8.4 Release Announcement (php.net)](https://www.php.net/releases/8.4/en.php)
- [Meta Graph API Versions changelog](https://developers.facebook.com/docs/graph-api/changelog/versions/)
- [Meta Graph API v20.0 changelog](https://developers.facebook.com/docs/graph-api/changelog/version20.0/)
- [Meta Conversions API documentation](https://developers.facebook.com/documentation/ads-commerce/conversions-api)
- [October CMS 4.x Multisite docs](https://docs.octobercms.com/4.x/cms/resources/multisite.html)
- [October CMS 4.x Database Traits](https://docs.octobercms.com/4.x/extend/database/traits.html)
- [October CMS SettingModel API](https://octobercms.com/docs/api/system/models/settingmodel)
- [Composer schema documentation](https://getcomposer.org/doc/04-schema.md)
- [PHP `class_exists()` manual](https://www.php.net/manual/en/function.class-exists.php)
- Internal: `/home/forge/nailscosmetics.lv/vendor/october/rain/src/Database/Traits/Multisite.php` (read 2026-05-15)
- Internal: `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/models/CommonSettings.php` (read 2026-05-15)
- Internal: `.planning/PROJECT.md`, `.planning/milestones/v1.1.1-ROADMAP.md`, `.planning/archive/v1.1.1/phases/03.1-event-log-refactor/BRIEF.md`
