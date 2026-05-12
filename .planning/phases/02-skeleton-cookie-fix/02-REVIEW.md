---
phase: 02-skeleton-cookie-fix
reviewed: 2026-05-12T00:00:00Z
depth: standard
files_reviewed: 17
files_reviewed_list:
  - Plugin.php
  - classes/helper/PluginGuard.php
  - components/PixelHead.php
  - components/pixelhead/default.htm
  - lang/en/lang.php
  - lang/lv/lang.php
  - lang/ru/lang.php
  - middleware/EnsureFbpFbcCookies.php
  - models/Settings.php
  - models/settings/fields.yaml
  - plugin.yaml
  - tests/Feature/BootsWithoutPixelIdTest.php
  - tests/Feature/EnsureFbpFbcCookiesTest.php
  - tests/Feature/PixelHeadTest.php
  - tests/Feature/SettingsRegistrationTest.php
  - tests/MetapixelTestCase.php
  - tests/Unit/SanityTest.php
findings:
  critical: 5
  warning: 8
  info: 5
  total: 18
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-05-12
**Depth:** standard
**Files Reviewed:** 17
**Status:** issues_found

## Summary

Phase 2 (SKEL/cookie middleware) wires Plugin boot, PluginGuard singleton, EnsureFbpFbcCookies HTTP middleware, the PixelHead component, Settings model, fields YAML, three lang files, and five PHPUnit feature/unit tests. The skeleton is mostly well-structured and aligns with Lovata/Toolbox conventions, but adversarial review surfaced multiple BLOCKERS that affect correctness and the explicit threat model the PLAN promised to mitigate:

- The `ensure_fbp_fbc_server_side` Settings toggle is wired into the UI but is **never read** anywhere — the middleware unconditionally writes cookies regardless of the operator's choice. The Settings field is a placebo (BLOCKER CR-01).
- The middleware **trusts `Request::getHost()`** to compute the Meta subdomain-index without HTTP host validation, exposing the cookie-subdomain semantics to Host-header spoofing (threat T-host explicitly mentioned in this phase's scope). The PHPDoc cap is irrelevant — the underlying `count(explode('.', host)) - 1` formula breaks for multi-part TLDs (`*.co.uk`) regardless of cap (BLOCKER CR-02).
- `fbclid` query input is interpolated raw into the `_fbc` cookie value with **no length cap, no character allowlist, and no validation**. The PHPDoc claim "Symfony Cookie::create rejects overlong values via InvalidArgumentException" is **factually false** — verified empirically: Symfony accepts an 8 KiB value silently. An attacker can deliver multi-KiB cookies that propagate to CAPI envelopes downstream and bloat every subsequent request (BLOCKER CR-03).
- `PluginGuard::flush()` calls `App::forgetInstance('metapixel.disabled')`, which **only clears the resolved-instance cache** — the closure binding still references the *flushed* `PluginGuard` instance. Subsequent `App::make('metapixel.disabled')` in tests after `flush()` re-executes the orphan closure against stale state (BLOCKER CR-04).
- The Twig partial emits `arMetaEvent.event_name` and `event_time` from page-vars **directly into a `<script>` tag** without `|json_encode`/`|e('js')`. `event_name` is hardcoded in Phase 2 so it is theoretically safe, but the partial is the canonical seed for Phase 4 FUN-01 where `event_name` becomes user-configurable. The pattern as-shipped will become a stored-XSS sink the moment FUN-01 propagates external values (BLOCKER CR-05).

Significant WARNINGs include: the boot order skips middleware for `App::runningInBackend()` *as well as* `App::runningInConsole()`, meaning ANY artisan-served request or queue worker that legitimately needs HTTP context (queue dashboards via Horizon-style HTTP endpoints) will silently bypass the middleware; mass-assignment is wide open on Settings (no `$fillable`/`$guarded`); the three lang files are byte-for-byte identical (LV and RU are unlocalized); the test harness's reflection-based PluginGuard priming bypasses the very read-path the production code uses; and the Plugin's `PluginGuard::instance()` is called from `boot()` which already happens before `Settings::get()` is safe (system_settings might not be migrated yet on a fresh install).

## Critical Issues

### CR-01: `ensure_fbp_fbc_server_side` Settings toggle is dead — middleware ignores it

**File:** `middleware/EnsureFbpFbcCookies.php:70-121` (and `models/settings/fields.yaml:61-67`, `lang/en/lang.php:40-41`)
**Issue:** The Settings form, lang strings, and PHPDoc all describe `ensure_fbp_fbc_server_side` as the operator's master switch for server-side cookie injection ("When ON, the EnsureFbpFbcCookies middleware sets _fbp / _fbc when missing"). However, `EnsureFbpFbcCookies::handle()` does NOT consult this setting anywhere. The middleware unconditionally writes cookies whenever `metapixel.disabled` resolves to false. An operator who toggles the switch OFF in the backend will see ZERO behavioral change. This is a correctness defect (the UI lies) and a compliance defect (the switch is the documented kill-switch for jurisdictions where cookie consent management requires fine-grained control).

Trace evidence:
```
grep -rn "ensure_fbp_fbc_server_side" plugins/logingrupa/metapixelshopaholic/middleware/  plugins/logingrupa/metapixelshopaholic/Plugin.php plugins/logingrupa/metapixelshopaholic/classes/
# returns: (no output) — the field name does not appear anywhere outside fields.yaml and lang files
```

**Fix:**
```php
// In EnsureFbpFbcCookies::handle(), after the `metapixel.disabled` short-circuit:
if (! \Logingrupa\Metapixelshopaholic\Models\Settings::get('ensure_fbp_fbc_server_side', true)) {
    return $obResponse;
}
```
Add a 10th invariant to `EnsureFbpFbcCookiesTest`: when the setting is false, neither `_fbp` nor `_fbc` is set even with a present `?fbclid`.

---

### CR-02: Host-spoofing in subdomain-index calculation; formula breaks for multi-part TLDs

**File:** `middleware/EnsureFbpFbcCookies.php:81-84`
**Issue:** Two distinct problems compound:

(a) `Request::getHost()` returns the value of the `Host:` header (or `X-Forwarded-Host` when running behind a trusted proxy). Symfony will throw `SuspiciousOperationException` ONLY when `framework.trusted_hosts` is configured. Laravel/October default to **no trusted hosts** — meaning any client can send arbitrary `Host:` headers. The middleware does no validation: an attacker can deliver `Host: a.b.c.d.e.f.evil.example` and inflate the subdomain-index calculation. The cap (`min(SUBDOMAIN_INDEX_CAP, ...)`) clamps to 2 but does NOT validate that the host is actually one of the configured production hosts (.no/.lv/.lt). This is the precise "host spoofing in cookie subdomain index" threat the PLAN's threat model called out.

(b) Even with a trusted host, the formula `count(explode('.', getHost())) - 1` is **wrong** for any multi-part public-suffix TLD. Example: `nailscosmetics.co.uk` → 3 components → index 2. Meta's spec expects index `1` for apex domains; `2` only for `www.`-style. So a Phase 5 deploy to a `.co.uk` site (or any registry suffix like `.com.au`, `.gov.uk`) ships *wrong* `_fbp` values that Meta's reconciliation logic interprets as a wrong-subdomain match → EMQ collapses, which is the exact bug Phase 2 was meant to FIX.

**Fix:**
```php
// Option A: derive index from a configured-host allowlist (preferred).
private const HOST_INDEX_MAP = [
    'nailscosmetics.no'     => 1,
    'www.nailscosmetics.no' => 2,
    'nailscosmetics.lv'     => 1,
    'www.nailscosmetics.lv' => 2,
    'nailscosmetics.lt'     => 1,
    'www.nailscosmetics.lt' => 2,
];

$sHost = strtolower($obRequest->getHost());
if (! isset(self::HOST_INDEX_MAP[$sHost])) {
    // Untrusted host — refuse to set cookies, do not poison Meta with a guessed index.
    return $obResponse;
}
$iSubdomainIndex = self::HOST_INDEX_MAP[$sHost];
```

Option B: extract eTLD+1 via the public-suffix list (e.g., `jeremykendall/php-domain-parser`) and compute the index from the registrable-domain offset. Heavier dependency but correct for `.co.uk`/multi-region deploys. Either way: do NOT keep the raw `count(explode('.', ...))` heuristic — it is wrong by construction.

Test: add a `test_does_not_set_cookies_on_untrusted_host` case using `Host: attacker.example` and assert both cookies absent.

---

### CR-03: Unbounded fbclid pass-through into `_fbc` cookie value

**File:** `middleware/EnsureFbpFbcCookies.php:107-117`
**Issue:** The middleware reads `$obRequest->query('fbclid', '')`, casts to string, and embeds it directly into the cookie value via `sprintf('fb.%d.%d.%s', ..., $sFbclid)`. There is no:
- length cap (Meta documents fbclid as ~100 chars; nothing in the code enforces this)
- character allowlist (fbclid is `[A-Za-z0-9_-]`; nothing rejects other bytes)
- validation that the string is well-formed at all

The PHPDoc at lines 105-106 claims "T-02-11: Symfony Cookie::create rejects overlong values via InvalidArgumentException — fail-fast at the boundary is correct." This claim is **empirically false**. Verified locally:
```
$c = Cookie::create("_fbc", "fb.1.123." . str_repeat("A", 8192));
// Created OK with length 8201 — no exception
```
Symfony URL-encodes special chars but performs no length validation in `Cookie::create()`. The "fail-fast at the boundary" is a wishful comment, not a real guard. Attack surface:
1. Persistent storage bloat: 4 KiB+ cookies on every subsequent request from this client for 90 days, increasing bandwidth and PHP-FPM request-line size.
2. Downstream injection: Phase 4 FUN-01 will read the cookie back and POST the value to Meta's CAPI. An attacker who triggers `?fbclid=<crafted>` followed by an Order completion will cause the application to relay the crafted value to Meta's API.
3. Some load balancers/CDNs reject responses with cookies exceeding 4 KiB headers — production 502 risk under attack.

**Fix:**
```php
private const FBCLID_MAX_LENGTH = 255; // Meta-published fbclid max ~100; allow headroom.
private const FBCLID_ALLOWED = '/^[A-Za-z0-9_-]+$/';

$sFbclid = (string) $obRequest->query('fbclid', '');
if ($sFbclid === '' || strlen($sFbclid) > self::FBCLID_MAX_LENGTH) {
    // continue without setting _fbc; do NOT poison the cookie
} elseif (! preg_match(self::FBCLID_ALLOWED, $sFbclid)) {
    // continue without setting _fbc; reject malformed fbclid
} elseif ($obRequest->cookie(self::COOKIE_FBC) === null) {
    // ... existing setCookie block
}
```

Also delete the misleading PHPDoc comment claiming Symfony validates length, OR replace it with a TODO that explicitly says "no upstream validation — enforce locally". Per Tiger-Style: comments must describe what the code actually does.

---

### CR-04: `PluginGuard::flush()` leaves stale container closure bound to flushed `$this`

**File:** `classes/helper/PluginGuard.php:80-87, 93-98`
**Issue:** `PluginGuard::init()` binds `App::singleton('metapixel.disabled', fn (): bool => $this->isDisabled())` — the closure captures `$this` (the singleton-trait instance) by reference. `flush()` does:
```php
if (App::bound('metapixel.disabled')) {
    App::forgetInstance('metapixel.disabled');  // only clears RESOLVED cache, not BINDING
}
self::forgetInstance();   // sets static::$instance = null
```
`App::forgetInstance` in `Illuminate\Container\Container` (verified at `vendor/laravel/framework/src/Illuminate/Container/Container.php:1694-1697`) only `unset($this->instances[$abstract])` — it leaves `$this->bindings[$abstract]` intact. So after `flush()`:
- The container binding is still registered with the OLD closure.
- The OLD closure still holds a live `$this` reference to the (now-flushed-from-singleton-trait) `PluginGuard` object.
- The next `App::make('metapixel.disabled')` invokes the OLD closure, which calls `isDisabled()` on the orphan object — which still returns its memoized `bIsDisabled` value (does NOT re-prime, since `bIsDisabled !== null`).

Practical fallout: in test teardown sequences where a test calls `Settings::set('pixel_id', '...')` and then `PluginGuard::flush()` between assertions, a third assertion via `App::make('metapixel.disabled')` can return the pre-set value. The test suite currently masks this by rebinding via `App::singleton('metapixel.disabled', fn () => false)` in `EnsureFbpFbcCookiesTest::setUp()` — but production code paths that rely on `flush()` to rotate state will silently see stale values. The flush also reverses test-order dependency intuitions.

**Fix:**
```php
public static function flush(): void
{
    // Forget the BINDING, not just the resolved instance.
    // Container::forgetInstance() only unsets $instances[$abstract];
    // we need the binding closure to be re-registered by the next init().
    if (App::bound('metapixel.disabled')) {
        // Laravel offers Container::offsetUnset / forget — the public API is:
        \Illuminate\Support\Facades\App::offsetUnset('metapixel.disabled');
        // OR equivalently: app()->forgetInstance + manually unset bindings via reflection.
        // Cleanest: don't bind in init() at all; resolve lazily.
    }
    self::forgetInstance();
}
```
Better redesign: do NOT capture `$this` in the closure. Bind a fresh-resolution closure:
```php
App::singleton('metapixel.disabled', fn (): bool => PluginGuard::instance()->isDisabled());
```
Now the closure re-resolves the static `instance()` on each call (will rebuild after `forgetInstance()`).

---

### CR-05: Twig partial interpolates `event_name` raw into `<script>` — XSS sink seeded for Phase 4

**File:** `components/pixelhead/default.htm:9` and `:11`
**Issue:** Line 9:
```twig
fbq('track', '{{ arMetaEvent.event_name }}', Object.assign({event_time: {{ arMetaEvent.event_time }}}, {{ arMetaEvent.custom_data|json_encode|raw }}), {eventID: '{{ arMetaEvent.event_id }}'});
```
- `event_name` is interpolated **inside a JS single-quoted string literal**. Twig's default escaper is HTML, NOT JS — so `event_name = "'); alert(1); fbq('track', 'X"` would close the literal and execute arbitrary JS.
- `event_time` is interpolated raw as a JS numeric literal — no `|e('js')`, relies on it being an int.
- `event_id` is interpolated raw inside another JS literal — relies on UUIDv4 regex.
- `custom_data|json_encode|raw` is the only correctly-escaped slot. The `|raw` is *required* because `json_encode` already produces a safe JS literal — but the inconsistency of escaping policy is concerning: the partial author understood JS-context escaping for custom_data but did not apply it to siblings.

Phase 2 hardcodes `event_name = 'PageView'` (PixelHead.php:84), `event_time = time()` (int), `event_id = Uuid::uuid4()->toString()` (regex-safe). So at this snapshot Phase 2 is not exploitable. **However**, the PHPDoc on `PixelHead::onRun()` (lines 65-72) explicitly says "Phase 4 FUN-01 will return ... [add] event_name override + dispatch_capi switch." The moment an attacker-controllable input is wired in (URL param, model field, Order metadata), the partial becomes stored XSS.

The noscript fallback at line 11 is also vulnerable:
```twig
<img ... src="https://www.facebook.com/tr?id={{ sMetaPixelId }}&ev={{ arMetaEvent.event_name }}&noscript=1"/>
```
`{{ }}` in HTML attribute context uses Twig's HTML escaper, which does NOT URL-encode `&`/`?`/etc. An `event_name` containing `"` could break out of the attribute.

**Fix:**
```twig
{# Use Twig's JS escaper explicitly for every JS-context interpolation. #}
fbq('init', {{ sMetaPixelId|e('js')|raw }});
fbq(
    'track',
    {{ arMetaEvent.event_name|e('js')|raw }},
    Object.assign({event_time: {{ arMetaEvent.event_time|e('js')|raw }}}, {{ arMetaEvent.custom_data|json_encode|raw }}),
    {eventID: {{ arMetaEvent.event_id|e('js')|raw }}}
);
```
For the noscript `<img>` URL: use `|url_encode` on `event_name` and `sMetaPixelId`:
```twig
<img ... src="https://www.facebook.com/tr?id={{ sMetaPixelId|url_encode }}&ev={{ arMetaEvent.event_name|url_encode }}&noscript=1"/>
```
Also add a defensive assertion in `PixelHead::onRun()`: `sMetaPixelId` must match `/^\d+$/` before publishing to page vars — Pixel IDs are numeric per Meta's spec; rejecting non-numeric values fails fast per Tiger-Style and shuts the door on Settings-injection vectors.

## Warnings

### WR-01: Middleware short-circuit conflates "backend" with "console" — backend AJAX requests bypass cookie injection silently

**File:** `Plugin.php:93-95`
**Issue:** `if (App::runningInBackend() || App::runningInConsole()) { return; }` — both branches collapse to the same outcome (no middleware push). The PHPDoc rationale ("backend routes should not poison Set-Cookie headers with tracking cookies, and CLI contexts have no HTTP response") is correct, but the two cases require different handling later. Two consequences:

1. If an operator switches the storefront from "October frontend" to a SPA that consumes a `/api/...` route resolved through the backend HTTP context (not unusual), tracking silently breaks with no log line.
2. The condition is evaluated at `boot()` time, but `App::runningInBackend()` in October relies on URL detection — it may be unreliable during early boot if backend URL resolution hasn't run yet. The CONTEXT/PATTERNS notes admit this is "context-dependent." Production deploys with a non-default `BACKEND_URI` may hit edge cases.

**Fix:** Push the middleware unconditionally and let `handle()` perform the storefront/backend check based on `$obRequest->is(config('cms.backendUri').'*')`. This co-locates the routing decision with the request object and removes the boot-order coupling. Add a feature test exercising a backend-prefixed URL through the middleware and asserting no `Set-Cookie: _fbp` header on the response.

---

### WR-02: Settings model has no `$fillable` / `$guarded` — full mass-assignment exposure

**File:** `models/Settings.php` (entire file)
**Issue:** The model extends `Lovata\Toolbox\Models\CommonSettings` (which extends October's `SettingModel` → `Model`). October's `Model` defaults to NO fillable/guarded enforcement unless declared. Settings models are not directly mass-assigned by user input in the normal flow, but:
- `Settings::set([...])` (the array form, called by October's settings controller on form submit) will hydrate ANY field name posted, including invented ones not in `fields.yaml`. An attacker with backend access (or an XSS-enabled CSRF) could write arbitrary keys into `system_settings.value`.
- The `capi_access_token` is documented as a secret — but `$translatable = ['pixel_id']` only restricts translatability, not write access.

**Fix:**
```php
public $fillable = [
    'pixel_id', 'capi_access_token', 'test_event_code', 'currency_code',
    'phone_country_code', 'send_hashed_pii', 'queue_connection',
    'paid_status_code', 'refire_purchase_on_status_flip',
    'ensure_fbp_fbc_server_side',
];
```
Add a test asserting `Settings::set('arbitrary_unknown_key', 'value')` followed by `Settings::get('arbitrary_unknown_key')` returns null.

---

### WR-03: `lang/lv` and `lang/ru` are byte-for-byte identical to `lang/en` — no actual localization

**File:** `lang/lv/lang.php` and `lang/ru/lang.php` (full files)
**Issue:** All three files are character-for-character identical to `lang/en/lang.php`. The PLAN's multi-site constraint (.no/.lv/.lt) implies real translations. Shipping unlocalized LV/RU files defeats RainLab.Translate's per-locale lookups and confuses backend admins who switch UI language and see English. This is also a quality-gate failure: `tests/Feature/SettingsRegistrationTest::test_fields_yaml_binds_lang_keys_per_field` verifies the keys but not that values are translated.

**Fix:** Either (a) produce actual Latvian and Russian translations, OR (b) delete `lang/lv/` and `lang/ru/` so October's fallback chain serves English explicitly (better than masquerading as a translation that exists). Document the choice in `02-SUMMARY.md`.

---

### WR-04: Test reflection priming bypasses the production Settings → PluginGuard read path

**File:** `tests/Feature/PixelHeadTest.php:197-231`
**Issue:** `primePluginGuardEnabled()` and `primePluginGuardDisabled()` use `\ReflectionProperty` to inject `bIsDisabled` and `sPixelId` directly. The PHPDoc admits this sidesteps "the fragile `Settings::set() → Settings::clearInternalCache() → Settings::get()` round-trip" (HR-02). Problem: every `PixelHeadTest` is now a unit test of `PixelHead` against a mock-style guard, NOT the integration test the project's Tiger-Style rules require ("integration > unit. Hit real DB. No mocking business logic"). Concretely: `prime()` itself — the function that maps "Settings returns empty" to "disabled = true" — is never exercised end-to-end against `Settings::get()` in `PixelHeadTest`. If `prime()` regresses (e.g., changes the empty-check semantics), these tests still pass.

Worse, the comment "the reflection priming is test-double for the upstream Settings read only" is a rationalization. If the hermetic SQLite harness fails to round-trip Settings reliably, the test harness is the bug — not something to route around with reflection.

**Fix:** Fix the underlying harness (HR-02). One known mitigation: October's `SettingModel` uses `Cache::remember()` with a per-instance cache key. After `Settings::set()`, both `Cache::flush()` AND `Settings::clearInternalCache()` are needed, in that order, and the Site singleton must be reset (`Site::flushCache()` if such method exists in this October version). The `BootsWithoutPixelIdTest` succeeds with this combination — see lines 39-42, 47-49. Apply the same pattern in `PixelHeadTest` and delete the reflection helpers. If a true blocker remains, log it as a known limitation in PHASE state, NOT in the test code.

---

### WR-05: `PluginGuard::prime()` catches `\Throwable` but `Log::warning` itself can throw (recursion risk during boot)

**File:** `classes/helper/PluginGuard.php:118-131`
**Issue:** The boundary catch is correctly scoped and logged. But if `Log::warning` itself throws (e.g., during early boot when the logging driver isn't configured, when the storage/logs directory is non-writable, or when the configured driver is `daily` and the disk is full), the exception propagates up through `prime()` → `init()` → `PluginGuard::instance()` → `Plugin::boot()`. The whole point of the boundary catch is to prevent boot failure — this defeats it. Tiger-Style says fail fast in business logic; boundary code should fail safe.

**Fix:** Wrap the `Log::warning` call in its own try/catch:
```php
} catch (\Throwable $obException) {
    try {
        Log::warning('Metapixel: pixel_id read failed', ['exception' => $obException->getMessage()]);
    } catch (\Throwable) {
        // Silent: logging must never break boot. SKEL-05.
    }
    $this->bIsDisabled = true;
    return;
}
```

---

### WR-06: `Settings::getPaidStatusCodeOptions()` returns mixed-typed keys via `(array) Status::lists()`

**File:** `models/Settings.php:52-64`
**Issue:** `Status::lists('name', 'code')` returns an October `Collection`. The cast `(array)` on an `Eloquent\Collection` does not produce a plain `code => name` array as the PHPDoc implies — it dumps the Collection's internal `items` array, which in `lists()`'s implementation already is `code => name`. So today this happens to work. But the cast is fragile: future October versions may change `Collection::__toArray()`/cast semantics, breaking the dropdown without a clear error.

Also: when `Status::lists()` returns an `array` directly (some October versions), iterating with `$mCode => $mName` and then casting `(string) $mCode` will silently coerce an integer-like code (e.g., "5") differently than a string code. A status with `code = '5'` will collide with a status whose `code = 5`.

**Fix:**
```php
$obList = Status::orderBy('sort_order')->get();
$arResult = [];
foreach ($obList as $obStatus) {
    if (! is_scalar($obStatus->code) || ! is_scalar($obStatus->name)) {
        continue;
    }
    $arResult[(string) $obStatus->code] = (string) $obStatus->name;
}
return $arResult;
```
Explicit iteration over the Eloquent collection is type-stable and avoids `lists()` historical inconsistencies. Add a test asserting sort-order preservation.

---

### WR-07: `PixelHead::onRun()` lacks an explicit return type

**File:** `components/PixelHead.php:73-88`
**Issue:** PHPDoc says `@return void|Response`, but the method has no PHP-native return type. Tiger-Style explicitly requires "Explicit types, explicit returns. No hidden magic. Declare `: void`, `: array`, `: ProductItem`, etc." The justification ("Phase 4 FUN-01 may return a Response") is forward-looking; Phase 2 returns nothing.

**Fix:** Either declare `: void` now and change to `: ?Response` in Phase 4 with a single 1-line refactor, OR declare `: ?Response` now and explicitly `return null;` in the disabled branch. Both are better than no declaration. The current state is the only path Tiger-Style forbids.

---

### WR-08: `EnsureFbpFbcCookies` constructs `$iCreationTimeMs = time() * 1000` — int overflow risk on 32-bit and not actually ms-precise

**File:** `middleware/EnsureFbpFbcCookies.php:85`
**Issue:** `time() * 1000` returns an int; on 32-bit PHP (still possible on some legacy LXC containers) `time()` is already near `2^31` and multiplication overflows in 2038 — NOT 2038 actually, but `2^31 / 1000 ≈ 2.1M seconds ≈ ~1970 + 25 days`. Wait, no: 32-bit signed int max is 2147483647. `time() ≈ 1747000000 today; * 1000 = 1.747e12` which is well above 32-bit signed int max (2.147e9). So on 32-bit PHP this overflows TODAY. The codebase pins PHP 8.4 NTS (64-bit confirmed), so this is theoretical, not actively broken. The PHPDoc admission "Meta accepts seconds-times-1000 even though the field name says ms" hand-waves the precision issue.

Also, multiple cookies created in the same `handle()` call share the same `$iCreationTimeMs` — that's actually fine and desired (cookies are co-created), but the variable name is misleading because it's not really ms-precision (always `*000` suffix).

**Fix:**
```php
// Use hrtime() for true microsecond precision, or just be honest:
$iCreationTimeMs = (int) (microtime(true) * 1000);
```
This yields actual millisecond precision matching Meta's spec literally. The cookie value is then `fb.{idx}.{13-digit-ms}.{rand}` — and the test regex `\d{13}` will still pass for any time after 2001. Add a comment confirming 64-bit PHP requirement in `composer.json` (`"php": ">=8.3"` already implies 64-bit on supported platforms, but documenting is cheap).

## Info

### IN-01: Hungarian notation slip — `$arPageVars`, `$obStub` are good; `$sUrl`, `$arCookies`, `$bHttps` mostly good; but a few violations

**File:** `tests/Feature/EnsureFbpFbcCookiesTest.php:201-210` and elsewhere
**Issue:** Phase 2's compliance with the project's Hungarian notation is strong overall, but a few spots slip: `(new Plugin($this->app))` (Plugin.php tests — should be `$obPlugin`), `$arRaw` and `$arResult` (good) vs `$mCode => $mName` (the `m` prefix is not in the CLAUDE.md table — should probably be split: `$mCode` is an integer-or-string, but only `$s`/`$i` are listed). Also `$reflect`, `$reflectClass`, `$class`, `$path`, `$result`, `$pluginPath` in `MetapixelTestCase::guessPluginCodeFromTest()` and `flushModelEventListeners()` are non-Hungarian — these are unchanged copies from October's PluginTestCase, but Tiger-Style + the CLAUDE.md table apply project-wide.

**Fix:** Add a TODO/Pint-config rule to enforce Hungarian for new code in `MetapixelTestCase`; either rename the copied October methods or document them as legacy-imports.

---

### IN-02: Lang files contain non-translatable comma-quoted apostrophes (`theme\'s`) — fine in PHP but mismatches Twig escaping context

**File:** `lang/{en,lv,ru}/lang.php:14`
**Issue:** `'Renders fbq() init + PageView with server-generated event_id. Coexists with the theme\'s facebook_pixel.htm partial.'` — the escaped apostrophe survives `__()`. Rendered through Twig with default HTML escaping it appears as `theme&#039;s` which is correct but ugly. Use a Unicode right-single-quote (`’`) for typographic correctness in user-facing copy.

**Fix:** Replace `theme\'s` with `theme’s` in all three lang files (after producing real LV/RU translations per WR-03).

---

### IN-03: `MetapixelTestCase::guessPluginCodeFromTest()` and `isAppCodeFromTest()` are dead code

**File:** `tests/MetapixelTestCase.php:252-271`
**Issue:** These two methods exist verbatim from October's upstream `PluginTestCase`. `isAppCodeFromTest()` returns `false` unconditionally. `guessPluginCodeFromTest()` is only used by the upstream class itself, not by any code in this project (no call site found in this plugin). They survive as fossils from a copy-paste base.

**Fix:** Either delete them, or add a PHPDoc note "Retained from October PluginTestCase for parity with PerformsRegistrations/PerformsMigrations expectations." Verify with `grep -rn "guessPluginCodeFromTest\|isAppCodeFromTest" plugins/logingrupa/metapixelshopaholic/` — if zero callers, delete.

---

### IN-04: PHPDoc references "modules/cms/classes/CodeBase.php:76-103" but offsets may have drifted

**File:** `tests/Feature/PixelHeadTest.php:30-32, 236-238`
**Issue:** The PHPDoc cites specific upstream line ranges as evidence ("ArrayAccess contract — see modules/cms/classes/CodeBase.php:76-103"). These line numbers will drift as October patches CMS module; the comment will become stale within months and confuse future readers.

**Fix:** Cite the method name only: "see `Cms\Classes\CodeBase::offsetSet/offsetGet/offsetExists/offsetUnset`". Method names are stable; line numbers are not.

---

### IN-05: PHPUnit autoloader includes `require_once __DIR__.'/../MetapixelTestCase.php';` at top of each feature test

**File:** All four files in `tests/Feature/` (lines 5)
**Issue:** The `require_once` statements bypass PHPUnit's PSR-4 autoloader. The PHPDoc on `SanityTest:18` admits the rationale (Pest's `uses(...)->in('Unit')` is flaky). However, if the `composer.json` autoload section already maps `Logingrupa\Metapixelshopaholic\Tests\\` → `tests/`, the `require_once` is redundant and gets in the way of static analysis. Worse, if the test is moved or the path changes, all four files need updating in lockstep.

**Fix:** Verify `composer.json` autoload-dev maps test namespaces. If yes, delete the four `require_once` lines and rely on Composer autoload. If autoload-dev is missing, add it and then delete the requires.

---

_Reviewed: 2026-05-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
