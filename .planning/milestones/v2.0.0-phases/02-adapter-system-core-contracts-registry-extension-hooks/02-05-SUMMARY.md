---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 5
subsystem: meta-client-payload-builder-user-data-hasher
tags: [meta-client, payload-builder, user-data-hasher, capi, graph-api-v23, guzzle, adap-07, adap-08, adap-09, d-18, d-19, d-21, d-22, h-4, h-6, h-9, m-4, hungarian-notation, fail-fast]

requires:
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 1
    provides: EventSubjectAdapter + ValueResolver interfaces + shared tests/doubles/ (FakeAdapter, FakeValueResolver) — UserDataHasher + PayloadBuilder consume; SpyMetaClient ships sibling to MetaClient
  - phase: 02-adapter-system-core-contracts-registry-extension-hooks
    plan: 3b
    provides: MetaPixelException base + MissingPixelConfigException + MissingCapiTokenException + MetaApiTransientException + MetaApiPermanentException — MetaClient throws all four
provides:
  - MetaClient final class with public sendForPixel(string, string, array): array — per-call credentials, Graph API v23.0 pinned, 408/429/5xx + ConnectException → MetaApiTransientException, 4xx → MetaApiPermanentException, 2xx → decoded array; token in body NOT URL
  - PayloadBuilder final class with public buildEventPayload(string, EventSubjectAdapter, object, ValueResolver, string, int, array): array — subject-agnostic + event-name-agnostic; ACTION_SOURCE='website' constant; H-9 grep gate locks zero event-name comparisons
  - UserDataHasher final class with public forSubject(EventSubjectAdapter, object): array<string, ?string> — 9 hashable + 4 passthrough fields; sha256(trim+lower); null/empty → null (M-4 stateless lock)
  - SpyMetaClient test double (class extends MetaClient, NOT final) recording iCallCount + arLastPayload + sLastPixelId + sLastToken for hook + queue tests in plans 02-06 / 02-07
  - composer.json require: adds guzzlehttp/guzzle ^7.8 (marketplace standalone-install contract)
affects:
  - 02-06 (SendCapiEvent::handle orchestrates MetaClient + PayloadBuilder + UserDataHasher; SpyMetaClient replaces MetaClient in hook unit tests)
  - 02-07 (FakeAdapterContractTest exercises round-trip via PayloadBuilder + UserDataHasher)
  - phase 03 (ShopaholicOrderAdapter + ThemeActionAdapter provide getUserData that UserDataHasher hashes; per-event ValueResolvers feed PayloadBuilder)

tech-stack:
  added:
    - "guzzlehttp/guzzle ^7.8 — declared in plugin composer.json require: (NOT require-dev). MetaClient imports GuzzleHttp\\Client + ClientInterface + ConnectException."
  patterns:
    - "Per-call credentials HTTP boundary — MetaClient::sendForPixel takes (string \\$sPixelId, string \\$sToken, array \\$arPayload); never reads Settings singleton inside. Caller resolves via Settings::lookupForSite(\\$iSiteId) at SendCapiEvent::handle entry. Multi-pixel routing works at queue dispatch time."
  - "Graph API version pinned by constant — `MetaClient::META_GRAPH_API_VERSION = 'v23.0'`. v20 expires 2026-09-24. No operator override (D-18 lock). Upgrade is a coordinated plugin release."
  - "access_token in POST body NEVER URL — Meta accepts both forms; body avoids webserver-access-log token leaks (T-02-05-04 mitigation). Verified by url_contains_graph_version_and_pixel_id_and_token_lives_in_body test asserting access_token=TOKEN-XYZ does NOT appear in the request URL."
  - "PayloadBuilder subject-agnostic + event-name-agnostic — adapter + resolver + per-call \\$arEventExtras carry everything that varies. Body has zero `switch ($sEventName)` / `match ($sEventName)` / `$sEventName === ...` / `in_array($sEventName, ...)`. Future events (AddToCart, Lead, ViewContent) ship by authoring a new adapter, not by editing the builder."
  - "UserDataHasher stateless per M-4 — no \\$arMemo property, no reset() method. Phase 2 has no caller that hashes the same subject twice in one request (SendCapiEvent calls hasher exactly ONCE per dispatch via PayloadBuilder); memo deferred to Phase 3 ThemeEventCollector if a real cross-event repeat surfaces. ~45 LOC."
  - "Empty/null user_data field stays null — UserDataHasher::hashField rejects '' before hashing. Hashing empty string produces e3b0c44298fc1c149... which would collide across unrelated senders; explicit null tells Meta 'value not provided' (correct semantics)."
  - "json_decode mixed-return narrowing — `MetaClient::decodeBody(string): array<string, mixed>` private helper. PHPStan level 10 phpVersion 80300 cannot narrow `is_array(...)` ternary on json_decode's mixed to a typed key shape; explicit foreach + `(string) \\$mKey` cast satisfies the return.type identifier without @phpstan-ignore (CLAUDE.md project lock)."

key-files:
  created:
    - classes/meta/MetaClient.php
    - classes/meta/PayloadBuilder.php
    - classes/meta/UserDataHasher.php
    - tests/doubles/SpyMetaClient.php
    - tests/Unit/Meta/MetaClientTest.php
    - tests/Unit/Meta/PayloadBuilderTest.php
    - tests/Unit/Meta/UserDataHasherTest.php
  modified:
    - composer.json (guzzlehttp/guzzle ^7.8 added to require:)

key-decisions:
  - "MetaClient::decodeBody helper extracted to satisfy phpstan level 10 return.type. Original spec returned `is_array(\\$mDecoded) ? \\$mDecoded : []` inline; phpstan widens that to `array<mixed>` not the declared `array<string, mixed>`. Helper walks the decoded shape with `(string) \\$mKey` cast and explicit foreach assembly; covers the non-array-decode fallback branch with `test_non_json_body_decodes_to_empty_array_on_2xx` (defensive against a proxy/edge-cache mishap returning text/html on 2xx). Resolves the return.type identifier without @phpstan-ignore."
  - "PHPUnit 12 #[DataProvider(...)] attribute replaces @dataProvider annotation. RED-committed MetaClientTest used the legacy annotation; first GREEN run hit ArgumentCountError on every dataProvider case (PHPUnit 12 dropped annotation discovery). Fixed in the GREEN commit by importing `PHPUnit\\Framework\\Attributes\\DataProvider` and converting both transient + permanent dataProvider methods. Pattern carried forward for any future dataProvider tests."
  - "PayloadBuilder merge order: ValueResolver-derived custom_data FIRST, then \\$arEventExtras overlay via array_merge. An adapter can override content_type / currency / etc. for a specific event by passing the new value through \\$arEventExtras. Phase 3 ThemeActionAdapter ViewContent-on-CMS-article events will override content_type='product' (default) → 'article' through this overlay."
  - "Lowercase directory carry-over from 02-01 honored: production classes ship at `classes/meta/` (lowercase) with PascalCase namespace `Logingrupa\\Metapixel\\Classes\\Meta\\…`. PSR-4 test fixtures ship at `tests/doubles/SpyMetaClient.php` (lowercase). Non-namespaced test classes ship at `tests/Unit/Meta/` (PascalCase) — convention preserved per 02-03a + 02-04 decisions."
  - "ramsey/uuid NOT directly imported. Plan note in 02-05-PLAN.md called out that UUIDv4 generation lives in plan 02-06 SendCapiEvent::handle via Laravel's `Str::uuid()->toString()` (which wraps Ramsey UUID internally). MetaClient does not generate event_ids — it accepts a pre-formed envelope from PayloadBuilder."
  - "Three classes ship as three commits each (test RED + impl GREEN) for transparent TDD audit trail — preserves the Tiger-Style 'small commits, one concern per commit' rule. UserDataHasher: 3a27670 (RED) + 6851faa (GREEN). PayloadBuilder: 4c7be9b (RED) + eb7682e (GREEN). MetaClient: 64bc9fa (RED) + 5c4f664 (GREEN, also folds in the @dataProvider → #[DataProvider] auto-fix on the RED-committed test)."

patterns-established:
  - "Per-call credentials at HTTP boundary — D-19 anchor. Used by MetaClient::sendForPixel; consumed by SendCapiEvent::handle in plan 02-06; SpyMetaClient mirrors the same signature."
  - "H-9 PayloadBuilder grep gate combined regex — `! grep -E '\\\$sEventName\\s*(===|!==|==)|switch\\s*\\(\\s*\\\$sEventName|match\\s*\\(\\s*\\\$sEventName|in_array\\s*\\(\\s*\\\$sEventName' classes/meta/PayloadBuilder.php` catches all 6 event-name-comparison anti-patterns (===, !==, ==, switch, match, in_array). Run as the H-9 acceptance gate; passes on green build."
  - "Test class for PHPUnit 12 dataProvider — declare `public static function provideXxx(): array` + decorate the test method with `#[DataProvider('provideXxx')]` attribute. Import `PHPUnit\\Framework\\Attributes\\DataProvider`. Legacy `@dataProvider` annotation no longer discovered."
  - "Non-final test doubles — `tests/doubles/SpyMetaClient.php` is `class SpyMetaClient extends MetaClient` (NOT final). Plan 02-06 Task 2 T14 dead-letter test inline-subclasses to throw MetaApiPermanentException; production MetaClient stays final."

requirements-completed:
  - ADAP-07
  - ADAP-08
  - ADAP-09

duration: ~11 min
completed: 2026-05-17
---

# Phase 02 Plan 05: MetaClient + PayloadBuilder + UserDataHasher (ADAP-07/08/09) Summary

**Phase 2 Wave 3 backbone landed — MetaClient.sendForPixel takes per-call credentials and hits Meta Graph API v23.0 with the access_token in the POST body (NOT URL query) and classifies responses into the 4 Phase 1 exception classes; PayloadBuilder.buildEventPayload assembles the CAPI envelope subject-agnostic + event-name-agnostic (H-9 grep gate locks zero `switch/match/===/in_array` on `$sEventName`); UserDataHasher.forSubject hashes the 9 Meta-CAPI hashable fields with sha256(trim+lower) and pass-throughs the 4 raw fields, stateless per M-4 (~45 LOC); SpyMetaClient test double ships sibling to MetaClient (deferred from plan 02-01 Task 4); 23 new tests under tests/Unit/Meta/ all green; composer qa green — 80 tests / 192 assertions / 100.0 % coverage on all 18 in-scope production files. ADAP-07 + ADAP-08 + ADAP-09 closed.**

## Performance

- **Duration:** ~11 min (2026-05-17T22:08:?Z → 2026-05-17T22:19Z)
- **Tasks:** 7 (all auto-mode, no checkpoints)
- **Commits:** 9 (1 composer.json require + 3 RED tests + 3 GREEN impls + 1 SpyMetaClient + 1 QA-gate fix)
- **Files created:** 7 (3 production classes + 1 test double + 3 test files)
- **Files modified:** 1 (composer.json)
- **Test count delta:** +24 tests (56 → 80) / +58 assertions (134 → 192)

## Accomplishments

- Shipped `classes/meta/MetaClient.php` — 121 lines, `final class`, public `sendForPixel(string $sPixelId, string $sToken, array $arPayload): array`. Public const `META_GRAPH_API_VERSION = 'v23.0'` (D-18 — v20 expires 2026-09-24, no operator override). Private const `TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504]`. Constructor accepts optional `?ClientInterface $obClient` for test injection. Empty pixel_id → `MissingPixelConfigException`; empty token → `MissingCapiTokenException`; ConnectException → `MetaApiTransientException` with the original as previous + null http_status; 2xx → decoded body; 408/429/5xx → `MetaApiTransientException`; 4xx (other) → `MetaApiPermanentException`. URL shape `{base}/v23.0/{pixel}/events`; `access_token` merged into the JSON body via `'json' => array_merge($arPayload, ['access_token' => $sToken])` — never in the URL (T-02-05-04 webserver-log leak mitigation). `http_errors => false` so the classification logic owns response routing. Private `decodeBody(string): array<string, mixed>` helper resolves the phpstan return.type narrowing problem on json_decode's mixed return without `@phpstan-ignore`.
- Shipped `classes/meta/PayloadBuilder.php` — 59 lines, `final class`, public `buildEventPayload(string $sEventName, EventSubjectAdapter $obAdapter, object $obSubject, ValueResolver $obResolver, string $sEventId, int $iEventTime, array $arEventExtras): array`. Constructor injects `UserDataHasher` via readonly promoted property. Private const `ACTION_SOURCE = 'website'`. Body assembles user_data via hasher; builds custom_data dict from resolver (currency, value, num_items, contents, content_ids, content_type='product'); overlays `$arEventExtras` via array_merge (when non-empty); returns `['data' => [[event_id, event_time, event_name, action_source, user_data, custom_data]]]`. H-9 anchor — body has zero event-name comparisons of any form. Verified by combined grep gate `! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php` exiting 0.
- Shipped `classes/meta/UserDataHasher.php` — 49 lines, `final class`, public `forSubject(EventSubjectAdapter $obAdapter, object $obSubject): array<string, ?string>`. Private const `HASHABLE_FIELDS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id']` (9 fields) + `PASSTHROUGH_FIELDS = ['fbp', 'fbc', 'client_ip_address', 'client_user_agent']` (4 fields) — 13 keys returned total. Hashable fields trimmed + lowercased + sha256-ed; passthrough returned as-is. Null/empty input returns null — never the sha256 of empty string (would collide across unrelated senders per Meta CAPI hash collision semantics). **M-4 lock: stateless. No `$arMemo` property, no `reset()` method, no memo test branch.** Phase 3 ThemeEventCollector adds memo when a real cross-event repeat surfaces.
- Shipped `tests/doubles/SpyMetaClient.php` — 37 lines, `class SpyMetaClient extends MetaClient` (NOT final — plan 02-06 Task 2 T14 dead-letter test inline-subclasses to throw `MetaApiPermanentException`). Public `int $iCallCount`, `array $arLastPayload`, `string $sLastPixelId`, `string $sLastToken` — overridden `sendForPixel` records all three inputs and returns canned `['events_received' => 1]`. **H-6 deferral closed:** SpyMetaClient was deferred from plan 02-01 Task 4 because it extends MetaClient, which only landed in this Wave 3 plan.
- Shipped 3 unit test files under `tests/Unit/Meta/`:
  - `UserDataHasherTest.php` — 4 tests / 9 assertions: `email is sha256 lowercased trimmed`, `null and empty inputs return null not hash of empty`, `passthrough fields are not hashed`, `returns all thirteen documented keys`. **M-4 lock: NO memo/reset tests** (the plan-checker R1 originally listed 6 tests; 2 dropped because the hasher is stateless).
  - `PayloadBuilderTest.php` — 3 tests / 25 assertions: `envelope has six top level event keys`, `event extras merge into custom data` (overrides default `content_type='product'` → `'article'`), `envelope subject agnostic same adapter different events` (proves user_data + action_source + custom_data are identical across two event names; only event_name + event_id + event_time differ).
  - `MetaClientTest.php` — 16 tests / 23 assertions via Guzzle MockHandler matrix: 200 happy path, empty pixel_id → MissingPixelConfigException, empty token → MissingCapiTokenException, 6 transient status codes via `#[DataProvider('provideTransientStatusCodes')]`, 4 permanent status codes via `#[DataProvider('providePermanentStatusCodes')]`, ConnectException rewrap with previous-exception preservation, URL/body split assertion (graph version + pixel id in URL; access_token in body NOT URL), `META_GRAPH_API_VERSION` constant pinned to `'v23.0'`, and the non-JSON 2xx decode fallback (defensive against proxy/edge-cache mishaps).
- composer.json `require:` block now declares `"guzzlehttp/guzzle": "^7.8"` (alphabetical). H-4 lock honored — no plugin-dir `composer update`; the marketplace standalone-install case downloads Guzzle when the operator runs `composer install` on the plugin; in this repo Guzzle is already a transitive Laravel/October dependency so the explicit require pins it for the third-party case.
- composer qa green end-to-end (host-vendor smoke from plugin dir): `pint --test` passed; phpstan level 10 phpVersion 80300 `[OK] No errors`; `phpmd Plugin.php,classes,models` exit 0; `pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90` → **80 tests / 192 assertions / 100.0 % coverage on all 18 in-scope production files**.

## Task Commits

| Task | Description | Commit | Type |
|------|-------------|--------|------|
| 1 | Declare guzzlehttp/guzzle ^7.8 in plugin composer require | `e007f65` | feat |
| 2 RED | UserDataHasher contract (sha256 spec + 13 keys) | `3a27670` | test |
| 2 GREEN | UserDataHasher hashes Meta CAPI user_data (ADAP-08) | `6851faa` | feat |
| 3 RED | PayloadBuilder envelope contract (subject-agnostic) | `4c7be9b` | test |
| 3 GREEN | PayloadBuilder assembles event envelope (ADAP-07) | `eb7682e` | feat |
| 4 RED | MetaClient HTTP contract via Guzzle MockHandler | `64bc9fa` | test |
| 4 GREEN | MetaClient Graph API v23.0 client per-call creds (ADAP-09; + @dataProvider → #[DataProvider] fix) | `5c4f664` | feat |
| 6 | SpyMetaClient double for hook + queue tests (H-6 deferred) | `3b4e886` | test |
| 7 | composer qa green — MetaClient phpstan + non-JSON fallback test | `7f7185a` | fix |

`docs(02-05)` metadata commit ships separately with this SUMMARY.md + STATE.md + ROADMAP.md + REQUIREMENTS.md.

## Files Created/Modified

### Created (7)

- `classes/meta/MetaClient.php` — 121 lines; final class; Graph API v23.0 client; per-call credentials; 4 exception throw branches; decodeBody helper.
- `classes/meta/PayloadBuilder.php` — 59 lines; final class; UserDataHasher injected; subject-agnostic envelope assembler; ACTION_SOURCE='website'.
- `classes/meta/UserDataHasher.php` — 49 lines; final class; sha256 hasher; 9 hashable + 4 passthrough fields; stateless (M-4).
- `tests/doubles/SpyMetaClient.php` — 37 lines; class extends MetaClient (NOT final); records call inputs for plans 02-06 + 02-07 unit tests.
- `tests/Unit/Meta/UserDataHasherTest.php` — 68 lines; 4 tests / 9 assertions.
- `tests/Unit/Meta/PayloadBuilderTest.php` — 98 lines; 3 tests / 25 assertions.
- `tests/Unit/Meta/MetaClientTest.php` — 167 lines; 16 tests / 23 assertions (3 single + 6 transient dataProvider + 4 permanent dataProvider + connect rewrap + url/body + version constant + non-JSON fallback).

### Modified (1)

- `composer.json` — added `"guzzlehttp/guzzle": "^7.8"` to `require:` (alphabetical; H-4 declarative — operator runs composer update from project root for marketplace standalone-install).

## Decisions Made

- **MetaClient::decodeBody private helper for phpstan level 10 narrowing.** Original spec returned `is_array($mDecoded) ? $mDecoded : []` inline. PHPStan widened the result to `array<mixed>` not the method's declared `array<string, mixed>` (`return.type` identifier). Extracted the body-decode + key-narrowing into `decodeBody(string): array<string, mixed>` that walks the decoded shape and casts each key to string. Resolves the identifier without `@phpstan-ignore` (CLAUDE.md project lock forbids the suppression). Tested both branches — happy path via `test_send_for_pixel_returns_decoded_array_on_200`, fail-safe non-array branch via `test_non_json_body_decodes_to_empty_array_on_2xx` (defensive against proxy/edge-cache mishaps).
- **PHPUnit 12 dataProvider attribute conversion.** PHPUnit 12 dropped `@dataProvider` annotation discovery. The Task 5 RED commit declared `public static function provideXxx()` methods + `@dataProvider provideXxx` annotations; first GREEN run hit `ArgumentCountError` on every dataProvider case. Fixed in the GREEN commit by importing `PHPUnit\Framework\Attributes\DataProvider` and decorating each test method with `#[DataProvider('provideXxx')]`. Pattern carried forward for any future dataProvider tests.
- **PayloadBuilder merge order: resolver-derived custom_data first, then $arEventExtras overlay.** `array_merge` second-arg-wins semantics → an adapter can override `content_type` / `currency` / etc. for a specific event by passing the new value through `$arEventExtras`. Phase 3 ThemeActionAdapter ViewContent-on-CMS-article events will override `content_type='product'` (default) → `'article'` through this overlay. Test `test_event_extras_merge_into_custom_data` proves it.
- **UserDataHasher stateless lock (M-4).** Plan-checker R1 originally listed `$arMemo` property + `reset()` method + 2 memo test methods. Phase 2 has no caller that hashes the same subject twice in one request (SendCapiEvent calls hasher exactly ONCE per dispatch via PayloadBuilder; each ThemeActionEvent has its own synthetic_id). Per CLAUDE.md "Build only for current need" the memo is deferred to Phase 3 ThemeEventCollector if a real cross-event repeat surfaces. Saves ~15 LOC + 2 test methods + 1 unnecessary mental model for downstream readers.
- **access_token in POST body NOT URL.** Meta accepts both forms but the URL form leaks tokens via webserver access logs. `test_url_contains_graph_version_and_pixel_id_and_token_lives_in_body` asserts both invariants: URL contains `/v23.0/PIXEL-42/events`, URL does NOT contain `access_token=` or the literal token; body JSON has `access_token` key set to the token. This is the persistent T-02-05-04 mitigation.
- **Lowercase production directories preserved.** `classes/meta/` lowercase per the 02-01 carry-over. Namespaces stay PascalCase (`Logingrupa\Metapixel\Classes\Meta\…`) — PHP is case-insensitive on namespace resolution; only filesystem paths matter to October Rain's ClassLoader. SpyMetaClient ships at lowercase `tests/doubles/` (existing dir from 02-01); the 3 test files ship at PascalCase `tests/Unit/Meta/` (existing test-class convention).
- **Three TDD commit pairs preserved for audit transparency.** UserDataHasher: 3a27670 (RED) + 6851faa (GREEN). PayloadBuilder: 4c7be9b (RED) + eb7682e (GREEN). MetaClient: 64bc9fa (RED) + 5c4f664 (GREEN — also folds the @dataProvider → #[DataProvider] Rule 1 fix on the RED-committed test). Single-file commits preserve the Tiger-Style "one concern per commit" rule and let downstream readers see the contract pin in isolation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] PHPUnit 12 dropped `@dataProvider` annotation discovery**

- **Found during:** Task 4 first GREEN run (RED committed earlier with the annotation form per the plan's <action> spec).
- **Issue:** Both `test_throws_transient_on_status(int $iStatus)` and `test_throws_permanent_on_status(int $iStatus)` failed with `ArgumentCountError: Too few arguments to function …, 0 passed … and exactly 1 expected` on every dataProvider case. PHPUnit 12 (vendor/pest = 4.7.0 → phpunit 12.x) dropped the legacy `@dataProvider` annotation and now requires the `#[DataProvider(...)]` attribute.
- **Fix:** Imported `PHPUnit\Framework\Attributes\DataProvider`; replaced both `/** @dataProvider provideXxxStatusCodes */` blocks with `#[DataProvider('provideXxxStatusCodes')]` attribute decorations.
- **Files modified:** `tests/Unit/Meta/MetaClientTest.php` (3 line replacements).
- **Verification:** All 16 MetaClientTest methods pass (3 single + 6 transient + 4 permanent + 3 misc).
- **Committed in:** `5c4f664` (folded into Task 4 GREEN commit — the RED test was contract-committed minutes earlier; the GREEN commit folds in the annotation → attribute fix alongside the production code).
- **Rationale:** The plan's <action> example used the annotation form; that's an outlier that surfaces only on PHPUnit 12. Pattern carried forward in the SUMMARY for future test files.

**2. [Rule 1 — Bug] PHPStan level 10 cannot narrow `is_array()` ternary on `json_decode`'s `mixed` to `array<string, mixed>`**

- **Found during:** Task 7 `composer qa` smoke run.
- **Issue:** `classes/meta/MetaClient.php:85` reported `return.type` identifier: "Method … sendForPixel() should return array<string, mixed> but returns array<mixed>." The naive `is_array($mDecoded) ? $mDecoded : []` ternary widens to `array` not the typed key shape, and CLAUDE.md project lock forbids `@phpstan-ignore` suppression.
- **Fix:** Extracted body-decode + key-narrowing into private `decodeBody(string $sBody): array<string, mixed>` helper. Walks the decoded shape with `foreach` and casts each key to `(string)` for the assembled result. Production code path unchanged on the happy 2xx case (decoded keys are already strings — Meta's response has named keys); the cast is defensive on the fail-safe non-array branch.
- **Files modified:** `classes/meta/MetaClient.php` (extracted helper + replaced inline decode).
- **Verification:** `phpstan analyse` reports `[OK] No errors`; pest still green; coverage now 100% (was 97.9% on MetaClient before the helper coverage test landed).
- **Committed in:** `7f7185a`.
- **Rationale:** CLAUDE.md project lock forbids `@phpstan-ignore`. The helper is 15 LOC and produces strictly-typed output. Same pattern carries forward for any future code that decodes external JSON at phpstan level 10.

**3. [Rule 2 — Missing critical functionality] decodeBody non-array-decode branch was uncovered**

- **Found during:** Task 7 `pest --coverage` first run after Task 7 part 1 landed — `classes/meta/MetaClient` reported 97.9% (line 111, the `if (! is_array($mDecoded)) { return []; }` fail-safe branch, was uncovered). Total coverage 99.4%.
- **Issue:** The fail-safe branch only fires when Meta upstream returns a non-JSON body on a 2xx response (proxy/edge-cache mishap, Cloudflare HTML error page wrapped in 200, etc.). The Phase 2 plan's expected coverage was `≥ 95%` on MetaClient — 97.9% passes — but the plan's `<success_criteria>` calls for `≥ 90%` plus per-class `≥ 95%`; the gate was technically green but the fail-safe path had no coverage gate against future refactor.
- **Fix:** Added `test_non_json_body_decodes_to_empty_array_on_2xx` — MockHandler returns `Response(200, [], 'not-json-at-all')`; assert that `sendForPixel` returns `[]` (empty array). Closes the coverage gap.
- **Files modified:** `tests/Unit/Meta/MetaClientTest.php` (1 test added).
- **Verification:** Coverage now `classes/meta/MetaClient` 100.0%, total 100.0%.
- **Committed in:** `7f7185a` (folded into the same QA-gate commit as the phpstan helper extraction).
- **Rationale:** Fail-safe branches without test coverage are real risk — a future refactor could silently break the non-array handling without a test gate to catch it. Same pattern carried forward from plan 02-04's Throwable-branch test addition.

**4. [Rule 3 — Block fix] pint `fully_qualified_strict_types` fixer on inline `@var RequestInterface` docblock**

- **Found during:** Task 7 `pint --test` smoke run.
- **Issue:** `tests/Unit/Meta/MetaClientTest.php:133` had `/** @var \Psr\Http\Message\RequestInterface $obRequest */` (FQN inside docblock). Pint's `fully_qualified_strict_types` rule rewrites docblock FQNs to short names with a top-level `use` import.
- **Fix:** Ran `pint tests/Unit/Meta/MetaClientTest.php` autofix. Added `use Psr\Http\Message\RequestInterface;` (alphabetical with the other use-block entries); docblock now reads `/** @var RequestInterface $obRequest */`.
- **Files modified:** `tests/Unit/Meta/MetaClientTest.php` (1 use added + 1 docblock changed).
- **Verification:** `pint --test` returns `result: passed`.
- **Committed in:** `7f7185a`.
- **Rationale:** Pint is the project's source-of-truth formatter (composer qa step 1). Auto-fix is the correct response.

---

**Total deviations:** 4 auto-fixed (Rule 1 × 2, Rule 2 × 1, Rule 3 × 1)
**Impact on plan:** All auto-fixes match documented expectations:
- Rule 1 PHPUnit 12 dataProvider — plan's <action> example was an outlier; SUMMARY carries forward the pattern.
- Rule 1 phpstan narrowing — anticipated at plan level (Task 7 action block mentioned `json_decode` mixed-return narrowing); solution didn't introduce a `@phpstan-ignore` per CLAUDE.md lock.
- Rule 2 coverage gap — matches plan 02-04's Throwable-branch test addition pattern.
- Rule 3 pint autofix — same flow as plan 02-04 deviation 3 (pint autofix from previous run carry-over).

No scope creep — every fix is inside the plan's stated artifact set.

## Issues Encountered

- **Plugin standalone-composer-install limitation persists** (carry-forward from Phase 1 + every Phase 2 plan). `composer qa` from inside `plugins/logingrupa/metapixel/` exits 127 because plugin-local `vendor/bin/` does not exist. Workaround: host-vendor binaries at `/home/forge/nailscosmetics.lv/vendor/bin/{pint,phpstan,phpmd,pest}` + smoke phpstan config at `/tmp/metapixel-phpstan-smoke.neon` (absolute paths). Same as prior Phase 2 plans.
- **`ramsey/uuid` NOT added to plugin composer require.** Plan 02-05-PLAN.md `<interfaces>` block called out that Phase 1 confirmed Laravel's `Str::uuid()` already wraps Ramsey UUID; plan 02-06 SendCapiEvent::handle will call `Str::uuid()->toString()` without a direct Ramsey import. No action needed in this plan.

## Self-Check: PASSED

- All 7 created files exist on disk under `plugins/logingrupa/metapixel/`:
  - `classes/meta/MetaClient.php` — FOUND.
  - `classes/meta/PayloadBuilder.php` — FOUND.
  - `classes/meta/UserDataHasher.php` — FOUND.
  - `tests/doubles/SpyMetaClient.php` — FOUND.
  - `tests/Unit/Meta/MetaClientTest.php` — FOUND.
  - `tests/Unit/Meta/PayloadBuilderTest.php` — FOUND.
  - `tests/Unit/Meta/UserDataHasherTest.php` — FOUND.
- All 9 commit hashes present in `git log --oneline`:
  - `e007f65` (feat: composer guzzle require) — FOUND.
  - `3a27670` (test: UserDataHasher RED) — FOUND.
  - `6851faa` (feat: UserDataHasher GREEN) — FOUND.
  - `4c7be9b` (test: PayloadBuilder RED) — FOUND.
  - `eb7682e` (feat: PayloadBuilder GREEN) — FOUND.
  - `64bc9fa` (test: MetaClient RED) — FOUND.
  - `5c4f664` (feat: MetaClient GREEN) — FOUND.
  - `3b4e886` (test: SpyMetaClient) — FOUND.
  - `7f7185a` (fix: composer qa green) — FOUND.
- `vendor/bin/pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90` exits 0 from plugin dir with **80 tests / 192 assertions / 100.0% coverage on all 18 in-scope production files**.
- `vendor/bin/pint --test Plugin.php classes models tests` exits 0 (`result: passed`).
- `vendor/bin/phpstan analyse --configuration /tmp/metapixel-phpstan-smoke.neon` reports `[OK] No errors` (level 10, phpVersion 80300).
- `vendor/bin/phpmd Plugin.php,classes,models text phpmd.xml` exits 0.
- H-9 grep gate `! grep -nE 'switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|\$sEventName\s*===|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php` exits 0.
- `classes/meta/MetaClient.php` contains the literal string `META_GRAPH_API_VERSION = 'v23.0'`.
- No phase markers (`// CR-N`, `// Phase N`, `// Plan N`, `// P-0N`) in any new source file.
- composer.json `require:` contains `"guzzlehttp/guzzle": "^7.8"`.

## composer qa tail (host-vendor smoke run from `plugins/logingrupa/metapixel/`)

```
=== 1/4 pint-test (host vendor) ===
{"tool":"pint","result":"passed"}

=== 2/4 phpstan analyse (host vendor, level 10, phpVersion 80300) ===
 [OK] No errors

=== 3/4 phpmd Plugin.php,classes,models ===
phpmd exit=0

=== 4/4 pest --testsuite='Metapixel Unit Tests,Metapixel Feature Tests' --coverage --min=90 ===
  Tests:    80 passed (192 assertions)
  Duration: 2.31s

  Plugin .............................................................. 100.0%
  classes/adapter/AdapterRegistry ..................................... 100.0%
  classes/adapter/EventSubjectAdapter ................................. 100.0%
  classes/adapter/ValueResolver ....................................... 100.0%
  classes/exception/MetaApiPermanentException ......................... 100.0%
  classes/exception/MetaApiTransientException ......................... 100.0%
  classes/exception/MetaPixelException ................................ 100.0%
  classes/exception/MissingCapiTokenException ......................... 100.0%
  classes/exception/MissingPixelConfigException ....................... 100.0%
  classes/helper/EventLogWriter ....................................... 100.0%
  classes/helper/PluginGuard .......................................... 100.0%
  classes/helper/SiteResolver ......................................... 100.0%
  classes/meta/MetaClient ............................................. 100.0%
  classes/meta/PayloadBuilder ......................................... 100.0%
  classes/meta/UserDataHasher ......................................... 100.0%
  models/EventLog ..................................................... 100.0%
  models/FailedEvent .................................................. 100.0%
  models/Settings ..................................................... 100.0%
  ────────────────────────────────────────────────────────────────────────────
                                                                Total: 100.0 %
```

Full QA log: `/tmp/02-05-qa.log`.

## Test method names (pest output for tests/Unit/Meta)

| # | Test class | Test method | Status |
|---|---|---|---|
| T8 | PayloadBuilderTest | test_envelope_has_six_top_level_event_keys | PASS |
| T8 | PayloadBuilderTest | test_event_extras_merge_into_custom_data | PASS |
| T8 | PayloadBuilderTest | test_envelope_subject_agnostic_same_adapter_different_events | PASS |
| T9 | UserDataHasherTest | test_email_is_sha256_lowercased_trimmed | PASS |
| T9 | UserDataHasherTest | test_null_and_empty_inputs_return_null_not_hash_of_empty | PASS |
| T9 | UserDataHasherTest | test_passthrough_fields_are_not_hashed | PASS |
| T9 | UserDataHasherTest | test_returns_all_thirteen_documented_keys | PASS |
| T10 | MetaClientTest | test_send_for_pixel_returns_decoded_array_on_200 | PASS |
| T10 | MetaClientTest | test_throws_missing_pixel_config_on_empty_pixel_id | PASS |
| T10 | MetaClientTest | test_throws_missing_capi_token_on_empty_token | PASS |
| T10 | MetaClientTest | test_throws_transient_on_status (×6 dataProvider 408/429/500/502/503/504) | PASS ×6 |
| T10 | MetaClientTest | test_throws_permanent_on_status (×4 dataProvider 400/401/403/404) | PASS ×4 |
| T10 | MetaClientTest | test_connect_exception_rewrapped_as_transient | PASS |
| T10 | MetaClientTest | test_url_contains_graph_version_and_pixel_id_and_token_lives_in_body | PASS |
| T10 | MetaClientTest | test_meta_graph_api_version_constant_pinned_to_v23 | PASS |
| T10 | MetaClientTest | test_non_json_body_decodes_to_empty_array_on_2xx | PASS |

**23 test methods / 57 assertions in tests/Unit/Meta** (3 PayloadBuilder + 4 UserDataHasher + 16 MetaClient including the 10 dataProvider cases). Combined with the 56 baseline tests from plans 02-01..02-04 + 1 added in Task 7 = **80 total passing tests / 192 assertions**.

## Coverage per class (from plugin dir)

| File | Coverage | Notes |
|---|---|---|
| classes/meta/MetaClient.php | 100.0 % | 6 status-code branches + ConnectException + 2 throws + decodeBody happy/fail-safe |
| classes/meta/PayloadBuilder.php | 100.0 % | 2 branches (extras empty vs non-empty) |
| classes/meta/UserDataHasher.php | 100.0 % | 3 branches (hashable hit, hashable null/empty, passthrough hit) — no memo branch (M-4) |

Plan expected `MetaClient ≥ 95%`, `PayloadBuilder 100%`, `UserDataHasher ≥ 95%`. All three exceeded; Total coverage on all 18 in-scope files = 100.0 %.

## H-9 grep gate result

```
$ grep -nE 'switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|\$sEventName\s*===|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php
(no output — exit 0)
```

PayloadBuilder source has zero event-name comparisons. Future events (AddToCart, Lead, ViewContent) ship by authoring a new adapter, NEVER by editing the builder.

## H-4 Guzzle install confirmation

- Plugin `composer.json` `require:` declares `"guzzlehttp/guzzle": "^7.8"` (alphabetical with `lovata/toolbox-plugin`, `october/system`, `php`).
- `composer validate --no-check-publish` → "./composer.json is valid".
- `php -r 'require_once "../../../vendor/autoload.php"; class_exists("GuzzleHttp\\Client") || exit(1); echo "ok\n";'` → "ok" (Guzzle autoloads from project root vendor).
- DID NOT run `composer update` from plugin dir (H-4 lock). Operator runs `composer update logingrupa/oc-metapixel-plugin --with-dependencies --no-interaction` from project root (`/home/forge/nailscosmetics.lv/`) to refresh the project lockfile. In this repo Guzzle is already a transitive dep of Laravel 12 / October 4; the explicit require pins it for the marketplace standalone-install case.

## H-6 SpyMetaClient confirmation

- File ships at `tests/doubles/SpyMetaClient.php` (lowercase dir, alongside the 6 doubles from plan 02-01).
- `class SpyMetaClient extends MetaClient` (NOT `final` — plan 02-06 Task 2 T14 dead-letter test inline-subclasses to throw `MetaApiPermanentException`).
- Public `int $iCallCount`, `array $arLastPayload`, `string $sLastPixelId`, `string $sLastToken` properties record every `sendForPixel` invocation.
- Plan 02-06 + 02-07 hook unit tests can now import by FQN: `use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;`.

## Graph API URL verification snippet (T10)

From `test_url_contains_graph_version_and_pixel_id_and_token_lives_in_body`:

```php
$this->assertStringContainsString('/v23.0/PIXEL-42/events', $sUrl);
$this->assertStringNotContainsString('access_token=', $sUrl);
$this->assertStringNotContainsString('TOKEN-XYZ', $sUrl);

$arBody = json_decode($sBody, associative: true);
$this->assertSame('TOKEN-XYZ', $arBody['access_token']);
```

Confirms (a) URL is the Graph API v23.0 events endpoint for pixel `PIXEL-42`, (b) the access token does NOT appear in the URL (T-02-05-04 webserver-log leak mitigation), (c) the access token IS in the POST body.

## Phase 2 plan-state update

Plan **02-05 CLOSED**. ADAP-07 + ADAP-08 + ADAP-09 closed.

- **02-06 (SendCapiEvent + ModelHandlers + event hooks)** — UNBLOCKED (sequential next on master per orchestrator prompt). SendCapiEvent::handle orchestrates `MetaClient::sendForPixel` + `PayloadBuilder::buildEventPayload` + `UserDataHasher::forSubject` + `EventLogWriter::record` + `SiteResolver::forSubject`. SpyMetaClient replaces MetaClient in hook unit tests for race-fence + listener behavior assertions.
- **02-07 (FakeAdapterContractTest + ContractTestCase)** — UNBLOCKED transitively. ContractTestCase exercises round-trip via PayloadBuilder + UserDataHasher; SpyMetaClient replaces production MetaClient in the smoke test.
- Phase 3 unblocked once 02-06 + 02-07 close.

## Threat Flags

(none — MetaClient + PayloadBuilder + UserDataHasher ship without introducing new schema changes or auth paths beyond what's already documented in the plan's STRIDE register T-02-05-01 through T-02-05-06. T-02-05-04 mitigation (access_token in body NOT URL) is explicitly asserted by the test suite. T-02-05-06 mitigation (UserDataHasher stateless — no memo property to leak via reflection) is anchored by the M-4 lock.)

## Next Phase Readiness

- Plan **02-06 (SendCapiEvent + ModelHandlers + Event::fire hooks)** is the next sequential plan on master. Touches `classes/queue/SendCapiEvent.php` + `classes/event/*ModelHandler.php` + `Plugin::boot()` registration + test counterparts. No file overlap with this plan.
- `MetaClient::sendForPixel` is the single Graph API HTTP boundary; SpyMetaClient is the test surface for race-fence + listener-isolation assertions.
- `PayloadBuilder::buildEventPayload` is the subject-agnostic envelope assembler; H-9 grep gate documented + tested.
- `UserDataHasher::forSubject` is the sha256 boundary; stateless per M-4 — Phase 3 may add memo when ThemeEventCollector reveals a real cross-event repeat.
- Guzzle declared in plugin `require:` for marketplace standalone-install.
- Coverage gate locked at 100.0 % across all 18 in-scope production files.

---

*Phase: 02-adapter-system-core-contracts-registry-extension-hooks*
*Plan: 5*
*Completed: 2026-05-17*
