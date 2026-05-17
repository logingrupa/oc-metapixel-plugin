---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 5
slug: metaclient-payloadbuilder-userdatahasher
type: execute
wave: 3
depends_on:
  - 02-01
  - 02-03a
  - 02-03b
files_modified:
  - plugins/logingrupa/metapixel/classes/Meta/MetaClient.php
  - plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php
  - plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php
  - plugins/logingrupa/metapixel/composer.json
  - plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php
  - plugins/logingrupa/metapixel/tests/Unit/Meta/MetaClientTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Meta/PayloadBuilderTest.php
  - plugins/logingrupa/metapixel/tests/Unit/Meta/UserDataHasherTest.php
autonomous: true
requirements:
  - ADAP-07
  - ADAP-08
  - ADAP-09
maps_to:
  pitfalls: []
  decisions:
    - D-18
    - D-19
    - D-21
    - D-22
must_haves:
  truths:
    - "`Logingrupa\\Metapixel\\Classes\\Meta\\MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` exists; per-call credentials; no singleton Settings read inside MetaClient (D-19)."
    - "`MetaClient::META_GRAPH_API_VERSION` constant equals `'v23.0'` (D-18); no operator override; v20 expires 2026-09-24."
    - "MetaClient throws `MetaApiTransientException` on HTTP 408/429/5xx + Guzzle ConnectException; throws `MetaApiPermanentException` on any other HTTP error; throws `MissingPixelConfigException` on empty pixel_id; throws `MissingCapiTokenException` on empty token."
    - "`Logingrupa\\Metapixel\\Classes\\Meta\\PayloadBuilder::buildEventPayload(string, EventSubjectAdapter, object, ValueResolver, string, int, array): array` is subject-agnostic; NO `switch`, NO `match`, NO `===` / `!==` / `==` / `in_array` comparisons on `$sEventName` inside body (OQ-3 resolution + H-9 grep gate); merges `$arEventExtras` into custom_data."
    - "`Logingrupa\\Metapixel\\Classes\\Meta\\UserDataHasher::forSubject(EventSubjectAdapter, object): array` hashes the 9 hashable Meta CAPI fields via sha256 lowercase (em, ph, fn, ln, ct, st, zp, country, external_id); pass-through for fbp, fbc, client_ip_address, client_user_agent. M-4 lock: NO static memo property + NO reset() method (per CLAUDE.md 'build only for current need' — Phase 3 ThemeEventCollector adds memo when a real cross-event repeat surfaces)."
    - "Guzzle (`guzzlehttp/guzzle ^7.8`) added to plugin composer.json `require:`; operator runs `composer update logingrupa/oc-metapixel-plugin` from project root to refresh the lockfile (H-4 — no `composer update` from plugin dir, no broken `||` shell fallback)."
    - "`tests/Doubles/SpyMetaClient.php` ships in this plan (deferred from plan 02-01 Task 4 — SpyMetaClient extends MetaClient which lands here in Wave 3)."
    - "All 3 test files pass (T8 + T9 + T10) — coverage matrix on MockHandler for 200/4xx/5xx/timeout classification."
    - "composer qa exits 0."
  artifacts:
    - path: "plugins/logingrupa/metapixel/classes/Meta/MetaClient.php"
      provides: "ADAP-09 — per-call credentials Graph API client; Graph v23.0 pinned."
      contains: "META_GRAPH_API_VERSION"
    - path: "plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php"
      provides: "ADAP-07 — subject-agnostic envelope assembler."
      contains: "buildEventPayload"
    - path: "plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php"
      provides: "ADAP-08 — sha256 hashing per Meta CAPI spec; stateless (M-4 — no memo until Phase 3 reveals real need)."
      contains: "forSubject"
    - path: "plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php"
      provides: "Shared MetaClient subclass for hook + queue tests in plans 02-06 / 02-07 (H-6 deferred to Wave 3 because SpyMetaClient extends MetaClient)."
      contains: "class SpyMetaClient extends MetaClient"
  key_links:
    - from: "plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php"
      to: "plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php"
      via: "constructor injection"
      pattern: "UserDataHasher"
    - from: "plugins/logingrupa/metapixel/classes/Meta/MetaClient.php"
      to: "plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php"
      via: "throw on 408/429/5xx"
      pattern: "MetaApiTransientException"
    - from: "plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php"
      to: "plugins/logingrupa/metapixel/classes/Meta/MetaClient.php"
      via: "class SpyMetaClient extends MetaClient"
      pattern: "extends MetaClient"
---

<objective>
Ship the three subject-agnostic backbone classes: `MetaClient` (Guzzle HTTP boundary to Graph API v23.0, per-call credentials), `PayloadBuilder` (envelope assembler — NO event-name comparisons of any form per H-9), `UserDataHasher` (sha256 of Meta CAPI user_data fields; stateless per M-4). These close ADAP-07, ADAP-08, ADAP-09 and unblock plan 02-06 SendCapiEvent which orchestrates all three. Also ship `tests/Doubles/SpyMetaClient.php` (deferred from plan 02-01 Task 4 — SpyMetaClient extends MetaClient which lands here in Wave 3).

OQ-3 + H-9 RESOLUTION applies: PayloadBuilder is event-name-agnostic. Adapter + ValueResolver + `$arEventExtras` parameter carry per-event shape. NO `switch ($sEventName)`, NO `match ($sEventName)`, NO `if ($sEventName === ...)`, NO `in_array($sEventName, [...])` — the combined grep gate catches all four anti-patterns. Future adapter events (AddToCart, Lead, ViewContent) ship without builder edits.

M-4 RESOLUTION: UserDataHasher is stateless. No `$arMemo` property, no `reset()` method, no memo-clears test. Phase 3 ThemeEventCollector adds the memo when a real cross-event repeat surfaces (CLAUDE.md "build only for current need").

H-4 RESOLUTION: Guzzle install is documented (composer.json require entry + operator instruction to run `composer update logingrupa/oc-metapixel-plugin` from project root). Verify step uses `composer validate` + `php -r class_exists` smoke from plugin dir against `../../../vendor/autoload.php` (3 levels up to project root).

Output: 3 production classes (`classes/Meta/`) + 1 composer.json edit (Guzzle dep) + 1 SpyMetaClient double + 3 unit tests covering 200/4xx/5xx/timeout + sha256 + envelope shape.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/REQUIREMENTS.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/classes/Adapter/EventSubjectAdapter.php
@plugins/logingrupa/metapixel/classes/Adapter/ValueResolver.php
@plugins/logingrupa/metapixel/classes/Exception/MetaApiTransientException.php
@plugins/logingrupa/metapixel/classes/Exception/MetaApiPermanentException.php
@plugins/logingrupa/metapixel/classes/Exception/MissingPixelConfigException.php
@plugins/logingrupa/metapixel/classes/Exception/MissingCapiTokenException.php
@plugins/logingrupa/metapixel/composer.json

<interfaces>
Locked decisions:

- D-18: Graph API pinned to `v23.0` via `MetaClient::META_GRAPH_API_VERSION = 'v23.0'` constant. v20 expires 2026-09-24. NO operator override.
- D-19: `MetaClient::sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` — per-call credentials, no singleton Settings read inside.
- D-21: `PayloadBuilder::buildEventPayload(string, EventSubjectAdapter, object, ValueResolver, string, int, array): array` subject-agnostic.
- D-22: `UserDataHasher::forSubject(EventSubjectAdapter, object): array` — adapter provides raw, hasher does sha256 ONLY. M-4 lock: no memo.
- OQ-3 (RESEARCH §3 resolution) + H-9 lock: NO event-name comparisons of any form inside PayloadBuilder. `$arEventExtras` carries per-event extras.

MetaClient shape (RESEARCH §4.4 — ~120-150 LOC; split helpers if bigger):

```
namespace Logingrupa\Metapixel\Classes\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;

final class MetaClient
{
    public const META_GRAPH_API_VERSION = 'v23.0';

    private const META_GRAPH_API_BASE = 'https://graph.facebook.com';

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /** @var list<int> */
    private const TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    public function __construct(private readonly ?ClientInterface $obClient = null) {}

    /**
     * Per-call credentials. Caller (SendCapiEvent::handle) resolves $sPixelId + $sToken
     * via Settings::lookupForSite($iSiteId) so multi-pixel routing works at queue time.
     *
     * @param  array<string, mixed>  $arPayload  envelope with key "data" => list of event records
     * @return array<string, mixed>
     *
     * @throws MissingPixelConfigException when $sPixelId is empty
     * @throws MissingCapiTokenException  when $sToken is empty
     * @throws MetaApiTransientException  on 408/429/5xx + ConnectException
     * @throws MetaApiPermanentException  on any other HTTP error
     */
    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
    {
        if ($sPixelId === '') {
            throw new MissingPixelConfigException('metapixel: pixel_id is empty at dispatch time');
        }
        if ($sToken === '') {
            throw new MissingCapiTokenException('metapixel: capi_access_token is empty at dispatch time');
        }

        $sUrl = sprintf('%s/%s/%s/events',
            self::META_GRAPH_API_BASE,
            self::META_GRAPH_API_VERSION,
            $sPixelId,
        );

        $obClient = $this->obClient ?? new Client(['timeout' => self::DEFAULT_TIMEOUT_SECONDS]);

        try {
            $obResponse = $obClient->request('POST', $sUrl, [
                'json' => array_merge($arPayload, ['access_token' => $sToken]),
                'http_errors' => false,
            ]);
        } catch (ConnectException $obException) {
            throw new MetaApiTransientException(
                'metapixel: graph API connect failure',
                null,
                $obException,
                ['url' => $sUrl],
            );
        }

        $iStatus = $obResponse->getStatusCode();
        $sBody = (string) $obResponse->getBody();
        $arDecoded = json_decode($sBody, associative: true) ?: [];

        if ($iStatus >= 200 && $iStatus < 300) {
            return $arDecoded;
        }

        if (in_array($iStatus, self::TRANSIENT_STATUS_CODES, true)) {
            throw new MetaApiTransientException(
                'metapixel: graph API transient '.$iStatus,
                $iStatus,
                null,
                ['response' => $arDecoded],
            );
        }

        throw new MetaApiPermanentException(
            'metapixel: graph API permanent '.$iStatus,
            $iStatus,
            null,
            ['response' => $arDecoded],
        );
    }
}
```

NOTE: `access_token` MUST be in the POST body, NOT in the URL query string. Meta accepts both but body is the recommended pattern (URL appears in logs). `[VERIFIED: Meta Conversions API docs — POST body access_token shape canonical]`.

`http_errors` flag is set to false so Guzzle does not auto-throw on 4xx/5xx — we classify ourselves. This is the Phase 1 pattern Phase 2 mirrors.

L-2 caveat: 5-second default timeout may be tight for Graph API on slow links. Configurable in v2.1 if operator reports issues. Phase 2 ships the default.

PayloadBuilder shape (RESEARCH §3 + §4.5 — ~50 LOC):

```
namespace Logingrupa\Metapixel\Classes\Meta;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

final class PayloadBuilder
{
    private const ACTION_SOURCE = 'website';

    public function __construct(private readonly UserDataHasher $obHasher) {}

    /**
     * Subject-agnostic Graph API envelope. Adapter + ValueResolver + $arEventExtras
     * carry per-event shape — NO event-name comparisons here (no switch / match /
     * === / in_array). Future events ship by authoring a new adapter, not by
     * editing the builder.
     *
     * @param  array<string, mixed>  $arEventExtras  per-event extras the resolver cannot precompute
     * @return array<string, mixed>
     */
    public function buildEventPayload(
        string $sEventName,
        EventSubjectAdapter $obAdapter,
        object $obSubject,
        ValueResolver $obResolver,
        string $sEventId,
        int $iEventTime,
        array $arEventExtras,
    ): array {
        $arUserData = $this->obHasher->forSubject($obAdapter, $obSubject);

        $arCustomData = [
            'currency' => $obResolver->resolveCurrency($obSubject),
            'value' => $obResolver->resolveValue($obSubject),
            'num_items' => $obResolver->resolveNumItems($obSubject),
            'contents' => $obResolver->resolveContents($obSubject),
            'content_ids' => $obResolver->resolveContentIds($obSubject),
            'content_type' => 'product',
        ];

        if ($arEventExtras !== []) {
            $arCustomData = array_merge($arCustomData, $arEventExtras);
        }

        return ['data' => [[
            'event_id' => $sEventId,
            'event_time' => $iEventTime,
            'event_name' => $sEventName,
            'action_source' => self::ACTION_SOURCE,
            'user_data' => $arUserData,
            'custom_data' => $arCustomData,
        ]]];
    }
}
```

UserDataHasher shape (RESEARCH §4.6 — ~40 LOC after M-4 memo removal):

```
namespace Logingrupa\Metapixel\Classes\Meta;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

final class UserDataHasher
{
    /** Fields Meta expects pre-hashed (sha256 lowercase). Lowercased before hash. */
    private const HASHABLE_FIELDS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];

    /** Fields Meta expects raw (not hashed). Pass-through. */
    private const PASSTHROUGH_FIELDS = ['fbp', 'fbc', 'client_ip_address', 'client_user_agent'];

    /**
     * Hash adapter-supplied raw fields per Meta CAPI spec. Stateless.
     *
     * @return array<string, ?string>
     */
    public function forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array
    {
        $arRaw = $obAdapter->getUserData($obSubject);
        $arResult = [];

        foreach (self::HASHABLE_FIELDS as $sField) {
            $arResult[$sField] = $this->hashField($arRaw[$sField] ?? null);
        }
        foreach (self::PASSTHROUGH_FIELDS as $sField) {
            $arResult[$sField] = $arRaw[$sField] ?? null;
        }

        return $arResult;
    }

    private function hashField(?string $sValue): ?string
    {
        if ($sValue === null || $sValue === '') {
            return null;
        }
        return hash('sha256', strtolower(trim($sValue)));
    }
}
```

M-4 RESOLUTION (plan-checker R1 — drop the memo):

Original spec had a per-instance `$arMemo` property + `reset()` method + memo-key built from `subject_type.':'.subject_id` + memo-hit branch. CLAUDE.md "Build only for current need" — Phase 2 has NO caller that hashes the same subject twice in one request. SendCapiEvent calls hasher exactly ONCE per dispatch via PayloadBuilder. Phase 3 ThemeEventCollector flushes multiple events per request but each ThemeActionEvent has its own synthetic_id (DIFFERENT subjects).

Add memo back in Phase 3 if ThemeEventCollector reveals a real cross-event repeat. The memo + reset() + memo-clears test (originally T9 b) are all premature for Phase 2. Saves ~15 LOC + 1 test method.

`[VERIFIED: PHP 8.3 hash('sha256', ...) returns 64-char hex; matches Meta CAPI spec.]`

Composer.json delta (H-4 lock):
- Add `guzzlehttp/guzzle ^7.8` to plugin `composer.json` `require:` (NOT require-dev — it's a production runtime dep used by MetaClient).
- DO NOT run `composer update` from the plugin dir — plugin packages don't carry composer.lock; the project root composer.json + composer.lock is authoritative.
- Document operator runs `composer update logingrupa/oc-metapixel-plugin` from project root to refresh the lockfile.
- Verify step: `composer validate` from plugin dir + `php -r 'require_once "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1); echo "ok\n";'`. Path `../../../vendor` from `plugins/logingrupa/metapixel/` is 3 levels up to project root vendor (correct).

`ramsey/uuid ^4.7` is needed for UUIDv4 generation in plan 02-06 (SendCapiEvent generates the event_id). Phase 1 confirmed Laravel's `Str::uuid()` already wraps Ramsey UUID. Use `Str::uuid()->toString()` from plan 02-06 — no direct Ramsey import needed.

SpyMetaClient shape (deferred from plan 02-01 Task 4 — must extend MetaClient which lands here):

```
namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Meta\MetaClient;

/**
 * Test spy: records sendForPixel call count + last payload. Pair with hook unit
 * tests (plans 02-06 / 02-07) to assert race-fence + listener behavior without
 * hitting Guzzle.
 */
class SpyMetaClient extends MetaClient
{
    public int $iCallCount = 0;

    /** @var array<string, mixed> */
    public array $arLastPayload = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
    {
        $this->iCallCount++;
        $this->arLastPayload = $arPayload;
        return ['events_received' => 1];
    }
}
```

NOT `final` — test subclasses may want a throwing variant (plan 02-06 Task 2 T14 dead-letter test inline-subclasses to throw MetaApiPermanentException).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add guzzlehttp/guzzle to composer.json require (H-4 — no plugin-dir composer update)</name>
  <files>
    plugins/logingrupa/metapixel/composer.json
  </files>
  <action>
Edit `plugins/logingrupa/metapixel/composer.json`. Current `require:` block (Phase 1 state):

```
"require": {
    "php": "^8.3 || ^8.4",
    "october/system": "^4.0",
    "lovata/toolbox-plugin": "^2.2"
},
```

Add Guzzle (alphabetical within the block):

```
"require": {
    "guzzlehttp/guzzle": "^7.8",
    "lovata/toolbox-plugin": "^2.2",
    "october/system": "^4.0",
    "php": "^8.3 || ^8.4"
},
```

H-4 NOTE: DO NOT run `composer update` from the plugin dir. Plugin packages don't carry composer.lock; the root project's composer.lock is authoritative. After this commit:
- The plugin's composer.json declares the constraint.
- Operator runs `composer update logingrupa/oc-metapixel-plugin --with-dependencies --no-interaction` from project root (`/home/forge/nailscosmetics.lv/`) to refresh the project's vendor/ + composer.lock.
- Guzzle is also a transitive dep of October 4 / Laravel 12 — it's already installed in `/home/forge/nailscosmetics.lv/vendor/guzzlehttp/`. The explicit `require:` pins it for the marketplace standalone-install case.

Verify (from plugin dir):

```
composer validate --no-check-publish
php -r 'require_once "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1); echo "ok\n";'
```

Path `../../../vendor/autoload.php` from `plugins/logingrupa/metapixel/` is 3 levels up to project root vendor (correct). The php -r smoke confirms Guzzle's Client class autoloads from the project root vendor.

If `composer-dependency-analyser` flags `guzzlehttp/guzzle` as unused at this point (no class imports it yet — Tasks 2–4 add them), that's expected and resolves itself after Task 4.
  </action>
  <verify>
    <automated>grep -q '"guzzlehttp/guzzle"' plugins/logingrupa/metapixel/composer.json &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; composer validate --no-check-publish 2&gt;&amp;1 | tail -3 | grep -qE '(valid|Composer schema)' &amp;&amp; php -r 'require_once "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1); echo "ok\n";'</automated>
  </verify>
  <done>composer.json adds guzzlehttp/guzzle ^7.8 to require; composer validate passes; GuzzleHttp\Client autoloads from project root vendor via 3-level-up path.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write UserDataHasher (M-4 — stateless, no memo)</name>
  <files>
    plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Meta\UserDataHasher`.
    - Two arrays: HASHABLE_FIELDS = [em, ph, fn, ln, ct, st, zp, country, external_id] (9 keys); PASSTHROUGH_FIELDS = [fbp, fbc, client_ip_address, client_user_agent] (4 keys). Total returned-key count = 13.
    - `forSubject(EventSubjectAdapter, object): array<string, ?string>` returns map of all 13 keys.
    - Hashable fields: trim + strtolower + sha256 → 64-char hex. Empty / null input → null (NEVER `hash('sha256', '')` which returns a real hash of empty string — Meta would treat that as a hash collision).
    - Passthrough fields: returned as-is (string or null).
    - **M-4 lock: NO `$arMemo` property, NO `reset()` method, NO memo-key computation.** Hasher is stateless. Phase 3 adds memo when ThemeEventCollector reveals a real cross-event repeat.
    - File ≤ 45 LOC (smaller than the original 70 LOC after memo removal).
  </behavior>
  <action>
Create `classes/Meta/UserDataHasher.php` per the shape in `<interfaces>`. Hungarian-notation throughout. Private `hashField(?string): ?string` helper.

Key implementation details:

1. `hash('sha256', strtolower(trim($sValue)))` — trim FIRST, lowercase SECOND, then hash. Meta's spec: trim whitespace, lowercase email/phone, then sha256.
2. `$sValue === null || $sValue === ''` → return null. Do NOT hash empty string (returns `e3b0c44298fc1c149...` which collides with anyone else passing empty).
3. M-4 lock: NO memo. The hasher is stateless. forSubject computes the result on every call. Saves ~15 LOC + 1 test method (the original T9 memo-clears-test) + 1 unnecessary mental model for downstream readers.

The hasher is constructor-injected into PayloadBuilder. Laravel resolves `App::make(UserDataHasher::class)` to a fresh instance per resolve. Phase 2 has no caller that hashes the same subject twice in one request — SendCapiEvent calls hasher exactly ONCE per dispatch via PayloadBuilder. CLAUDE.md "Build only for current need."
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class UserDataHasher' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; grep -q 'HASHABLE_FIELDS' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; grep -q 'PASSTHROUGH_FIELDS' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; grep -q "hash\('sha256'" plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; ! grep -q 'arMemo' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; ! grep -q 'function reset' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9]|// P-0[0-9])' plugins/logingrupa/metapixel/classes/Meta/UserDataHasher.php</automated>
  </verify>
  <done>UserDataHasher.php is final + has 13 documented keys + sha256 hash call + NO $arMemo + NO reset() (M-4) + no phase markers.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Write PayloadBuilder (H-9 — combined event-name grep gate)</name>
  <files>
    plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Meta\PayloadBuilder`.
    - Constructor accepts `UserDataHasher` (readonly promoted property).
    - One public method `buildEventPayload(string $sEventName, EventSubjectAdapter, object, ValueResolver, string $sEventId, int $iEventTime, array $arEventExtras): array`.
    - Constant `ACTION_SOURCE = 'website'`.
    - Body: assemble user_data via hasher; assemble custom_data via resolver; merge $arEventExtras into custom_data (if non-empty); return `['data' => [[event_id, event_time, event_name, action_source, user_data, custom_data]]]`.
    - **H-9 lock: NO event-name comparisons of ANY form** — no `switch ($sEventName)`, no `match ($sEventName)`, no `if ($sEventName === ...)`, no `if ($sEventName !== ...)`, no `in_array($sEventName, [...])`. Verified by combined grep gate in verify step.
    - File ≤ 60 LOC.
  </behavior>
  <action>
Create `classes/Meta/PayloadBuilder.php` per the shape in `<interfaces>`. The single method has zero event-name branching — the verify step's H-9 combined grep gate explicitly catches:

- `switch ($sEventName)` and `switch($sEventName)` (with or without space)
- `match ($sEventName)` and `match($sEventName)`
- `$sEventName === ...`
- `$sEventName !== ...`
- `$sEventName == ...`
- `in_array($sEventName, ...)`

H-9 combined regex: `! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|in_array\s*\(\s*\$sEventName' file.php`

The merge order matters: `$arCustomData` (from ValueResolver) FIRST, then `$arEventExtras` overlay via `array_merge`. This means an adapter can override `content_type` or `currency` for a specific event by passing the new value through `$arEventExtras`. Phase 3 ThemeActionAdapter uses this to inject `action_key` / `synthetic_id` keys without colliding with the base custom_data shape.

`content_type` default 'product' matches Meta's expectation for e-commerce events. For ThemeActionAdapter ViewContent-on-a-CMS-article events, the operator overrides via `$arEventExtras = ['content_type' => 'article']`.
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class PayloadBuilder' plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php &amp;&amp; grep -q 'buildEventPayload' plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php &amp;&amp; ! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|in_array\s*\(\s*\$sEventName' plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php &amp;&amp; grep -q "'action_source' => self::ACTION_SOURCE" plugins/logingrupa/metapixel/classes/Meta/PayloadBuilder.php</automated>
  </verify>
  <done>PayloadBuilder.php is final + has buildEventPayload + H-9 combined grep gate (no switch / match / === / !== / == / in_array on $sEventName) + constant ACTION_SOURCE='website'.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Write MetaClient</name>
  <files>
    plugins/logingrupa/metapixel/classes/Meta/MetaClient.php
  </files>
  <behavior>
    - Final class `Logingrupa\Metapixel\Classes\Meta\MetaClient`.
    - Public constant `META_GRAPH_API_VERSION = 'v23.0'`.
    - Private constants `META_GRAPH_API_BASE = 'https://graph.facebook.com'`, `DEFAULT_TIMEOUT_SECONDS = 5`, `TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504]`.
    - Constructor accepts optional `?ClientInterface $obClient = null` (DI for tests via MockHandler).
    - Public method `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array`.
    - Empty $sPixelId → throw MissingPixelConfigException.
    - Empty $sToken → throw MissingCapiTokenException.
    - Build URL `{BASE}/{VERSION}/{pixelId}/events`.
    - POST with `access_token` merged INTO the json body (NOT URL query), `http_errors => false`.
    - ConnectException → throw MetaApiTransientException (carrying the original exception as previous).
    - 2xx → return decoded array.
    - 408/429/5xx → throw MetaApiTransientException with HTTP status.
    - any other (4xx) → throw MetaApiPermanentException with HTTP status.
    - File ≤ 130 LOC.
  </behavior>
  <action>
Create `classes/Meta/MetaClient.php` per the shape in `<interfaces>`. Implementation notes:

- The `?ClientInterface $obClient` constructor parameter (typed against Guzzle's `GuzzleHttp\ClientInterface`) lets tests inject a Client with MockHandler. Production code instantiates `new Client(['timeout' => 5])` if null is passed.
- `http_errors => false` is the Guzzle option that disables auto-throw on 4xx/5xx (Phase 1 v1.x pattern).
- `json_decode($sBody, associative: true) ?: []` returns the decoded array or empty on parse failure.
- ConnectException is caught and rewrapped as MetaApiTransientException. The original is passed as `$obPrevious` so logs preserve the stack trace.
- Plan 02-03b's MetaPixelException base requires a `string $sMessage` first arg — our HTTP exceptions accept `(string, ?int, ?Throwable, array)` per plan 02-03b's signature.

Helper splitting if MetaClient grows: keep `sendForPixel` ≤ 50 LOC by extracting `classifyResponse(int $iStatus, array $arDecoded): void` private helper. Plan 02-03b's signature on MetaApiTransientException allows `null` http_status (for ConnectException) and an `int` (for status-coded responses).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; php -l plugins/logingrupa/metapixel/classes/Meta/MetaClient.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'final class MetaClient' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; grep -q "META_GRAPH_API_VERSION = 'v23.0'" plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; grep -q 'TRANSIENT_STATUS_CODES' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; grep -q 'MissingPixelConfigException' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; grep -q 'MetaApiPermanentException' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; grep -q 'MetaApiTransientException' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php &amp;&amp; ! grep -E '(// CR-[0-9]|// Phase\s*[0-9]|// Plan\s*[0-9])' plugins/logingrupa/metapixel/classes/Meta/MetaClient.php</automated>
  </verify>
  <done>MetaClient.php is final + has v23.0 constant + transient status array + 4 exception throw branches + no phase markers.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 5: Write 3 unit tests (T8 + T9 + T10) — M-4 drops T9 memo-clears branch</name>
  <files>
    plugins/logingrupa/metapixel/tests/Unit/Meta/PayloadBuilderTest.php
    plugins/logingrupa/metapixel/tests/Unit/Meta/UserDataHasherTest.php
    plugins/logingrupa/metapixel/tests/Unit/Meta/MetaClientTest.php
  </files>
  <behavior>
    - T8 `PayloadBuilderTest::test_*` — envelope shape (top-level keys: data; per-event keys: event_id, event_time, event_name, action_source, user_data, custom_data); $arEventExtras merges into custom_data; subject-agnostic (same FakeAdapter shape produces same envelope for different event names — only event_name field changes).
    - T9 `UserDataHasherTest::test_*` (M-4 — 4 tests, NOT 6; memo + reset tests dropped):
        - test_email_is_sha256_lowercased_trimmed
        - test_null_and_empty_inputs_return_null_not_hash_of_empty
        - test_passthrough_fields_are_not_hashed
        - test_returns_all_thirteen_documented_keys
    - T10 `MetaClientTest::test_*` — Guzzle MockHandler matrix: 200 → returns decoded array; 408/429/5xx → throws Transient; 4xx → throws Permanent; ConnectException → throws Transient with previous; empty pixel_id → throws MissingPixelConfigException; empty token → throws MissingCapiTokenException; URL contains v23.0 + pixel ID; access_token in body NOT URL.
    - All tests use H-8 setUp pattern (`$this->app->singleton(AdapterRegistry::class)` direct bind — even though MetaClient tests don't use the registry, the convention applies project-wide).
    - All tests use FakeAdapter from `tests/Doubles/` (plan 02-01) — no inline anonymous adapters (H-6).
    - All tests pass.
  </behavior>
  <action>
Use the shared FakeAdapter + FakeValueResolver doubles from `tests/Doubles/` (plan 02-01 Task 4). No inline anonymous-class adapter declarations.

T8 `PayloadBuilderTest.php`:
- `test_envelope_has_six_top_level_event_keys` — instantiate FakeAdapter + FakeValueResolver; call `(new PayloadBuilder(new UserDataHasher))->buildEventPayload('Purchase', $obAdapter, new \stdClass, $obResolver, 'uuid-1', 1700000000, [])`; assert each of the 6 keys present in `data[0]`; assert action_source='website', event_name='Purchase', event_id='uuid-1', event_time=1700000000.
- `test_event_extras_merge_into_custom_data` — pass `['action_key' => 'product-view:42', 'content_type' => 'article']` as $arEventExtras; assert `custom_data['action_key']` + `custom_data['content_type']='article'` (overrides default 'product').
- `test_envelope_subject_agnostic_same_adapter_different_events` — call buildEventPayload twice with different event names ('Purchase' + 'ViewContent'); assert user_data + action_source + custom_data are identical (only event_name + event_id + event_time differ).

T9 `UserDataHasherTest.php` (4 tests, M-4 — dropped memo tests):
- `test_email_is_sha256_lowercased_trimmed` — pass `['em' => '  FOO@BAR.COM  ']` to FakeAdapter; assert hash('sha256', 'foo@bar.com') matches.
- `test_null_and_empty_inputs_return_null_not_hash_of_empty` — pass `['em' => null, 'ph' => '']`; assert both result keys are null (not the SHA of empty string).
- `test_passthrough_fields_are_not_hashed` — pass `['fbp' => 'fb.1.x.42', 'fbc' => 'fb.1.x.fbclid', 'client_ip_address' => '203.0.113.10', 'client_user_agent' => 'Mozilla/5.0']`; assert each is returned as-is.
- `test_returns_all_thirteen_documented_keys` — assert keys of the result equal the 13-key set (sort both before assertSame).

DROPPED (M-4): `test_per_request_memo_returns_cached_on_second_call` and `test_reset_clears_memo` — UserDataHasher is now stateless.

T10 `MetaClientTest.php`:
- Use Guzzle MockHandler + HandlerStack + Middleware::history pattern.
- DataProviders for transient (408/429/500/502/503/504) and permanent (400/401/403/404) status codes — `public static function provideTransientStatusCodes()` etc.
- `test_send_for_pixel_returns_decoded_array_on_200`.
- `test_throws_missing_pixel_config_on_empty_pixel_id`.
- `test_throws_missing_capi_token_on_empty_token`.
- `test_throws_transient_on_status` (dataProvider for 6 transient codes).
- `test_throws_permanent_on_status` (dataProvider for 4 permanent codes).
- `test_connect_exception_rewrapped_as_transient` — MockHandler with `new ConnectException(...)`.
- `test_url_contains_graph_version_and_pixel_id` — Middleware::history captures the request; assert URL contains `/v23.0/PIXEL-42/events` + access_token NOT in URL + access_token IS in body JSON.

H-8: each test file's setUp method does `parent::setUp(); $this->app->singleton(AdapterRegistry::class);` (consistency with the rest of Phase 2, even though MetaClient tests don't exercise the registry).

L-8 confirmation: all 3 test files use `final class FooTest extends MetapixelTestCase` (classic Pest style).

DataProvider methods use `public static function` (PHPUnit 12 requirement).

Coverage check: MetaClient has roughly 6 branches (empty pixel_id, empty token, ConnectException, 2xx, transient, permanent) — all 6 hit. PayloadBuilder has 2 branches (extras empty vs non-empty). UserDataHasher has 3 branches (hashable hit, hashable null, passthrough hit) after M-4 memo removal — no memo branch.
  </action>
  <verify>
    <automated>for f in plugins/logingrupa/metapixel/tests/Unit/Meta/PayloadBuilderTest.php plugins/logingrupa/metapixel/tests/Unit/Meta/UserDataHasherTest.php plugins/logingrupa/metapixel/tests/Unit/Meta/MetaClientTest.php; do test -f "$f" || { echo "missing $f"; exit 1; }; php -l "$f" | grep -q 'No syntax errors' || exit 1; done &amp;&amp; ! grep -E 'test_per_request_memo|test_reset_clears_memo' plugins/logingrupa/metapixel/tests/Unit/Meta/UserDataHasherTest.php &amp;&amp; ! grep -rE '\(new\s+\\?Logingrupa\\\\Metapixel\\\\Plugin\)' plugins/logingrupa/metapixel/tests/Unit/Meta/ &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; ../../../vendor/bin/pest tests/Unit/Meta --configuration phpunit.xml 2&gt;&amp;1 | tail -10 | grep -Eq '(PASS|OK|Tests:.*passed)'</automated>
  </verify>
  <done>3 test files exist + php -l clean; UserDataHasherTest has NO memo/reset tests (M-4); H-8 setUp pattern enforced; pest tests/Unit/Meta exits 0 with ≥ 16 test methods passing (3 PayloadBuilder + 4 UserDataHasher + 3 MetaClient single + 6 transient + 4 permanent via dataProviders — at least 20 methods including dataProvider cases).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 6: Ship SpyMetaClient double (H-6 deferred from plan 02-01 Task 4)</name>
  <files>
    plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php
  </files>
  <behavior>
    - `class SpyMetaClient extends MetaClient` (NOT final — test subclasses may want throwing variants).
    - Constructor calls `parent::__construct(null)`.
    - Public `int $iCallCount = 0`; public `array $arLastPayload = []`.
    - Override `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array` → increments counter, captures payload, returns `['events_received' => 1]`.
    - Namespace `Logingrupa\Metapixel\Tests\Doubles` (autoload-dev).
    - php -l clean.
  </behavior>
  <action>
Create `tests/Doubles/SpyMetaClient.php` per the shape in `<interfaces>`. This file was deferred from plan 02-01 Task 4 because SpyMetaClient extends MetaClient, which only lands in Wave 3 (this plan). Plans 02-06 hook unit tests + 02-07 BackboneIntegrationTest import SpyMetaClient by FQN.

Short Laravel docblock: "Test spy: records sendForPixel call count + last payload. Pair with hook unit tests (plans 02-06 / 02-07) to assert race-fence + listener behavior without hitting Guzzle."

NO comment pollution. NOT `final` (plan 02-06 T14 dead-letter test inline-subclasses to throw MetaApiPermanentException).
  </action>
  <verify>
    <automated>test -f plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php &amp;&amp; php -l plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php | grep -q 'No syntax errors' &amp;&amp; grep -q 'class SpyMetaClient extends MetaClient' plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php &amp;&amp; grep -q 'public int \$iCallCount' plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php &amp;&amp; ! grep -q 'final class' plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php</automated>
  </verify>
  <done>SpyMetaClient.php exists + php -l clean + extends MetaClient + NOT final + has iCallCount + arLastPayload properties.</done>
</task>

<task type="auto">
  <name>Task 7: composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/composer.json
    plugins/logingrupa/metapixel/classes/Meta/
    plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php
    plugins/logingrupa/metapixel/tests/Unit/Meta/
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer qa 2>&1 | tee /tmp/02-05-qa.log | tail -30
```

Likely phpstan issues:
- `json_decode` return type `mixed` → narrow via `is_array($arDecoded) ? $arDecoded : []`.
- Guzzle's `Response::getBody()` returns `StreamInterface` — cast to string via `(string) $obResponse->getBody()`.
- DataProvider methods being non-static (PHPUnit 12 deprecation) → declared `public static` above.

Likely composer-dependency-analyser issue:
- Guzzle declared in require + imported in MetaClient → analyser happy.
- `mockery/mockery` already declared in require-dev (Phase 1) — no need to add for MockHandler tests (we use Guzzle's MockHandler, not Mockery).

If `composer test-cov --min=90` fails:
- Verify branch coverage — the `array_merge($arCustomData, $arEventExtras)` branch hits both paths; UserDataHasher's two foreach paths hit both lists; MetaClient's 6 branches all tested.

Commit:

```
git add plugins/logingrupa/metapixel/composer.json \
        plugins/logingrupa/metapixel/composer.lock \
        plugins/logingrupa/metapixel/classes/Meta/ \
        plugins/logingrupa/metapixel/tests/Doubles/SpyMetaClient.php \
        plugins/logingrupa/metapixel/tests/Unit/Meta/

git commit -m "$(cat <<'EOF'
feat(metapixel): MetaClient + PayloadBuilder + UserDataHasher (ADAP-07..09)

MetaClient::sendForPixel takes per-call credentials, hits Graph API
v23.0 (pinned via META_GRAPH_API_VERSION constant — v20 expires
2026-09-24). Throws MissingPixel/Token exceptions on empty config;
Transient on 408/429/5xx + ConnectException; Permanent on any other
HTTP error. Guzzle 7.8 added to require (operator runs composer update
from project root to refresh the lockfile). access_token sent in POST
body, never URL query (prevents leaks via webserver logs).

PayloadBuilder::buildEventPayload is subject-agnostic and event-name-
agnostic — NO switch / match / === / in_array on \$sEventName (H-9
combined grep gate). Adapter + ValueResolver + \$arEventExtras carry
per-event shape (OQ-3 resolution). Future events ship by authoring a
new adapter, not by editing the builder.

UserDataHasher::forSubject hashes Meta CAPI's 9 hashable fields via
sha256(trim+lower) and pass-throughs the 4 raw fields. Empty + null
input → null (NOT hash of empty string). Stateless (M-4 — no memo
until Phase 3 ThemeEventCollector reveals a real cross-event repeat).

tests/Doubles/SpyMetaClient.php ships in this wave (deferred from
plan 02-01 Task 4 because SpyMetaClient extends MetaClient).

Coverage on the Guzzle MockHandler matrix proves the 6 status-code +
ConnectException branches; PayloadBuilder + UserDataHasher tests cover
envelope shape, extras merge, sha256 spec compliance.
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'MetaClient' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | grep -c '^plugins/logingrupa/metapixel/' | xargs test 7 -le</automated>
  </verify>
  <done>composer qa exits 0; commit touches ≥ 7 files including composer.json + 3 Meta classes + SpyMetaClient + 3 test files; commit message references ADAP-07..09.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| MetaClient → graph.facebook.com HTTPS | Per-call credentials cross the HTTP boundary. access_token in POST body (NOT URL query) prevents leaks via webserver access logs. Guzzle's default verify=true validates the cert. |
| UserDataHasher input → sha256 output | Adapter-supplied user_data may contain PII (email, phone, name). Hasher applies sha256 before payload assembly; raw values never leave the hasher. Passthrough fields (fbp/fbc/IP/UA) are intentionally NOT hashed per Meta CAPI spec. |
| PayloadBuilder → adapter + resolver | PayloadBuilder trusts the adapter + resolver fully. Phase 2 doesn't ship production adapters; Phase 3 ShopaholicAdapter is internally trusted code. Third-party adapter quality enforced by plan 02-07's ContractTestCase. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-05-01 | Tampering | An adversary intercepts the CAPI POST and modifies the event | accept | TLS to graph.facebook.com prevents MITM. Meta validates `access_token` server-side. Caller's responsibility to manage token rotation. |
| T-02-05-02 | Spoofing | A malicious plugin passes a fake `$sToken` to MetaClient | mitigate | Settings::lookupForSite is the only blessed token source (per ADAP-09 + plan 02-06 SendCapiEvent flow). A third-party plugin would have to override Settings to inject a fake token — out-of-band tampering covered by OctoberCMS file permissions. |
| T-02-05-03 | Repudiation | Operator wonders why event_id X didn't reach Meta | mitigate | Plan 02-06 SendCapiEvent records FailedEvent rows on permanent failure with the graph_error response body. MetaApiTransient retries via queue backoff. |
| T-02-05-04 | Information Disclosure | access_token leaks via URL query string in webserver access logs | mitigate | MetaClient passes token in POST body via `'json' => array_merge($arPayload, ['access_token' => $sToken])`. T10's URL test asserts `assertStringNotContainsString('access_token=', $sUrl)`. |
| T-02-05-05 | Denial of Service | A pathological listener mutates `$arPayload` to enormous size causing Guzzle timeout | mitigate | Plan 02-06's before_dispatch listener-isolation try/catch + payload-mutation contract documents max-size expectation. Phase 2 ships no size cap; Phase 4 may add one if operator reports the issue. L-2 — 5s default timeout may be tight; configurable v2.1 if needed. |
| T-02-05-06 | Elevation of Privilege | A bug in UserDataHasher leaks raw PII via reflection | accept | UserDataHasher is stateless per M-4 — no memo property to leak via reflection. Per-request instance lifecycle; PHP runtime guarantees process isolation between requests. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "MetaClient::sendForPixel per-call credentials, Graph v23.0" — Task 4 implements; Task 5 T10 verifies via MockHandler matrix + URL test.
2. "PayloadBuilder::buildEventPayload subject-agnostic, no event-name comparisons" — Task 3 implements; Task 5 T8 + H-9 combined grep guard in Task 3 verify.
3. "UserDataHasher sha256 per Meta CAPI spec (stateless per M-4)" — Task 2 implements; Task 5 T9 covers spec; memo tests DROPPED.
4. "5 exceptions thrown at correct boundaries" — Task 4 throws all 4 client-side classes; Task 5 T10 asserts via dataProvider matrix.
5. "Guzzle 7.8 in require (H-4 — operator updates from project root)" — Task 1.
6. "SpyMetaClient ships (H-6 deferred from plan 02-01)" — Task 6.
7. "composer qa exits 0" — Task 7.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| REQ ADAP-07 (PayloadBuilder subject-agnostic) | Requirement | Task 3 | H-9 combined grep gate (no switch / match / === / in_array on $sEventName) |
| REQ ADAP-08 (UserDataHasher::forSubject adapter-driven) | Requirement | Task 2 | Adapter provides raw, hasher does sha256 — stateless per M-4 |
| REQ ADAP-09 (MetaClient::sendForPixel per-call credentials, Graph v23.0) | Requirement | Task 4 | Constant pinned; per-call creds; no singleton read inside |
| CONTEXT D-18 (Graph v23.0 pinned, no override) | Decision | Task 4 | `public const META_GRAPH_API_VERSION = 'v23.0'` |
| CONTEXT D-19 (sendForPixel per-call credentials) | Decision | Task 4 | Method signature matches verbatim |
| CONTEXT D-21 (PayloadBuilder shape) | Decision | Task 3 | 7-parameter signature; subject-agnostic body |
| CONTEXT D-22 (UserDataHasher::forSubject) | Decision | Task 2 | Adapter provides raw; hasher hashes — stateless per M-4 |
| RESEARCH §3 OQ-3 resolution (no switch in PayloadBuilder, $arEventExtras carries) | Decision | Task 3 + H-9 verify grep guard | Enforced |
| RESEARCH §4.4 MetaClient shape | Reference | Task 4 | Code matches |
| RESEARCH §4.5 PayloadBuilder shape | Reference | Task 3 | Code matches |
| RESEARCH §4.6 UserDataHasher shape | Reference | Task 2 | Code matches WITHOUT memo (M-4 — deferred to Phase 3) |
| RESEARCH §6 T8/T9/T10 tests | Reference | Task 5 | All three test files land; T9 has 4 tests instead of 6 (M-4 — no memo) |
| RESEARCH §9 A2 (CCache memo in cache.default=array env) | Risk | Task 2 | RESOLVED — M-4 drops the memo entirely; no need for CCache or static-array memo |
| Plan 02-03b exception hierarchy | Dependency | Tasks 4 + 5 imports | MissingPixel/Token + MetaApiTransient/Permanent all extant before Plan 02-05 starts (Wave 3 follows Wave 2) |
| Plan 02-03a EventLog model | Dependency | Indirect (this plan doesn't import; plan 02-06 does) | Wave 3 follows Wave 2 |
| Plan-checker H-4 (Guzzle install verify) | Revision | Task 1 | composer.json require entry + operator instruction + verify uses composer validate + php -r class_exists from project-root vendor |
| Plan-checker H-9 (PayloadBuilder grep) | Revision | Task 3 verify | Combined regex catches switch / match / === / !== / == / in_array on $sEventName |
| Plan-checker M-4 (UserDataHasher memo dropped) | Revision | Task 2 + Task 5 T9 | Stateless hasher; 4 tests instead of 6 |
| Plan-checker H-6 (SpyMetaClient shared fixture) | Revision | Task 6 | Deferred from plan 02-01 Task 4 to this Wave-3 plan because SpyMetaClient extends MetaClient |
| Plan-checker H-8 (Plugin instantiation in tests) | Revision | Task 5 | All 3 test setUps use `$this->app->singleton(AdapterRegistry::class)` direct bind |
| Plan-checker L-4 (Log facade FQN) | Revision (not relevant — MetaClient does not log; relies on caller) | n/a | This plan's classes don't import Log directly |
| Plan-checker L-8 (classic Pest style) | Revision | Task 5 | All 3 test files use `final class FooTest extends MetapixelTestCase` |

No gaps.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 7's commit. Coverage on the 3 new classes ≥ 90% via the unit-test matrix.
</verification>

<success_criteria>
Plan 02-05 ships when ALL of the following hold:

1. `classes/Meta/MetaClient.php` is final + has `META_GRAPH_API_VERSION = 'v23.0'` + per-call credential params + 4 exception throw branches + access_token in body (not URL).
2. `classes/Meta/PayloadBuilder.php` is final + has buildEventPayload + H-9 combined grep gate passes (no switch / match / === / !== / == / in_array on $sEventName anywhere) + ACTION_SOURCE='website'.
3. `classes/Meta/UserDataHasher.php` is final + 9 hashable + 4 passthrough fields + sha256(trim+lower) + null/empty → null + M-4 lock (NO $arMemo property, NO reset() method).
4. `composer.json` require adds `guzzlehttp/guzzle ^7.8` (H-4 — operator runs composer update from project root to refresh lockfile; verify is composer validate + php -r class_exists from project-root vendor).
5. `tests/Doubles/SpyMetaClient.php` ships (H-6 deferred from plan 02-01 Task 4) — NOT final, extends MetaClient, has iCallCount + arLastPayload + overrides sendForPixel.
6. 3 unit test files exist + pest tests/Unit/Meta exits 0 with ≥ 16 test methods (UserDataHasher: 4 tests instead of 6 per M-4; PayloadBuilder: 3 tests; MetaClient: 3 + 6 transient + 4 permanent via dataProviders).
7. `composer qa` exits 0; coverage ≥ 90% on the 3 new classes.
8. Single commit on HEAD touches ≥ 7 files (composer.json + 3 classes + SpyMetaClient + 3 tests).
9. No comment pollution.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-05-SUMMARY.md` documenting:

- Single commit SHA.
- composer qa output tail.
- Test counts per file: PayloadBuilderTest 3+ tests; UserDataHasherTest 4 tests (M-4 — no memo/reset tests); MetaClientTest 12+ tests (3 single + 6 transient + 4 permanent via dataProviders).
- Coverage per new class (expected: MetaClient ≥ 95%, PayloadBuilder 100%, UserDataHasher ≥ 95%).
- Confirm M-4 — UserDataHasher is stateless; memo deferred to Phase 3.
- Confirm H-4 — operator-run composer update from project root populated the project vendor with Guzzle 7.8.
- Confirm H-9 — combined grep gate passes on PayloadBuilder source.
- Confirm H-6 — SpyMetaClient ships under tests/Doubles/.
- Graph API URL verification snippet from T10 (`/v23.0/PIXEL-42/events`).
- Phase 2 plan-state update: 02-05 closed; plan 02-06 (SendCapiEvent + hooks) Wave 4 now ready to start.
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker findings H-4 (Task 1 reframes Guzzle install — composer.json require entry + operator instruction to run `composer update logingrupa/oc-metapixel-plugin` from project root; verify uses `composer validate` + `php -r class_exists` from project-root vendor via `../../../vendor/autoload.php` — eliminates broken `||` shell fallback and plugin-dir `composer update` failure mode), H-9 (Task 3 PayloadBuilder verify gate strengthened to combined regex catching switch / match / === / !== / == / in_array on $sEventName — original gate only caught `switch` + `===`), M-4 (Task 2 UserDataHasher made stateless — dropped $arMemo property + reset() method + memo-clears test method; defer memo to Phase 3 ThemeEventCollector per CLAUDE.md "build only for current need"), H-6 (Task 6 ships SpyMetaClient deferred from plan 02-01 — must extend MetaClient which only lands here in Wave 3), H-8 (Task 5 setUp uses `$this->app->singleton(AdapterRegistry::class)` direct bind), L-8 (Task 5 confirms classic Pest style).
