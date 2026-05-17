---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
reviewed: 2026-05-17T00:00:00Z
depth: standard
files_reviewed: 23
files_reviewed_list:
  - Plugin.php
  - classes/adapter/AdapterRegistry.php
  - classes/adapter/EventSubjectAdapter.php
  - classes/adapter/ValueResolver.php
  - classes/exception/MetaApiPermanentException.php
  - classes/exception/MetaApiTransientException.php
  - classes/exception/MetaPixelException.php
  - classes/exception/MissingCapiTokenException.php
  - classes/exception/MissingPixelConfigException.php
  - classes/helper/EventLogWriter.php
  - classes/helper/PluginGuard.php
  - classes/helper/SiteResolver.php
  - classes/meta/MetaClient.php
  - classes/meta/PayloadBuilder.php
  - classes/meta/UserDataHasher.php
  - classes/queue/SendCapiEvent.php
  - classes/testing/EventSubjectAdapterContractTestCase.php
  - models/EventLog.php
  - models/FailedEvent.php
  - models/Settings.php
  - updates/CreateMetapixelEventLogTable.php
  - updates/CreateMetapixelFailedEventsTable.php
findings:
  critical: 3
  warning: 7
  info: 5
  total: 15
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-05-17
**Depth:** standard
**Files Reviewed:** 23 (22 source + 1 testing harness — `EventSubjectAdapterContractTestCase` ships as production-facing extension surface per CLAUDE addendum)
**Status:** issues_found

## Summary

Generic event-dispatch backbone for Metapixel v2.0. Architecture is clean: adapter registry + payload builder + HTTP client + queue job, with a tightly scoped UNIQUE race fence and dead-letter persistence. Hungarian notation is consistent. PHP 8.4-only syntax is absent. The PHPStan `disallowedMethodCalls` deny-list anchors the cross-context-determinism invariant statically.

However, the adversarial pass surfaces three blocker-class defects in the locked-decision surface:

1. The `before_dispatch` snapshot/restore (P-08) is conditional on the listener preserving payload shape — a listener that empties `data` or replaces `data[0]` bypasses event_id/event_time restoration, breaking server↔browser dedup. The locked decision says mutation is "forbidden via snapshot+restore", but the implementation only restores when the shape is intact.
2. `MetaClient::sendForPixel` builds the JSON envelope with `array_merge($arPayload, ['access_token' => $sToken])` — if upstream ever places an `'access_token'` key inside `$arPayload` (e.g., a malicious or buggy adapter via `before_dispatch`), it gets silently overwritten by the real token, OR if `array_merge` semantics change between PHP minor versions the operator-supplied key wins. Token-injection vector via the `before_dispatch` mutation hook.
3. `UserDataHasher` does not validate the structural type of `$arRaw[$sField]` — adapters returning non-strings (e.g., int order id under `external_id`) crash `strtolower(trim(...))` with a TypeError at dispatch time, because adapters are typed `array<string, ?string>` only via docblock — there is no runtime guard.

Additional warnings around `failed()` exception classification, `MissingCapiTokenException` being treated as permanent rather than transient, JSON encode return-value handling, contract-test coverage gaps, and a few minor schema concerns. See per-finding details below.

The Structural Findings section is omitted — no `<structural_findings>` block was provided to this review.

## Critical Issues

### CR-01: `before_dispatch` snapshot/restore can be bypassed by listener payload shape mutation

**File:** `classes/queue/SendCapiEvent.php:163-194`
**Issue:** P-08 locks "Mutating event_id/event_time is forbidden — Snapshot+restore guarantees enforcement." The implementation snapshots `event_id` + `event_time` at lines 166-167, passes `$arMutablePayload` by reference into `Event::fire`, then on lines 176-181 restores both fields **only when**:

```php
if (isset($arMutablePayload['data']) && is_array($arMutablePayload['data'])
    && isset($arMutablePayload['data'][0]) && is_array($arMutablePayload['data'][0])
) {
    $arMutablePayload['data'][0]['event_id'] = $sEventId;
    $arMutablePayload['data'][0]['event_time'] = $iEventTime;
}
$this->arPayload = $arMutablePayload;
```

A listener that does `$arPayload = []`, `unset($arPayload['data'])`, `$arPayload['data'] = []`, or `$arPayload['data'][0] = ['event_id' => 'attacker-controlled']` (e.g., swapping the inner record entirely so `is_array` still passes but the snapshot keys are not re-applied because we're inserting on `data[0]` which we don't verify was even ours) will:

- Case A (`data` cleared): pass the `isset` check fails → restore skipped → `$this->arPayload` is now an empty array. Downstream `MetaClient::sendForPixel` posts `{access_token: ...}` only — Meta returns a 400 dead-letter. Dedup contract is violated implicitly (because no event went to Meta but the EventLogWriter race-fence row was already written upstream at line 105-113 — wait, it was NOT written yet at this point; race-fence write is after the hook. So this case is mostly self-healing. Still bad UX.)
- Case B (`data[0]` replaced with attacker map containing arbitrary `event_id`): `isset` passes → restore overwrites → safe.
- **Case C (most dangerous): listener replaces `data[0]` with a different record shape that omits other fields**, e.g. `$arPayload['data'][0] = ['event_id' => $original, 'event_time' => $original, 'evil_field' => '...']`. Restore writes correct values back, but `event_name`, `user_data`, `custom_data`, `action_source` are now missing/attacker-controlled. The dispatch pipeline ships an arbitrary record to Meta under the original event_id.

The contract is "before_dispatch may mutate payload, but event_id/event_time are server-owned and inviolable." The implementation enforces only the second half — and only conditionally. A misbehaving (or compromised — third-party plugin) listener can ship arbitrary payloads against a legitimate event_id.

**Fix:**
```php
private function fireBeforeDispatchHalt(EventSubjectAdapter $obAdapter): bool
{
    try {
        $sEventId = $this->readEventId();
        $iEventTime = $this->readEventTime();
        $arSnapshot = $this->arPayload;

        $arMutablePayload = $this->arPayload;
        $mResult = Event::fire(
            self::HOOK_BEFORE_DISPATCH,
            [$this->sEventName, &$arMutablePayload, $this->obSubject],
            true,
        );

        // Restore protected fields unconditionally — listener's data[0] shape
        // is irrelevant. If listener corrupted the envelope, we restore the
        // server-owned snapshot of event_id/event_time and re-attach to whatever
        // shape we can produce. Simplest: rebuild data[0] envelope on top of
        // listener's data[0] only when shape is intact; otherwise restore full
        // snapshot and log.
        if (! isset($arMutablePayload['data'][0]) || ! is_array($arMutablePayload['data'][0])) {
            Log::warning('metapixel: before_dispatch listener destroyed envelope shape — restoring snapshot', [
                'meta_pixel.event_id' => $sEventId,
            ]);
            $this->arPayload = $arSnapshot;
            return $mResult === false;
        }
        $arMutablePayload['data'][0]['event_id'] = $sEventId;
        $arMutablePayload['data'][0]['event_time'] = $iEventTime;
        $this->arPayload = $arMutablePayload;

        return $mResult === false;
    } catch (Throwable $obException) {
        // ... existing
    }
}
```

Add a test: listener that does `unset($arPayload['data'])` → assert `$this->arPayload['data'][0]['event_id']` still equals the original UUID after the hook.

---

### CR-02: Token-injection via `array_merge` precedence in `MetaClient::sendForPixel`

**File:** `classes/meta/MetaClient.php:67`
**Issue:**
```php
'json' => array_merge($arPayload, ['access_token' => $sToken]),
```

`array_merge` semantics: later-array string keys overwrite earlier-array string keys. So if `$arPayload` contains `'access_token'`, the call-site-supplied `$sToken` wins. That's the *intended* behavior, but the dispatch path (`SendCapiEvent`) lets a `before_dispatch` listener mutate `$this->arPayload` — and `$arPayload` here is the envelope after listener mutation (the protected-field restore in CR-01 only restores `data[0].event_id`/`event_time`, not top-level keys).

A malicious or buggy listener can:

- Inject `$arPayload['access_token'] = 'attacker_token'` — overwritten by real token (safe, as long as `array_merge` order is preserved).
- Inject `$arPayload['test_event_code'] = 'TEST123'` — silently flips the event to test mode, hiding production events from Meta dashboards (no validation, accepted by Meta API).
- Inject `$arPayload['data'] = [<99 spurious records>]` — Meta accepts arbitrary batched events under the operator's pixel.

The token-overwrite issue itself is contained (token always wins), but the broader "top-level envelope is operator-trusted, not adapter-trusted" assumption is undocumented and creates a footgun: any operator who writes a `before_dispatch` listener can stamp arbitrary CAPI fields into every event without a code review surface flagging it.

**Fix:** Whitelist top-level envelope keys before posting. The Meta Conversions API envelope only legitimately contains `data`, `test_event_code`, `partner_agent`, `upload_id`. Anything else is junk or attack:

```php
private const ALLOWED_ENVELOPE_KEYS = ['data', 'test_event_code', 'partner_agent', 'upload_id'];

// in sendForPixel, after constructing $arPayload but before posting:
$arFiltered = array_intersect_key($arPayload, array_flip(self::ALLOWED_ENVELOPE_KEYS));
$arBody = array_merge($arFiltered, ['access_token' => $sToken]);

try {
    $obResponse = $obClient->request('POST', $sUrl, [
        'json' => $arBody,
        'http_errors' => false,
    ]);
}
```

Additionally, consider asserting `'access_token'` is not present in `$arPayload` and rejecting with `MetaPixelException` if it is — that's a clear adapter contract violation. (Defense in depth — `array_merge` already protects, but failing loud surfaces bugs faster.)

---

### CR-03: `UserDataHasher::hashField` crashes on non-string adapter values (TypeError → uncaught at queue layer)

**File:** `classes/meta/UserDataHasher.php:41-48`, `classes/adapter/EventSubjectAdapter.php:57`
**Issue:** `getUserData()` is *documented* as `@return array<string, ?string>` but enforced only by PHPStan static analysis. The contract test (`test_invariant_07_get_user_data_returns_documented_meta_capi_keys`, line 125-145) asserts `string|null` *after* the adapter returns — too late if the adapter ever returns `1234` (e.g., a user `id` cast accidentally to external_id as int).

`hashField` is typed `?string` — so PHP 8.4 type coercion will throw a TypeError when given an int. That TypeError propagates out of `forSubject` → `PayloadBuilder::buildEventPayload` → `SendCapiEvent::handle` (no try/catch wrapping `$obClient->sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $this->arPayload)` covers it — the try/catch on lines 120-130 only handles `MetaApiTransient/PermanentException` and `MissingPixelConfig/CapiTokenException`).

Result: queue job throws TypeError → Laravel queue retries 3 times → `failed()` runs → `writeFailedEvent` records `attempts=3` against a TypeError that will recur forever (deterministic bug). Until: `failed()` itself calls `getSubjectType($this->obSubject)` and `getSubjectId($this->obSubject)` — at line 250-251 — which depend on the adapter being non-null; if the adapter call chain also fails (e.g., for a different reason — but in this path adapter is non-null), this works.

So worst case: silent infinite retries of a poisoned event until manual intervention. Best case: 3 retries + dead-letter row that an operator must triage.

This also extends to other adapter methods returning unexpected types:
- `getSubjectType` returns non-string → `EventLogWriter` DB write fails with type error (caught by Throwable catch at line 73 → fail-safe path returns false → event dropped silently)
- `getSubjectId` returns int but `<= 0` → guarded at line 48
- `getSubjectId` returns non-int (e.g., string from a buggy adapter) → TypeError propagates out, same dead-letter loop

**Fix:** Add runtime coercion + validation in `UserDataHasher::forSubject`:

```php
public function forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array
{
    $arRaw = $obAdapter->getUserData($obSubject);
    $arResult = [];

    foreach (self::HASHABLE_FIELDS as $sField) {
        $mValue = $arRaw[$sField] ?? null;
        if ($mValue !== null && ! is_string($mValue)) {
            Log::warning('metapixel: UserDataHasher coercing non-string user_data field', [
                'meta_pixel.field' => $sField,
                'meta_pixel.actual_type' => get_debug_type($mValue),
                'meta_pixel.adapter' => get_class($obAdapter),
            ]);
            $mValue = is_scalar($mValue) ? (string) $mValue : null;
        }
        $arResult[$sField] = $this->hashField($mValue);
    }
    foreach (self::PASSTHROUGH_FIELDS as $sField) {
        $mValue = $arRaw[$sField] ?? null;
        if ($mValue !== null && ! is_string($mValue)) {
            $mValue = null;
        }
        $arResult[$sField] = $mValue;
    }

    return $arResult;
}
```

Also add a test to the contract test base that injects a deliberately broken adapter returning ints/objects and verifies the hasher does not throw.

---

## Warnings

### WR-01: `MissingCapiTokenException` and `MissingPixelConfigException` are routed to permanent dead-letter, hiding configuration recovery

**File:** `classes/queue/SendCapiEvent.php:124-130`
**Issue:**
```php
} catch (MetaApiPermanentException|MissingPixelConfigException|MissingCapiTokenException $obException) {
    $iStatus = $obException instanceof MetaApiPermanentException ? $obException->getHttpStatus() : null;
    $this->writeFailedEvent($obException, $iStatus, $obAdapter);
    $this->fireDeadLetter($obException, $obAdapter);
    return;
}
```

Missing credentials at dispatch time are treated as a *permanent* failure → FailedEvent row → dead-letter listeners alerted. But:

1. A site admin who forgets to fill `capi_access_token` and saves Settings will cause every queued event to dead-letter until they retroactively run a replay job.
2. Recovery semantics ("operator filled in the token, now what?") are unaddressed — phase 4 admin UI is the documented mechanism, but a replay flow needs to exist.
3. More importantly: a *transient* unavailability of Settings (e.g., DB hiccup loading the settings row, or a multisite credential not yet propagated) would also dead-letter, instead of retrying.

Per `MissingPixelConfigException` docblock: "Boot-time empty pixel_id is handled by PluginGuard (log + disable + no throw) — this exception only fires when an event has slipped past the guard for the current site row." That implies a config gap not a transient gap — so dead-lettering is correct *for the documented contract*. But the Settings model's `lookupForSite` doesn't yet route per-site — Phase 4 MULT-03 does — so today, a permanently-missing token for site B will dead-letter every site-B event silently in production, no operator visibility unless they wired up a `metapixel.event.dead_letter` listener.

**Fix:** Two parts:
1. Add an explicit `Log::error` (not just dead_letter listener fire) when these specific exceptions reach the dispatch path. The dead_letter event hook is observe-only; without an explicit Log line, ops have no signal.
2. Document the recovery path in `MissingCapiTokenException` and `MissingPixelConfigException` docblocks — at minimum, "FailedEvent rows for this exception class are recoverable via Phase 4 admin replay after the operator fills the missing credentials in Settings."

---

### WR-02: `failed()` exception classification is wrong for `MetaApiPermanentException` arrival

**File:** `classes/queue/SendCapiEvent.php:148`
**Issue:**
```php
$iStatus = $obException instanceof MetaApiTransientException ? $obException->getHttpStatus() : null;
```

`failed()` is invoked when the queue exhausts retries. Today the only exception that reaches retry exhaustion is `MetaApiTransientException` (line 122-123 re-throws it; the catch at line 124 *swallows* permanent exceptions). So the `instanceof MetaApiTransientException` check is correct for the present dispatch logic — but it is fragile. If a future change ever lets `MetaApiPermanentException` escape `handle()`, `failed()` writes `http_status = null` even though the exception carries a status. Cosmetic bug today, latent correctness bug tomorrow.

**Fix:** Promote `getHttpStatus()` to the `MetaPixelException` base or extract a `HasHttpStatus` interface:

```php
// In MetaPixelException:
public function getHttpStatus(): ?int
{
    return null;  // overridden by transient/permanent subclasses
}

// In SendCapiEvent::failed and catch handlers, simplify to:
$iStatus = $obException instanceof MetaPixelException ? $obException->getHttpStatus() : null;
```

DRY win + future-proof.

---

### WR-03: `EventLogWriter::record` catches `Throwable` and returns false on ALL DB errors, masking permission/schema issues from ops

**File:** `classes/helper/EventLogWriter.php:73-84`
**Issue:** The "fail-safe direction" rationale in the docblock (line 11-16) is correct *for race-fence collisions*. But the `catch (\Throwable $obException)` also catches:
- DB connection drops
- Missing table (deployment migration not run)
- Missing column (schema drift between code and DB)
- Insufficient grants
- Deadlocks under concurrent load

All collapse to `Log::critical` + `return false` + silent event drop. `Log::critical` is appropriate, but operators rarely watch the log stream — and the upstream caller in `SendCapiEvent::handle` line 114-116 treats `false` as "lost the race, peer won" and silently returns without any further indication.

Result: a production DB schema drift (e.g., `secret_key` column missing because a migration was skipped) will silently swallow 100% of events with no other signal beyond `Log::critical` lines.

**Fix:** Distinguish "duplicate-key fail-safe" from "infrastructure failure" by introspecting the exception:

```php
} catch (\Throwable $obException) {
    $bIsUniqueViolation = $obException instanceof \Illuminate\Database\QueryException
        && in_array($obException->errorInfo[1] ?? null, [1062 /* MySQL */, 19 /* SQLite */, 23505 /* Postgres */], true);

    if ($bIsUniqueViolation) {
        // benign race-fence collision — peer won, return false silently
        return false;
    }

    Log::critical('metapixel: EventLogWriter::record DB write FAILED (NOT a race-fence collision)', [
        'meta_pixel.exception' => get_class($obException),
        'meta_pixel.message' => $obException->getMessage(),
        // ...
    ]);
    return false;
}
```

Today `insertOrIgnore` is supposed to swallow duplicate-key at the driver level — so a `QueryException` reaching the catch is *almost certainly* infrastructure failure, not a collision. Recommend logging at `critical` with a distinct message prefix ops can alert on.

---

### WR-04: `Settings::lookupForSite` `$iSiteId` parameter is unused — silent multisite contract gap

**File:** `models/Settings.php:34-43`
**Issue:** The signature `lookupForSite(?int $iSiteId)` advertises multisite-aware credential lookup; the implementation discards the argument. The docblock says "Phase 2 stub ignores `$iSiteId`. Phase 4 MULT-03 re-implements...". But:

1. PHPStan level 10 will not flag the unused parameter (per-method dead-param detection is off by default).
2. A reader of `SendCapiEvent::handle` sees `Settings::lookupForSite($iSiteId)` and assumes multisite routing is live. It isn't.
3. If a multi-site operator installs the plugin and configures site A pixel + site B pixel, events for both sites silently dispatch with the default-row credentials (whichever was saved last in the backend) until Phase 4 ships.

Phase 2 build philosophy is "ship only for current need" — but the method *exists* and is *called*; partial implementation creates the silent-mis-routing footgun. Either:

(a) Throw `LogicException` when `$iSiteId !== null` (fail-fast — operator on multi-site install gets a hard signal "you cannot use this plugin until Phase 4");
(b) Log a warning when `$iSiteId !== null` is passed and the multisite table has rows;
(c) Remove the `$iSiteId` parameter entirely until Phase 4 — `SendCapiEvent` passes `null` or no-arg.

**Fix:** Option (b) is the least disruptive:

```php
public static function lookupForSite(?int $iSiteId): array
{
    if ($iSiteId !== null) {
        Log::info('metapixel: Settings::lookupForSite ignoring $iSiteId — Phase 4 multisite routing not yet shipped', [
            'meta_pixel.site_id' => $iSiteId,
        ]);
    }
    // ... existing logic
}
```

Combined with WR-01 logging the credential-missing path, this gives operators a clear "you're on Phase 2 default-row mode" signal in logs.

---

### WR-05: `writeFailedEvent` swallows `json_encode` failure silently

**File:** `classes/queue/SendCapiEvent.php:260`
**Issue:**
```php
'graph_error' => $obException->getMessage()."\n".json_encode($arContext),
```

`json_encode` returns `false` on encoding failure (e.g., resource handles, recursive object refs, non-UTF-8 bytes in payload strings). Concatenating `false` to a string yields empty-string for the JSON portion, so the FailedEvent row's `graph_error` ends up with just the exception message and a trailing newline — the *context* is lost without any indication that encoding failed.

Adapters' `getContext()` payloads can include the Graph API response body (decoded into `$arDecoded`), which may include arbitrary Meta-side strings. If Meta ever returns invalid UTF-8 (unusual but not impossible), this loses diagnostic info.

**Fix:**
```php
$sContextJson = json_encode($arContext, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
if ($sContextJson === false) {
    $sContextJson = '{"_encode_error":"context could not be JSON-encoded; see Log::critical for raw repr"}';
    Log::critical('metapixel: writeFailedEvent could not JSON-encode context', [
        'meta_pixel.exception' => get_class($obException),
        'meta_pixel.context_keys' => array_keys($arContext),
    ]);
}
// ...
'graph_error' => $obException->getMessage()."\n".$sContextJson,
```

---

### WR-06: `MetaClient` HTTP client has no TLS verification or proxy configuration plumbing

**File:** `classes/meta/MetaClient.php:63`
**Issue:**
```php
$obClient = $this->obClient ?? new Client(['timeout' => self::DEFAULT_TIMEOUT_SECONDS]);
```

Guzzle's default behavior is `verify=true` against the system CA bundle, so TLS verification is on by default. However:
1. There is no explicit `'verify' => true` — relying on Guzzle defaults makes audit harder and creates a footgun if a future Guzzle major version changes the default.
2. No proxy support — environments behind corporate proxies cannot ship CAPI events. Adding `'proxy' => env('HTTP_PROXY')` or similar is trivial.
3. No `connect_timeout` separately — the 5-second `timeout` is total request time, so a TLS handshake that takes 4.9 seconds + 0.5 seconds for response = hard cutoff at 5s with no graceful degradation.
4. No retry on transport-level errors at the HTTP layer (relying purely on Laravel queue retry).

**Fix:**
```php
$obClient = $this->obClient ?? new Client([
    'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
    'connect_timeout' => 2,
    'verify' => true,
    'http_errors' => false,
]);
```

`http_errors => false` is already set at request time (line 68), which is correct — but setting it at client construction time as a defense-in-depth measure means future maintainers cannot accidentally forget it. The `'verify' => true` is the most important: explicit > implicit for security-relevant config.

---

### WR-07: PII (PII-like) leakage in dead-letter `FailedEvent.payload` blob is not redacted

**File:** `classes/queue/SendCapiEvent.php:259`, `models/FailedEvent.php:24`
**Issue:** When dispatch dead-letters, `$this->arPayload` is persisted verbatim to `FailedEvent.payload` (longtext, cast `array`). That payload contains the post-hasher `user_data` block — which is sha256-hashed but the snapshot also contains the raw `$arEventExtras` (operator-injected `event_source_url`, etc.) and the raw `custom_data` (`content_ids`, `value`). The hashed fields are not reversible, but the *raw* fields in `custom_data` (item SKUs purchased, amounts) and any extras may identify a customer when joined to the orders table.

Additionally, the `fbc` and `fbp` cookie passthrough values (which Meta uses for click attribution) sit in `user_data` unhashed by design — these are click IDs but they're moderately persistent identifiers.

`FailedEvent` is intended for ops triage and Phase 4 replay. The longtext column persists in the DB indefinitely. GDPR / "right to be forgotten" requests would need to scan `logingrupa_metapixel_failed_events.payload` for the user's hashed email / external_id and delete matching rows. Today there's no schema support for that.

**Fix:** Two parts:
1. Index `subject_id` + `subject_type` on `failed_events` already exists. Add admin guidance to use the existing `forSubject` scope (port from `EventLog` to `FailedEvent`) when responding to GDPR deletion.
2. Add a `retention_at` timestamp column to `FailedEvent` so a scheduled job (Phase 4 dependency, but the column should land now to avoid a v2.0 → v2.1 migration churn) can purge old rows. Recommend 90-day default per Meta CAPI guidance.

Defer the actual purge job to Phase 4 admin UI, but commit the column now if the table is being touched. (Acceptable to punt — file as info if you prefer.)

---

## Info

### IN-01: `MetaClient` has no PSR-3 logging of the HTTP roundtrip

**File:** `classes/meta/MetaClient.php`
**Issue:** Successful CAPI dispatches are not logged at any level inside `MetaClient::sendForPixel`. Failure paths log via the exception → caller chain, but operators have no visibility into a 200 response containing `events_received: 0` or `messages: [...warnings...]`. Meta Conversions API returns soft warnings inside a 200 OK body (e.g., "received but cannot be matched"); silent acceptance of those hides quality regressions.

**Fix:** Add a `Log::debug` (won't fire in production but helpful in staging):
```php
if ($iStatus >= 200 && $iStatus < 300) {
    Log::debug('metapixel: graph API 2xx', [
        'meta_pixel.events_received' => $arDecoded['events_received'] ?? null,
        'meta_pixel.messages' => $arDecoded['messages'] ?? [],
    ]);
    return $arDecoded;
}
```

---

### IN-02: `AdapterRegistry::resolveByClass` provides no signal when the class is not registered

**File:** `classes/adapter/AdapterRegistry.php:91-97`
**Issue:** `resolveByClass($sAdapterClass)` does not check whether `$sAdapterClass` was ever passed to `register()`. It just calls `App::make($sAdapterClass)`. Will succeed for *any* class that implements `EventSubjectAdapter`, registered or not. Quietly bypasses the registry contract.

If a queue job's `$sAdapterClass` is stale (e.g., an adapter class was deleted between job enqueue and job dequeue), `App::make` throws `BindingResolutionException`, which is caught at `handle()` line 82 — so end-to-end behavior is safe. But the method name "resolveByClass" implies "look up in the registry", and that's not what it does. Cosmetic but misleading. Consider renaming to `instantiateAdapter` or adding `is_subclass_of` check + a `Log::warning` when unregistered.

**Fix:** Either rename or document:
```php
/**
 * Instantiate $sAdapterClass via the container. Does NOT consult the
 * registered-map — used by queue rehydration where the adapter class FQN
 * is serialized into the job payload and the registry may not yet have
 * been populated at queue worker boot time.
 */
```

---

### IN-03: `PluginGuard::isDisabled` memo is process-scoped — long-running queue workers won't pick up a Settings edit

**File:** `classes/helper/PluginGuard.php:17-38`
**Issue:** `private static ?bool $bIsDisabled` is a class-level static, so it persists across requests in a `php-fpm` worker pool process *and* across queue jobs in a `queue:work --daemon` process. An operator who clears `pixel_id` in the admin UI to disable the plugin won't see effect until:
- The php-fpm worker is recycled (typically every N requests or after a SIGHUP); OR
- `queue:work` is restarted manually.

The `reset()` method exists for tests but is not called in production.

**Fix:** Two options:
1. Document the gotcha in the class docblock so ops know to restart workers after a settings change.
2. Cache the lookup in `Cache::store('array')->remember(...)` (request-scoped only) — but that requires Settings cache invalidation hooks. Probably overkill for Phase 2.

Recommend option 1 — add to docblock:
```php
/**
 * Boot-time and event-time guard. Empty pixel_id → log + disable; never throws.
 *
 * Operator note: the `isDisabled()` memo is process-scoped. After a settings
 * change in the admin UI, queue workers and long-running php-fpm processes
 * must be restarted (`queue:restart` + php-fpm reload) for the new state to
 * take effect.
 */
```

---

### IN-04: `subject_type` VARCHAR(255) is generous given the contract enforces ≤ 64 chars

**File:** `updates/CreateMetapixelEventLogTable.php:32`, `classes/testing/EventSubjectAdapterContractTestCase.php:69`
**Issue:** `EventSubjectAdapterContractTestCase::test_invariant_01_subject_type_is_opaque_alias_format` asserts `strlen <= 64`, but the migration ships `string('subject_type', 255)`. Inconsistency. If 64 is the contract, the DB column should enforce it; if the column allows 255, the contract should not lie.

Slightly more important: the UNIQUE index `(subject_type, subject_id, event_name, channel, site_id)` on InnoDB with utf8mb4 has a max key length of 3072 bytes. 255 * 4 (subject_type) + 4 (subject_id int) + 64 * 4 (event_name) + 16 * 4 (channel) + 4 (site_id int) = 1320 bytes. Fits, but tight on older MySQL versions.

**Fix:** Tighten column to `string('subject_type', 64)`. Smaller index + matches contract.

```php
$obTable->string('subject_type', 64);
```

(Same applies to `FailedEvent.subject_type` for consistency.)

---

### IN-05: Composite UNIQUE on `(event_id, http_status)` for `FailedEvent` allows the same event_id to be re-dead-lettered with different statuses

**File:** `updates/CreateMetapixelFailedEventsTable.php:42-45`
**Issue:** The migration's docblock (lines 12-16) says "a second 400 for the same event_id is a no-op insertOrIgnore". But `SendCapiEvent::writeFailedEvent` does NOT use `insertOrIgnore` — it uses `FailedEvent::create()` which is a regular INSERT. If the unique constraint is hit on a second write, `create()` throws `QueryException`, which is caught by the `Throwable` handler at line 264 → silent warning log + return.

So the flow works (no double dead-letter row), but the docblock claim is wrong about the mechanism. Also: if the same event_id retries and ends up with `MetaApiTransientException` final → `http_status=503` first time + (after manual replay) `http_status=400` second time → unique constraint allows both rows. Whether that's intended or a UX bug depends on the Phase 4 admin UI's expected behavior.

**Fix:** Two parts:
1. Update the migration docblock — "writeFailedEvent uses `create()`; second insert raises QueryException, caught silently."
2. Either tighten unique constraint to `event_id` alone (single dead-letter row per event), or use `insertOrIgnore` / `updateOrCreate` in `writeFailedEvent` to make the docblock truthful.

Either direction is defensible; the *current* combination is the worst — docblock describes one mechanism, code does another. Recommend `updateOrCreate(['event_id' => ...], [...])` — single row per event, latest failure state preserved.

---

_Reviewed: 2026-05-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
