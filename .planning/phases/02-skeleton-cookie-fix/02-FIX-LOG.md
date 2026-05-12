---
phase: 02-skeleton-cookie-fix
fixed_at: 2026-05-12T17:30:00Z
review_path: .planning/phases/02-skeleton-cookie-fix/02-REVIEW.md
iteration: 1
findings_in_scope: 13
fixed: 8
skipped: 5
status: partial
qa_exit_code: 0
qa_chain: pint-test + phpstan analyse level 10 + phpmd + pest test-cov
tests_total: 30
tests_passing: 30
coverage_total_pct: 87.0
total_commits: 9
---

# Phase 2: Code Review Fix Report

**Fixed at:** 2026-05-12T17:30:00Z
**Source review:** `.planning/phases/02-skeleton-cookie-fix/02-REVIEW.md`
**Iteration:** 1
**Scope:** Critical (BLOCKER) + Warning severity (per `/gsd-code-review --fix --auto` invocation; Info skipped)

## Summary

- Findings in scope: 13 (5 Critical + 8 Warning)
- Fixed: 8 (5 Critical + 3 Warning)
- Skipped: 5 (deliberate / intentionally deferred / not applicable)
- Total commits: 9 (8 fix commits + 1 qa-green chore commit)
- Final `composer qa` exit code: 0
- Tests: 30 passing, 95 assertions, 87.0% line coverage

All five Critical blockers are fixed. Three Warnings are fixed; five Warnings are deliberately skipped per the user-supplied per-blocker guidance (intentional design decisions, deferred-to-Phase-5 work, or already-addressed in the blocker fixes).

## Fixed Issues

### CR-01: `ensure_fbp_fbc_server_side` Settings toggle is dead — middleware ignores it

**Files modified:** `middleware/EnsureFbpFbcCookies.php`, `tests/Feature/EnsureFbpFbcCookiesTest.php`
**Commit:** `a8bfe82` (combined with CR-02 + CR-03 — same `handle()` rewrite)
**Applied fix:** Added `Settings::get('ensure_fbp_fbc_server_side', true)` lookup with a `Throwable` boundary catch matching SKEL-05. When the toggle is OFF the middleware no-ops (returns the original response untouched). Added new test `test_short_circuits_when_settings_toggle_off` locking the negative path.

### CR-02: Host-spoofing in subdomain-index calculation; formula breaks for multi-part TLDs

**Files modified:** `middleware/EnsureFbpFbcCookies.php`, `tests/Feature/EnsureFbpFbcCookiesTest.php`
**Commit:** `a8bfe82`
**Applied fix:** Replaced `count(explode('.', host)) - 1` with a `HOST_INDEX_MAP` allowlist containing the six production hosts (apex + www across .no/.lv/.lt). Untrusted hosts (anything not in the map) short-circuit the middleware — no cookies set. Rewrote the legacy `test_caps_subdomain_index_at_2_for_deep_subdomains` as `test_does_not_set_cookies_on_untrusted_host` reflecting the new allowlist semantics. PHPDoc documents the multi-part-TLD limitation and the Phase 5 deploy path for `.co.uk`-style suffixes.

### CR-03: Unbounded fbclid pass-through into `_fbc` cookie value

**Files modified:** `middleware/EnsureFbpFbcCookies.php`, `tests/Feature/EnsureFbpFbcCookiesTest.php`
**Commit:** `a8bfe82`
**Applied fix:** Added `FBCLID_MAX_LENGTH = 255` and `FBCLID_ALLOWED_PATTERN = /^[A-Za-z0-9_-]+$/` constants. The `_fbc` set path now requires the fbclid value to pass both gates; failures continue without setting the cookie. Deleted the misleading PHPDoc claim about Symfony's `Cookie::create` rejecting overlong values. Added `test_rejects_overlong_fbclid` and `test_rejects_malformed_fbclid_charset`.

### CR-04: `PluginGuard::flush()` leaves stale container closure bound to flushed `$this`

**Files modified:** `classes/helper/PluginGuard.php`
**Commit:** `77c7a06`
**Applied fix:** Two changes, both required:
- `flush()` now calls `App::offsetUnset('metapixel.disabled')` instead of `App::forgetInstance(...)`. `offsetUnset` is the public Container API that removes the binding closure itself (verified in `Illuminate\Container\Container::offsetUnset` — does `unset($this->bindings[$key])`).
- `init()`'s closure now does `self::instance()->isDisabled()` rather than `$this->isDisabled()`. The closure no longer captures `$this`; it re-resolves the Singleton-trait instance on every container lookup. After `flush()` rebuilds the static instance, subsequent `App::make('metapixel.disabled')` calls always read the fresh PluginGuard.

### CR-05: Twig partial interpolates `event_name` raw into `<script>` — XSS sink seeded for Phase 4

**Files modified:** `components/pixelhead/default.htm`, `components/PixelHead.php`
**Commit:** `6aa9c24`
**Applied fix:** Every JS-context interpolation in the `<script>` block now uses Twig's `|e('js')` escaper (`sMetaPixelId`, `event_name`, `event_time`, `event_id`). The `<noscript><img>` URL parameters use `|url_encode`. `custom_data|json_encode|raw` was already correct and preserved. Added a PHPDoc block in the template explaining the Phase 4 lock so future maintainers do not loosen the escapers. Defense-in-depth: `PixelHead::onRun()` now rejects non-numeric `pixel_id` values with `preg_match('/^\d+$/', ...)` before publishing to page vars.

### WR-01: Middleware short-circuit conflates "backend" with "console" — backend AJAX bypasses cookie injection silently

**Files modified:** `Plugin.php`, `middleware/EnsureFbpFbcCookies.php`, `tests/Feature/EnsureFbpFbcCookiesTest.php`
**Commit:** `e37975e`
**Applied fix:** Moved the backend-vs-storefront discrimination from `Plugin::boot()` into the middleware's `handle()` method itself. The new check reads `config('cms.backendUri')` and matches against the resolved request path — more reliable than `App::runningInBackend()` at boot time (which depends on URL detection that may not have completed). `Plugin::boot()` now only short-circuits on `App::runningInConsole()` (CLI has no HTTP response). Added `test_short_circuits_on_backend_url` locking the path-based check.

### WR-02: Settings model has no `$fillable` / `$guarded` — full mass-assignment exposure

**Files modified:** `models/Settings.php`
**Commit:** `c09c77b`
**Applied fix:** Added explicit `$fillable` allowlist with the 10 SKEL-02 field keys. Prevents October's array-form `Settings::set([...])` from hydrating arbitrary keys (XSS-enabled CSRF defense). PHPDoc type later refined to `list<string>` (in the qa-green commit) for covariance with `Eloquent\Model::$fillable`.

### WR-05: `PluginGuard::prime()` catches `\Throwable` but `Log::warning` itself can throw

**Files modified:** `classes/helper/PluginGuard.php`
**Commit:** `1f35235`
**Applied fix:** Both `Log::warning` call sites in `prime()` (the boundary-catch branch AND the empty-pixel_id branch) now wrap the call in nested `try { ... } catch (\Throwable) { /* silent */ }` blocks with explicit comments stating "logging must never break boot. SKEL-05." Matches the Tiger-Style "silent catch with explicit reason" allowance for boundary code.

### WR-06: `Settings::getPaidStatusCodeOptions()` returns mixed-typed keys via `(array) Status::lists()`

**Files modified:** `models/Settings.php`
**Commit:** `9a4ac74`
**Applied fix:** Replaced the brittle `(array) Status::lists('name', 'code')` cast with explicit iteration: `Status::orderBy('sort_order')->get()` returns a stable Eloquent collection; iterate each model and build the result array via `getAttribute('code') => getAttribute('name')` with `is_scalar` guards. Deterministic result ordering (sort_order asc) and immune to October version drift in the `lists()` return shape. The qa-green commit switched from dynamic property access to `getAttribute()` for phpstan level-10 compliance with the upstream Status model (which lacks @property docblocks).

### WR-08: `time() * 1000` semantics

**Files modified:** `middleware/EnsureFbpFbcCookies.php`
**Commit:** `a8bfe82` (folded into the CR-02 rewrite, same code line)
**Applied fix:** Changed `$iCreationTimeMs = time() * 1000` to `$iCreationTimeMs = (int) (microtime(true) * 1000)`. Yields true millisecond precision matching Meta's field name. 64-bit PHP only (composer.json `"php": "^8.4"` already enforces this — overflow impossible until year ~292 million).

## Skipped Issues

### WR-03: `lang/lv` and `lang/ru` are byte-for-byte identical to `lang/en`

**File:** `lang/lv/lang.php`, `lang/ru/lang.php`
**Reason:** Intentional per CONTEXT Area 4 Q4. Stubs deferred to Phase 5 HARD-04 (full localization is the documented Phase 5 deliverable). Removing the files would block RainLab.Translate's `|_` filter from resolving for the LV and RU locales. Documented in the user's per-blocker guidance.
**Original issue:** All three lang files are character-for-character identical — no actual localization. The PLAN's multi-site constraint implies real translations.

### WR-04: Test reflection priming bypasses the production Settings → PluginGuard read path

**File:** `tests/Feature/PixelHeadTest.php:197-231`
**Reason:** Per the user's per-blocker guidance: "note as documentation comment only". The existing PHPDoc on `primePluginGuardEnabled()` and `primePluginGuardDisabled()` already documents the HR-02 hermetic-harness limitation extensively (8 lines of context). Replacing the reflection helpers would require fixing the underlying SQLite + Multisite + Cache::remember interaction — a Phase 5 HR-02 task that is out of scope here.
**Original issue:** The reflection-based priming bypasses the very read-path the production code uses.

### WR-07: `PixelHead::onRun()` lacks an explicit return type

**File:** `components/PixelHead.php:73`
**Reason:** Per the user's per-blocker guidance: "DO NOT ADD (intentionally omitted per plan-checker iteration 1 decision; preserves Phase 4 Response escape hatch)". The existing PHPDoc `@return void|Response` documents the intent. Phase 4 FUN-01 will return an `Illuminate\Http\Response` from this method when CAPI dispatch needs to short-circuit page rendering.
**Original issue:** PHPDoc says `@return void|Response`, but the method has no PHP-native return type.

### IN-01 through IN-05 (entire Info severity)

**Reason:** Out of scope per the user's `--auto` invocation: "Scope: All Critical (BLOCKER) and Warning severity findings. Skip Info-only findings." Five Info-level findings (Hungarian-notation slips in test fixtures, apostrophe typography, dead `guessPluginCodeFromTest`/`isAppCodeFromTest` methods, drifted line-number citations in PHPDocs, redundant `require_once` lines in test files) are not addressed in iteration 1.

## QA Verification

Final `composer qa` exit code: **0**

Tool chain results:
- **pint-test:** passed (no style violations)
- **phpstan analyse (level 10):** OK, no errors across all 5 paths
- **phpmd:** clean (all custom rules pass; cyclomatic ≤ 10, NPath ≤ 200, LongVariable ≤ 40)
- **pest test-cov:** 30 tests passed, 95 assertions, 87.0% total line coverage

Per-file coverage:
- `Plugin.php` — 52.0% (untested branches: HttpKernel::pushMiddleware push under the runningInConsole=true branch in CI; not a Phase 2 invariant lock)
- `classes/helper/PluginGuard.php` — 93.5%
- `components/PixelHead.php` — 94.4%
- `middleware/EnsureFbpFbcCookies.php` — 96.1%
- `models/Settings.php` — 92.9%

## Commits in this iteration

```
a8bfe82  fix(02-skeleton): CR-01/CR-02/CR-03 — middleware honors Settings toggle + host allowlist + fbclid validation
77c7a06  fix(02-skeleton): CR-04 — PluginGuard flush() unsets binding, init() avoids $this capture
6aa9c24  fix(02-skeleton): CR-05 — JS-context escaping in Twig partial + numeric pixel_id guard
c09c77b  fix(02-skeleton): WR-02 — Settings $fillable allowlist for 10 SKEL-02 fields
1f35235  fix(02-skeleton): WR-05 — wrap Log::warning in its own try/catch (no boot cascade)
9a4ac74  fix(02-skeleton): WR-06 — explicit Status iteration in getPaidStatusCodeOptions
e37975e  fix(02-skeleton): WR-01 — move backend/storefront gate into middleware (path-based)
fa160d1  chore(02-skeleton): pint normalize HOST_INDEX_MAP alignment
da5d591  chore(02-skeleton): qa green — phpstan level 10 + phpmd thresholds + test isolation
```

---

_Fixed: 2026-05-12T17:30:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
