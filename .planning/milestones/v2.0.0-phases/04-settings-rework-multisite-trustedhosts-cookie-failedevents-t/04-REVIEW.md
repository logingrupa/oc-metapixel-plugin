---
phase: 04-settings-rework-multisite-trustedhosts-cookie-failedevents-t
reviewed: 2026-05-20T00:00:00Z
depth: standard
files_reviewed: 38
files_reviewed_list:
  - classes/helper/HostIndexResolver.php
  - classes/meta/MetaClient.php
  - composer.json
  - console/RefreshPsl.php
  - controllers/failedevents/config_list.yaml
  - controllers/failedevents/index.htm
  - controllers/failedevents/_list_toolbar.htm
  - controllers/FailedEvents.php
  - lang/en/lang.php
  - lang/lv/lang.php
  - middleware/EnsureFbpFbcCookies.php
  - models/failedevent/columns.yaml
  - models/failedevent/_graph_error.htm
  - models/FailedEvent.php
  - models/settings/fields.yaml
  - models/Settings.php
  - phpstan.neon
  - phpunit.xml
  - Plugin.php
  - tests/Feature/Console/RefreshPslTest.php
  - tests/Feature/Controllers/FailedEventsCheckDedupTest.php
  - tests/Feature/Controllers/FailedEventsListTest.php
  - tests/Feature/Controllers/FailedEventsReplayTest.php
  - tests/Feature/Lang/LangKeyCoverageTest.php
  - tests/Feature/Middleware/EnsureFbpFbcCookiesTest.php
  - tests/Feature/Migrations/AddDedupColumnsToFailedEventsTest.php
  - tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php
  - tests/Feature/Models/FailedEventModelTest.php
  - tests/Feature/MultisiteEventLogRoutingTest.php
  - tests/Feature/Settings/SettingsLookupForSiteTest.php
  - tests/Feature/Settings/TrustedHostsValidationTest.php
  - tests/fixtures/sites.php
  - tests/MetapixelTestCase.php
  - tests/Unit/Helper/HostIndexResolverTest.php
  - tests/Unit/Models/SettingsBeforeSaveTest.php
  - tests/Unit/Models/SettingsMultisiteTraitTest.php
  - updates/AddDedupColumnsToFailedEvents.php
  - updates/AddMultisitePixelIdAndToken.php
  - updates/version.yaml
findings:
  critical: 2
  warning: 7
  info: 6
  total: 15
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-05-20T00:00:00Z
**Depth:** standard
**Files Reviewed:** 38
**Status:** issues_found

## Summary

Phase 4 ships Multisite-aware Settings (`lookupForSite` + `$propagatable = []`), a PSL-aware `HostIndexResolver`, the `EnsureFbpFbcCookies` HTTP middleware, the read-only FailedEvents backend audit UI with Replay / CheckDedup / Delete batch handlers, and bilingual EN/LV translations. The work is generally well-structured: adapter contract preserved, Tiger-Style fail-fast at the right boundaries, fail-safe NO-OPs in the request-time middleware, hermetic SQLite test base, and reasonable test coverage for the new surfaces.

However, the review surfaces **two BLOCKER-class security defects** that contradict the plugin's own stated policy and locked decisions:

1. `MetaClient::fetchTestEventsStatus` places `access_token` in the URL query string — the class docblock explicitly forbids this ("Token is sent in the POST body, never the URL query string — Meta accepts both but webserver access logs leak the URL") yet the new CheckDedup-supporting method does it anyway, exposing the CAPI access token to webserver access logs, proxy logs, and HTTPS-decrypting load balancers.
2. The hardcoded URL token leak in (1) is reachable from any backend operator with FailedEvents access by clicking the CheckDedup toolbar button.

Additional WARNING-class findings cover incorrect `http_status` updates on the failure path of `replayOne`, an unused `use Backend;` import, dead lang keys for replay/dedup flashes, an `App::make` short-circuit in the middleware that will always evaluate truthy when bound (boolean check is structurally correct only because the binding currently exists as a closure that hasn't been wired in this phase), `Settings::lookupForSite` ignoring the `?int $iSiteId = null` callsite on CheckDedup (D-01-acknowledged but worth flagging for marketplace ops), and several test-hermeticity issues (`Mockery::mock('alias:...')` without `runInSeparateProcess` will leak the alias class into subsequent tests in the same PHP process).

No PHPStan-level-10 `@phpstan-ignore` smell was observed — all narrowing is done via runtime `is_string` / `is_array` / `is_int` guards as the convention prescribes.

## Critical Issues

### CR-01: CAPI access token leaked in URL query string on Dataset Quality fetch

**File:** `classes/meta/MetaClient.php:117-132`
**Issue:** `fetchTestEventsStatus` builds a GET URL containing `&access_token=%s` (line 127) with `rawurlencode($sToken)` interpolated into the path. The class-level docblock at lines 22-23 explicitly states:

> "Token is sent in the POST body, never the URL query string — Meta accepts both but webserver access logs leak the URL."

The newly added CheckDedup-supporting method violates the very policy the class is documented to enforce. This is reachable from production: any backend operator with `failedevents` URL access clicks the toolbar's `Check dedup` button → `Controllers\FailedEvents::onCheckDedupBatch` → `MetaClient::fetchTestEventsStatus` → token in the URL. The token now lands in:

- Nginx/Apache access logs on the host running the plugin.
- Any forward proxy / corporate egress filter in the operator's network path.
- Any HTTPS-decrypting load balancer or WAF before the egress to graph.facebook.com.
- `php-fpm` slow-query logs if the Guzzle timeout (5 s default) is hit.

Meta CAPI access tokens are long-lived and grant write access to the operator's pixel — this is a credential disclosure to the operator's own infrastructure, not Meta's. (`pinned to v23.0` in the same file at line 27 — these tokens do not auto-rotate.)

**Fix:** Move the token into the request body or a header per the docblock policy. Graph API supports `access_token` as a POST field on the GET endpoint via the Facebook SDK convention, OR as `Authorization: Bearer <token>` header (Graph v17+):

```php
// Option A — POST body (matches the existing sendForPixel pattern):
$sUrl = sprintf(
    '%s/%s/%s/?fields=name,event_match_quality,deduplication_rate',
    self::META_GRAPH_API_BASE,
    self::META_GRAPH_API_VERSION,
    $sPixelId,
);
$obResponse = $obClient->request('GET', $sUrl, [
    'http_errors' => false,
    'form_params' => ['access_token' => $sToken],
]);

// Option B — Authorization header (preferred for read endpoints):
$obResponse = $obClient->request('GET', $sUrl, [
    'http_errors' => false,
    'headers' => ['Authorization' => 'Bearer '.$sToken],
]);
```

Also: drop the `rawurlencode($sToken)` callsite — once the token is no longer in the URL, no encoding is needed; the body or header transport handles encoding itself.

### CR-02: `replayOne` permanent-failure path does NOT reset `http_status`, but success path overwrites it to a fabricated `200`

**File:** `controllers/FailedEvents.php:181-203`
**Issue:** Two related correctness defects on the same code path:

1. **Success branch fabricates `http_status = 200`** (line 184). `sendForPixel` returns the decoded body, NOT the response status. The Replay path never inspects `$obResponse->getStatusCode()` — it just assumes any non-throwing return is `200`. If Graph API returns a 2xx that is not `200` (e.g. `201 Created`, which Graph occasionally uses on async-accepted events), the row is now mislabelled. More importantly, the docblock claims "on success ... http_status 200" but the production payload from Meta is dropped on the floor; the operator can never see the actual successful HTTP status.
2. **`MetaPixelException` and `Throwable` catch branches do NOT update `http_status`** (lines 190-201). When a row was failed with `http_status = 400`, the operator hits Replay, the API returns `400` again with a new graph error, attempts increments but `http_status` is stale from the first failure — masking transient → permanent classification changes. If the failure flips from a transient `502` to a permanent `400`, the row STILL shows `502`.

The end result is a row whose `http_status` column lies about the actual outcome of the most recent replay attempt. This is silent state corruption — the audit UI exists precisely so operators can trust the columns.

**Fix:** Capture the actual response from `sendForPixel` and propagate status from both success and exception paths. The simplest fix is to expose the real status — extend `MetaPixelException` to carry the upstream HTTP status (Phase 3's `MetaApiTransientException` already does this via `getHttpStatus()`):

```php
try {
    $obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $arPayload);
    $obRow->update([
        'attempts' => $obRow->attempts + 1,
        'graph_error' => null,
        'http_status' => null,  // unset stale status — successful row no longer carries last failure code
    ]);
    Flash::success(/* ... */);
} catch (MetaPixelException $obException) {
    $iStatus = method_exists($obException, 'getHttpStatus') ? $obException->getHttpStatus() : null;
    $obRow->update([
        'attempts' => $obRow->attempts + 1,
        'graph_error' => $obException->getMessage(),
        'http_status' => $iStatus,  // reflect the actual code from THIS replay attempt
    ]);
    Flash::error(/* ... */);
} catch (Throwable $obException) {
    $obRow->update([
        'attempts' => $obRow->attempts + 1,
        'graph_error' => $obException->getMessage(),
        'http_status' => null,  // non-HTTP failure (timeout, parser) — explicitly clear
    ]);
    Flash::error(/* ... */);
}
```

Update `FailedEventsReplayTest::test_on_replay_success_increments_attempts_and_clears_graph_error` accordingly — the current test asserts `http_status === 200` which masks this bug (the assertion passes because the production code fabricates the value, not because Meta actually returned 200).

## Warnings

### WR-01: Unused `use Backend;` import in FailedEvents controller

**File:** `controllers/FailedEvents.php:5`
**Issue:** `use Backend;` is imported but never referenced anywhere in the file. The only `Backend\` usage is `Backend\Classes\Controller` (line 6, separate import) and `BackendMenu` (line 7, also separate). `Plugin.php` uses `Backend::url(...)` but `controllers/FailedEvents.php` does not. PHPMD would flag this as `UnusedFormalImport`; CLAUDE.md "no dead code" rule explicitly bans this.

**Fix:** Remove line 5.

### WR-02: Defined lang keys for replay/dedup flashes are dead — controller emits hardcoded English

**File:** `controllers/FailedEvents.php:137, 160, 186, 194, 202, 237, 253`
**Issue:** `lang/en/lang.php` and `lang/lv/lang.php` both define:
- `failed_events.flash_replay_success` → "Replay succeeded — event_id :event_id"
- `failed_events.flash_replay_error` → "Replay failed — :error"
- `failed_events.flash_dedup_success` → "Dedup status updated for :count events"

But the controller emits hardcoded `'metapixel: replay succeeded — event_id '.$obRow->event_id` etc., bypassing the translation pipeline entirely. Result on a Latvian backend: operator sees English error strings while the rest of the UI is translated. The Russian-style hard-coded prefix `metapixel:` also disagrees with the i18n key shape. The LV test `test_lv_strings_are_not_blank` enforces the lang values are populated, so the dead keys still ship as live translation surface despite never being consumed.

**Fix:** Use `Lang::get` / `trans` (or rebuild the messages through `:event_id` / `:error` / `:count` placeholders the lang file already declares):

```php
Flash::success(trans(
    'logingrupa.metapixel::lang.failed_events.flash_replay_success',
    ['event_id' => (string) $obRow->event_id],
));
```

Apply to all six `Flash::*` callsites in the controller. Alternative if you intend to ship English-only operator-internal messages: delete the unused `flash_*` keys from both lang files and update `LangKeyCoverageTest::test_en_lang_has_at_least_50_keys` accordingly.

### WR-03: Middleware `App::make('metapixel.disabled')` short-circuit is dead in Phase 4 — no binding wired

**File:** `middleware/EnsureFbpFbcCookies.php:100`
**Issue:** The line `if (App::bound('metapixel.disabled') && App::make('metapixel.disabled'))` checks for a container binding that **is never created anywhere in the v2.0 codebase**. Grep across `plugins/logingrupa/metapixel/` for `App::singleton('metapixel.disabled'` returns only archived v1.x phase notes (`.planning/archive/v1.1.1/...`). `PluginGuard::isDisabled()` exists but is wired statically; it never binds the container key. The branch is therefore unreachable.

This is more than dead code — the docblock at line 88 ("PluginGuard-disabled plugins skip") **promises** behaviour that the implementation does not deliver. An operator with an empty `pixel_id` will (per the docblock) expect the middleware to NO-OP; the actual code path still proceeds to `readTrustedHosts` and may set cookies on an effectively-disabled plugin.

**Fix:** Pick one:
1. Wire the binding (`App::singleton('metapixel.disabled', fn () => PluginGuard::isDisabled())` in `Plugin::register()`) and add an assertion test.
2. Replace the dead `App::bound` check with the static call: `if (PluginGuard::isDisabled()) { return true; }` (preferred; one less indirection).
3. Drop the disabled-check entirely with a docblock update.

### WR-04: `findOrFail` on single-row Replay/CheckDedup throws bare `ModelNotFoundException` — backend AJAX layer returns 500, not a graceful error flash

**File:** `controllers/FailedEvents.php:343, 57-59, 92-94`
**Issue:** `findRowOrFail` calls `FailedEvent::query()->findOrFail($iRecordId)` which raises `Illuminate\Database\Eloquent\ModelNotFoundException` for `id = 0` (the boundary-narrowed default from `postRecordId`) or any deleted-since-page-load record. This propagates as an uncaught 500 through the backend AJAX framework. The test `test_on_replay_record_id_zero_or_missing_rejects` (line 178) verifies the exception is thrown — but a `500` response on a missing row is poor UX for what is fundamentally a stale-page scenario (operator deleted a row in tab A then clicks Replay in tab B).

The docblock at lines 27-28 claims "(int) post('record_id') + findOrFail validates the user-input boundary" — validation that 500s is not validation, it is a crash.

**Fix:** Either catch `ModelNotFoundException` at the AJAX boundary and `Flash::error` + return the list refresh, or downgrade `findOrFail` to `find` + null guard:

```php
private function findRowOrFail(int $iRecordId): FailedEvent
{
    if ($iRecordId <= 0) {
        throw new \RuntimeException('metapixel: invalid record_id for replay');
    }
    $obRow = FailedEvent::query()->find($iRecordId);
    if (! $obRow instanceof FailedEvent) {
        // operator-friendly: deleted-since-page-load, not a crash
        Flash::error('metapixel: failed event row no longer exists');
        throw new \RuntimeException('metapixel: failed event row '.$iRecordId.' not found');
    }
    return $obRow;
}
```

The bare `\RuntimeException('...query returned non-FailedEvent row...')` at line 345 is also unreachable — `findOrFail` either returns a `FailedEvent` or throws `ModelNotFoundException`; it cannot return a non-FailedEvent. Drop the dead `instanceof` check.

### WR-05: `lookupForSite(null)` hardcoded on Replay AND CheckDedup despite multi-site D-01 carry-forward

**File:** `controllers/FailedEvents.php:168-169, 218`
**Issue:** Both Replay and CheckDedup pass `null` as the site_id, falling back to the default-row credentials per D-01. The plugin CLAUDE.md states `getSiteId from subject only` — `FailedEvent` rows do carry `subject_type` + `subject_id` columns (per `FailedEvent.php:43-44` `$fillable`), so the adapter could resolve the actual site_id and route credentials correctly. The controller acknowledges this in lines 165-167 ("FailedEvent has no site_id column in v2.0") but the data IS available — Replay could re-resolve via the AdapterRegistry it already calls at line 156.

On a multi-site install (.no / .lv / .lt per parent CLAUDE.md), an operator clicking Replay on a `.no` site's failed event will dispatch through the **default-row** pixel which may belong to `.lv`. Result: events fire under the wrong pixel ID. The README troubleshooting note ("operators should configure default-row as primary site") is a documentation workaround for a fixable bug.

**Fix:** After `$obRegistry->resolveByClass($sAdapterType)` succeeds, the adapter MAY expose `getSiteId(object $obSubject)`. The FailedEvent row carries `subject_type` (opaque alias) + `subject_id` (int) — re-hydrate the subject through the adapter and call `getSiteId`. If the subject is no longer fetch-able (deleted product, deleted order), fall back to `null` with an explicit `Flash::warning('metapixel: replay using default credentials — original subject unavailable')`.

If site routing is genuinely out of scope for v2.0 the docblock should be loud about it ("WARNING: replay does NOT honor per-site credentials — see issue #N") not a soft README footnote.

### WR-06: Mockery `alias:` mocks in tests will leak into subsequent tests in the same process

**File:** `tests/Feature/Controllers/FailedEventsReplayTest.php:44`, `FailedEventsCheckDedupTest.php:39`, `tests/Feature/Settings/TrustedHostsValidationTest.php:36`, `tests/Unit/Models/SettingsBeforeSaveTest.php:45, 56, 68, 79`
**Issue:** `Mockery::mock('alias:\Flash')` (and friends) creates a class alias under the autoloader for the `Flash` class. Once that alias is registered in a PHP process, **subsequent tests in the same process inherit the alias** — they cannot un-alias `Flash` back to the real October facade. Mockery's own documentation requires `runInSeparateProcess` + `preserveGlobalState = false` for `alias:` and `overload:` mocks.

`phpunit.xml` line 4 has `processIsolation="false"`. Neither test class declares the PHPUnit `#[RunInSeparateProcess]` attribute. The test will pass in isolation but silently flake (or pollute downstream tests) when run as part of `composer qa` / `pest`.

The presence of `tests/Feature/Plugin/ShopaholicConditionalRegistrationTest.php:20` ("This avoids Mockery::mock('overload:') and its runInSeparateProcess") shows the team is aware of the pattern — but the awareness was not applied to these 7 new alias mocks.

**Fix:** Either add `#[RunInSeparateProcess]` + `#[PreserveGlobalState(false)]` attributes at the test-method level OR drop the alias-mock and bind a real test-only `Flash` facade root. The cleanest fix for OctoberCMS is `Flash::swap(new Illuminate\Support\MessageBag)` or `App::instance('flash', new SpyFlashBag)` at setUp — no alias mock required.

### WR-07: `EnsureFbpFbcCookies::resolveResponse` throws `\LogicException` on non-Response inner pipeline — kills the request before any safe path

**File:** `middleware/EnsureFbpFbcCookies.php:74-84`
**Issue:** The `\LogicException` thrown at line 78 if the inner pipeline returns a non-Response is a fail-CLOSED choice in a middleware whose entire stance is fail-SAFE (untrusted host → NO-OP, kill switch failure → defaults to enabled but only sets cookies when host is allowed, missing PSL file → resolver returns null and NO-OP at line 53). One inner middleware returning a `JsonResponse` (which IS a `Symfony\Component\HttpFoundation\Response` subclass, so this is fine), or returning a `Closure`/`null` due to bugs upstream, will now `500` the entire request instead of letting the upstream pipeline handle the malformed shape.

The runtime narrowing comment at line 73 says "phpstan level 10 cannot prove the Response shape statically" — true, but Laravel's middleware contract uses `mixed` return because the framework wraps non-Response values in `Response::create()` downstream. Throwing here pre-empts that.

**Fix:** Cast to Response defensively or let the value bubble; one option:

```php
private function resolveResponse(Closure $fnNext, Request $obRequest): Response
{
    $mResponse = $fnNext($obRequest);
    if ($mResponse instanceof Response) {
        return $mResponse;
    }
    // Defensive narrowing — let downstream Kernel::handle wrap non-Response values
    // exactly as it does for non-middleware Closure pipelines. fail-SAFE matches
    // the rest of this middleware's stance.
    return new Response((string) (is_scalar($mResponse) ? $mResponse : ''), 200);
}
```

If you want fail-fast for diagnostic reasons, log + throw — but then the docblock at line 19 ("middleware NO-OPs ... no exception thrown") becomes a lie. Pick one stance and document it.

## Info

### IN-01: `AddMultisitePixelIdAndToken` migration is a documented no-op — file is dead weight

**File:** `updates/AddMultisitePixelIdAndToken.php:18-30`
**Issue:** The migration body is empty (`up()` is `if (!Schema::hasTable(...)) return;` then a comment; `down()` is empty). The docblock claims the file exists for "marketplace install-log traceability." That is a real reason in OctoberCMS marketplace audits, but the equivalent traceability can be achieved via a `notes/MIGRATION_LOG.md` entry without a fake migration. The current shape will also collide with strict QA rules ("no dead code, no unused functions" per CLAUDE.md).

Suggestion: Either drop the file + version.yaml entry (1.0.2 collapses to "documentation-only release") OR add an actual concern to the migration (e.g. ensure `system_settings.site_id` index exists, even though October ships it — would be defensive).

### IN-02: `HostIndexResolver` stale-PSL check uses Carbon-equivalent literal — 15552000 magic number lacks named constant breakdown

**File:** `classes/helper/HostIndexResolver.php:22`
**Issue:** `STALE_THRESHOLD_SECONDS = 15552000` is documented as "180 days × 86_400" in the docblock above it. The literal is a magic number; a more idiomatic shape (still no Carbon at this layer) would be `180 * 86400` evaluated at compile time — readable AND grep-discoverable. Tiger-Style "no magic numbers" applies.

```php
private const STALE_THRESHOLD_SECONDS = 180 * 86400; // 180 days
```

### IN-03: `EnsureFbpFbcCookies::shouldSkip` backend-URI check uses `str` startsWith semantics, not host-aware routing

**File:** `middleware/EnsureFbpFbcCookies.php:94-98`
**Issue:** The backend skip uses `$obRequest->is(ltrim($sBackendUri, '/').'*')` which is path-pattern based. If an operator sets `BACKEND_URI=back` in production but mounts the backend on a subdomain (`admin.example.com` with `cms.backendUri=''`), the path-based skip fails silently and the middleware runs on backend pages. This is not a security bug (cookies on backend pages are harmless), but the docblock claim "Backend paths skip" is misleading. For a marketplace plugin, an explicit `BackendHelper::isBackendUrl` check (October ships this) is safer:

```php
if (\Backend\Classes\BackendController::isBackendController()) {
    return true;
}
```

(Verify the helper name in your October 4 version.)

### IN-04: `Plugin::registerSchedule` parameter type is doc-only — actual signature loses type safety

**File:** `Plugin.php:157-159`
**Issue:** `public function registerSchedule($obSchedule): void` — the parameter is intentionally untyped to match the parent `PluginBase::registerSchedule($schedule)` LSP variance (correctly documented in the RESEARCH note at line 154). The `@param Schedule` docblock is the only type signal. This is acceptable per the LSP constraint, but the method body could narrow at runtime:

```php
public function registerSchedule($obSchedule): void
{
    if (! $obSchedule instanceof Schedule) {
        throw new \LogicException('metapixel: registerSchedule expected Schedule, got '.get_debug_type($obSchedule));
    }
    $obSchedule->command('metapixel:purge-event-log')->daily();
}
```

(This adds defense in depth without breaking LSP variance.)

### IN-05: `RefreshPsl` console command does not verify TLS / signature of upstream PSL response — only sentinel string match

**File:** `console/RefreshPsl.php:46-61`
**Issue:** `RefreshPsl` validates the downloaded PSL by checking for the literal `// ===BEGIN ICANN DOMAINS===` sentinel. An attacker MITM-ing `https://publicsuffix.org` (or a compromised PSL upstream) could craft a malicious PSL that contains the sentinel AND adds bogus TLDs / public-suffix entries that change how subdomain-index resolution works at request time. The download relies entirely on Guzzle's default TLS validation (which IS strict by default, so cert verification will fail an active MITM) — but no integrity check (SHA-256 sum, GPG signature, response-header `Content-MD5`) is performed.

For v2.0 marketplace shipping this is acceptable (TLS is sufficient for the threat model), but a note in the docblock about the implicit TLS-only trust model would be helpful for operators who route `publicsuffix.org` through a corporate proxy.

### IN-06: `EnsureFbpFbcCookies` does not log a Log::warning for untrusted-host NO-OP path

**File:** `middleware/EnsureFbpFbcCookies.php:46-50`
**Issue:** When the operator's `trusted_hosts` Settings textarea is missing the current host (`! in_array($sHost, $arTrustedHosts, true)`), the middleware silently returns. For a marketplace plugin where the operator's most common config error will be "I set up trusted_hosts but cookies still aren't writing," a single `Log::info('metapixel: skipping cookie write — host X not in trusted_hosts allowlist')` (rate-limited to once per host per request via memo) would dramatically reduce support load.

Not a bug — but the operator-feedback ergonomics here are the weakest in Phase 4. Compare with `HostIndexResolver::checkPslAge` which DOES emit operator-feedback warnings.

---

_Reviewed: 2026-05-20T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
