---
phase: quick-260619-osv
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - classes/meta/FbqScriptBuilder.php
  - classes/meta/OfferSwitchResult.php
  - components/PixelHead.php
  - classes/event/adapter/shopaholic/ProductPageWatcher.php
  - classes/adapter/theme/ThemeAjaxHandler.php
  - tests/Unit/Meta/FbqScriptBuilderTest.php
  - tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
  - tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php
autonomous: true
requirements: [VIEW-04, VIEW-07, VIEW-09]
must_haves:
  truths:
    - "All three fbq-track-block render sites build through one shared FbqScriptBuilder"
    - "PixelHead deferred-flush output is byte-identical to current (production + test-code branches)"
    - "Offer-switch AJAX response script carries content_ids SKU-{pid}-{oid}, value, test_event_code (when set), and eventID"
    - "Generic-theme AJAX branch script carries test_event_code + eventID with {} custom_data"
    - "ProductPageWatcher::dispatchForOfferSwitch returns event_id AND the browser-facing custom_data it already assembles"
    - "AJAX handler does NOT re-derive offer-switch ViewContent custom_data — watcher owns it"
  artifacts:
    - path: "classes/meta/FbqScriptBuilder.php"
      provides: "Single shared fbq track-block string builder (final class, static method)"
      contains: "final class FbqScriptBuilder"
    - path: "classes/meta/OfferSwitchResult.php"
      provides: "Readonly value object carrying event_id + custom_data from dispatchForOfferSwitch"
      contains: "readonly"
    - path: "tests/Unit/Meta/FbqScriptBuilderTest.php"
      provides: "Unit coverage for all builder branches + byte-identical-to-old assertion"
  key_links:
    - from: "components/PixelHead.php"
      to: "classes/meta/FbqScriptBuilder.php"
      via: "buildFbqOptionsObject delegates to shared builder"
      pattern: "FbqScriptBuilder::"
    - from: "classes/adapter/theme/ThemeAjaxHandler.php"
      to: "classes/meta/FbqScriptBuilder.php"
      via: "both script-build sites route through shared builder"
      pattern: "FbqScriptBuilder::"
    - from: "classes/adapter/theme/ThemeAjaxHandler.php"
      to: "classes/event/adapter/shopaholic/ProductPageWatcher.php"
      via: "dispatchForOfferSwitch returns OfferSwitchResult consumed for script custom_data"
      pattern: "dispatchForOfferSwitch"
---

<objective>
Offer-switch ViewContent completion — finish the half-built offer-switch path and collapse three duplicate fbq-track-block render sites into one shared builder (DRY + SRP).

Today the offer-switch AJAX product branch returns a contentless browser script (`fbq("track","ViewContent",{},{eventID:X})`) while the server CAPI mirror carries full content_ids `SKU-{pid}-{oid}` + value + currency. The browser event is therefore content-blind and also lacks `test_event_code`, so it never appears in Meta's Test Events tab and cannot be content-attributed. Three sprintf sites build fbq blocks independently; only PixelHead injects test_event_code.

Purpose: Browser offer-switch ViewContent must mirror the CAPI payload (content_ids + value + test_event_code) and dedup by eventID, with one source of truth for fbq-block assembly.

Output:
- `classes/meta/FbqScriptBuilder.php` — one shared track-block builder.
- `classes/meta/OfferSwitchResult.php` — readonly value object (event_id + custom_data).
- PixelHead, ProductPageWatcher, ThemeAjaxHandler all wired through the shared builder.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/components/PixelHead.php
@plugins/logingrupa/metapixel/classes/adapter/theme/ThemeAjaxHandler.php
@plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/ProductPageWatcher.php
@plugins/logingrupa/metapixel/classes/meta/PayloadBuilder.php
@plugins/logingrupa/metapixel/tests/Feature/Components/PixelHeadDeferredFlushTest.php
@plugins/logingrupa/metapixel/tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php
@plugins/logingrupa/metapixel/tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php

## Phase 6 SUMMARY (worktree sync pattern)
@plugins/logingrupa/metapixel/.planning/phases/06-viewcontent-funnel-shopaholic-pdp/06-02-SUMMARY.md

## Worktree caveat (READ before running pest/phpstan)
Composer PSR-4 binds `Logingrupa\Metapixel\` to the LIVE plugin dir
(`/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/`), NOT the worktree.
To exercise edited code under pest/phpstan you MUST sync the edited files into the
live plugin dir (exclude `.planning/`, `.claude/`, `.git/`), run the tools, then
RESTORE the live dir before committing in the worktree. Pattern documented in
`06-02-SUMMARY.md` "Worktree sync".

## Vendor binaries (run from project root)
- pest:     `/home/forge/nailscosmetics.lv/vendor/bin/pest`
- phpstan:  `/home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --memory-limit=512M` (level 10, plugin-root `phpstan.neon`)

## Locked decisions (do NOT break)
- event_id direction = server → frontend only, UUIDv4. Never reverse.
- offer-switch action_key canonical `viewcontent:{pid}:{oid}:{eid}` owned by `dispatchForOfferSwitch`.
- content_ids format = `SKU-{product_id}[-{offer_id}]`.
- PayloadBuilder content_type/content_ids gating from quick 260616-wcp stays intact (do NOT touch PayloadBuilder).
- JS-encode flags: `JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS` (== PixelHead `self::JS`).
- fbq 4th-arg key order in the eventID branch: `eventID` first, then `test_event_code` (matches current `buildFbqOptionsObject`).

## Plugin code style (CLAUDE.md)
Hungarian locals (`$ob`, `$ar`, `$i`, `$s`, `$f`, `$b`); no Phase-N / CR-XX / Plan-N markers in source;
no `assert()`; no `@phpstan-ignore`; Laravel short docblocks; dual PHP 8.3/8.4 — no property hooks,
no asymmetric visibility, no `array_find/any/all/find_key`, no `#[\Deprecated]`. `readonly` properties OK (8.1+).
Tiger-Style: throw at boundaries; every `catch` logs + has a reason. Do NOT touch theme files.
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Extract FbqScriptBuilder + wire PixelHead through it</name>
  <files>classes/meta/FbqScriptBuilder.php, components/PixelHead.php, tests/Unit/Meta/FbqScriptBuilderTest.php</files>
  <behavior>
    FbqScriptBuilder unit tests (tests/Unit/Meta/FbqScriptBuilderTest.php), all branches:
    - eventID + test_event_code → `<script>fbq("track", "ViewContent", {"...":...}, {eventID: "X", test_event_code: "TEST123"});</script>` (eventID first, test_event_code second).
    - eventID only (no test code) → `{eventID: "X"}` 4th arg.
    - test_event_code only, no eventID → `{test_event_code: "TEST123"}` 4th arg, no `eventID` substring.
    - neither eventID nor test code → 3-arg call `<script>fbq("track", NAME, DATA);</script>`, no 4th-arg object.
    - JS-encode flags identical to PixelHead self::JS (JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_APOS): assert a name containing `<`/`"`/`&`/`'` is hex-escaped.
    - Byte-identical guard: for an event {name, custom_data, event_id} with NO test code, the builder output equals the legacy sprintf+buildFbqOptionsObject output string for the same inputs (assert exact string).
  </behavior>
  <action>Create `classes/meta/FbqScriptBuilder.php` — `final class FbqScriptBuilder` in namespace `Logingrupa\Metapixel\Classes\Meta`. Public const `JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS` (single source for the encode flags; PixelHead's private `self::JS` keeps its value but the builder owns the canonical const). Expose one static method `build(string $sEventName, array $arCustomData, ?string $sEventId, ?string $sTestEventCode): string` returning the full `<script>fbq("track", NAME, DATA[, OPTS]);</script>` line. Internally: json_encode name + custom_data with `self::JS`; assemble the 4th-arg options object via a private static helper that mirrors current `buildFbqOptionsObject` semantics — push `eventID: <json($sEventId)>` when `$sEventId` is a non-empty string, then push `test_event_code: <json($sTestEventCode)>` when `$sTestEventCode` is a non-empty string; if no pairs, emit the 3-arg sprintf form, else the 4-arg form. CALLER passes test_event_code (builder is pure string-assembly, reads NO Settings — SRP/testability). Hungarian locals throughout; Laravel short docblocks.

Then refactor `components/PixelHead.php`: in `flushDeferredFromController`, replace the inline `buildFbqOptionsObject` + dual sprintf branches (current L218-235) with a single `FbqScriptBuilder::build($sName, $arCustomData, $mEventId-as-?string, $sTestCode-as-?string)` call. Resolve `$sTestCode` once as today (`Settings::get('test_event_code','')`, is_string guard) and pass the raw string (empty → builder treats as absent); convert `$mEventId` to `?string` at the call site (is_string && !== '' ? value : null). DELETE the now-unused private `buildFbqOptionsObject` method. Keep `self::JS` const on PixelHead only if still referenced elsewhere in the file; otherwise leave it (no behavior change required outside the flush path — do NOT touch emitBasePixel). Add `use Logingrupa\Metapixel\Classes\Meta\FbqScriptBuilder;`. Production output (no test code) MUST stay byte-identical to current for the eventID and no-eventID branches.</action>
  <verify>
    <automated>cd /home/forge/nailscosmetics.lv && rsync -a --exclude='.planning' --exclude='.claude' --exclude='.git' plugins/logingrupa/metapixel/ /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel.live-bak-check/ 2>/dev/null; vendor/bin/phpstan analyse --memory-limit=512M plugins/logingrupa/metapixel/classes/meta/FbqScriptBuilder.php plugins/logingrupa/metapixel/components/PixelHead.php && vendor/bin/pest plugins/logingrupa/metapixel/tests/Unit/Meta/FbqScriptBuilderTest.php plugins/logingrupa/metapixel/tests/Feature/Components/PixelHeadDeferredFlushTest.php</automated>
  </verify>
  <done>FbqScriptBuilder exists with all branches covered; PixelHead `flushDeferredFromController` delegates to it; `buildFbqOptionsObject` removed; FbqScriptBuilderTest green (incl. byte-identical assertion); PixelHeadDeferredFlushTest still green (all 6 tests); phpstan L10 clean on both files. Atomic commit: `refactor(metapixel): extract FbqScriptBuilder; wire PixelHead deferred flush through it`.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: OfferSwitchResult return shape + enrich offer-switch + route AJAX branches through builder</name>
  <files>classes/meta/OfferSwitchResult.php, classes/event/adapter/shopaholic/ProductPageWatcher.php, classes/adapter/theme/ThemeAjaxHandler.php, tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php, tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php</files>
  <behavior>
    ProductPageWatcherTest updates:
    - `test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku`: `dispatchForOfferSwitch(42,101)` now returns an OfferSwitchResult; assert `->sEventId` is a fresh valid UUID (not the PDP event_id) AND `->arCustomData['content_ids'] === ['SKU-42-101']` AND `->arCustomData['value']` is the resolved float AND `->arCustomData['currency'] === 'EUR'` AND `->arCustomData['content_type'] === 'product'`. CAPI dispatch + collector action_key assertions stay as-is. Disabled-state still throws RuntimeException.

    ThemeAjaxHandlerSubjectTypeTest updates:
    - `test_valid_alias_routes_through_registered_adapter_and_dispatches_send_capi_event`: the mocked `dispatchForOfferSwitch` now returns `new OfferSwitchResult($sFakeEventId, ['content_ids' => ['SKU-42-100'], 'content_name' => 'Test Product', 'content_type' => 'product', 'value' => 9.99, 'currency' => 'EUR'])`. Response body `event_id` === $sFakeEventId; `script` contains `fbq("track", "ViewContent"`, the eventID, `SKU-42-100`, and `9.99`. With Settings `test_event_code` set, script also contains `test_event_code: "..."`.
    - NEW test: generic-theme AJAX branch (non-shopaholic alias) with test_event_code set → response script contains `test_event_code` + `eventID` and `{}` (empty) custom_data — content NOT invented.
  </behavior>
  <action>Create `classes/meta/OfferSwitchResult.php` — `final class OfferSwitchResult` in `Logingrupa\Metapixel\Classes\Meta` with constructor-promoted `public readonly string $sEventId` and `public readonly array $arCustomData` (PHPDoc `array<string, mixed>`). readonly is 8.1+ safe. (Prefer this typed VO over a loose array — phpstan L10 narrows `->arCustomData` cleanly and the contract is self-documenting.)

Refactor `ProductPageWatcher::dispatchForOfferSwitch` (`classes/event/adapter/shopaholic/ProductPageWatcher.php`): change return type from `string` to `OfferSwitchResult`. It already assembles the collector-push array with content_ids `[SKU-pid-oid_new]`, content_name, content_type 'product', value, currency. Build the browser-facing custom_data ONCE as a local `$arCustomData` (keys: `content_ids`, `content_name`, `content_type`, `value`, `currency` — the ViewContent custom_data subset; do NOT include num_items/contents which the browser block does not carry today), use it for the collector push AND wrap it in `return new OfferSwitchResult($sEventId, $arCustomData);`. Update the method docblock summary + `@return` (short Laravel form). Watcher owns the ViewContent browser payload; the AJAX handler must NOT re-derive it. Add `use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;`.

Refactor `classes/adapter/theme/ThemeAjaxHandler.php`:
- Product branch (current L186 + L212-217): receive `$obResult = App::make(ProductPageWatcher::class)->dispatchForOfferSwitch($iSubjectId, $iOfferId);` (type `OfferSwitchResult`). Resolve `$sTestCode` from `Settings::get('test_event_code','')` (is_string guard) once. Build the response script via `FbqScriptBuilder::build($mName, $obResult->arCustomData, $obResult->sEventId, $sTestCode !== '' ? $sTestCode : null)`. Set `$sEventId = $obResult->sEventId` for the JSON `event_id` field.
- Generic-theme branch (current L188-209, the else): keep custom_data `{}` (theme actions are contentless — correct, do NOT invent content). Replace the inline sprintf (L212-217) for THIS branch too by routing through `FbqScriptBuilder::build($mName, [], $sEventId, $sTestCode !== '' ? $sTestCode : null)` so it gains test_event_code + eventID parity. Resolve `$sTestCode` once at a scope covering both branches.
- The non-subject-type theme path in `onBeforeRun` (current L112-117) ALSO builds an fbq block via inline sprintf — route it through `FbqScriptBuilder::build($obEvent->sEventName, [], $sEventId, $sTestCode-as-?string)` as well for full DRY (resolve test code there too). This keeps all theme-action browser blocks consistent.
- Add `use Logingrupa\Metapixel\Classes\Meta\FbqScriptBuilder;` and `use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;`. Add `use Logingrupa\Metapixel\Models\Settings;` already present (confirm). Remove now-dead `$iJsonFlags` locals where the builder replaces them.

Update the two test files per the behavior block above (import OfferSwitchResult; mock the new return shape; add the generic-theme test_event_code test).</action>
  <verify>
    <automated>cd /home/forge/nailscosmetics.lv && rsync -a --exclude='.planning' --exclude='.claude' --exclude='.git' plugins/logingrupa/metapixel/ plugins/logingrupa/metapixel/ >/dev/null 2>&1; vendor/bin/phpstan analyse --memory-limit=512M plugins/logingrupa/metapixel/classes/meta/OfferSwitchResult.php plugins/logingrupa/metapixel/classes/event/adapter/shopaholic/ProductPageWatcher.php plugins/logingrupa/metapixel/classes/adapter/theme/ThemeAjaxHandler.php && vendor/bin/pest plugins/logingrupa/metapixel/tests/Feature/Adapter/Shopaholic/ProductPageWatcherTest.php plugins/logingrupa/metapixel/tests/Feature/Adapter/Theme/ThemeAjaxHandlerSubjectTypeTest.php</automated>
  </verify>
  <done>OfferSwitchResult VO exists; dispatchForOfferSwitch returns it carrying event_id + custom_data; both AJAX script-build sites + the non-subject-type theme block route through FbqScriptBuilder; offer-switch response script carries SKU-{pid}-{oid} + value + test_event_code (when set) + eventID; generic-theme branch script carries test_event_code + eventID with `{}` data; ProductPageWatcherTest + ThemeAjaxHandlerSubjectTypeTest green; phpstan L10 clean. Atomic commit: `feat(metapixel): enrich offer-switch ViewContent browser script via shared builder + OfferSwitchResult`.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| JS → ThemeAjaxHandler AJAX | Untrusted `data` (subject_type, subject_id, offer_id, name) crosses here |
| Server → browser (fbq script) | Server-rendered `<script>` string returned in JSON response |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-osv-01 | Tampering/XSS | FbqScriptBuilder json_encode of name + custom_data | mitigate | Preserve `JSON_HEX_TAG\|JSON_HEX_QUOT\|JSON_HEX_AMP\|JSON_HEX_APOS` flags identical to PixelHead self::JS; unit test asserts hex-escaping of `<"&'` in the event name. No raw interpolation of untrusted strings into the script. |
| T-osv-02 | Information disclosure | offer-switch custom_data echoed to browser | accept | content_ids/value/currency are already public catalog data also sent to Meta via CAPI; no PII added to the browser block (user_data stays CAPI-only). |
| T-osv-03 | Spoofing | offer_id / subject_id from JS | mitigate | Existing handler guards (numeric + positive checks, loadSubject re-enforces active/site/SoftDelete) unchanged; this plan does not relax them. |
| T-osv-SC | Tampering | npm/pip/cargo installs | mitigate | No package installs in this plan — no new dependencies. |
</threat_model>

<verification>
- `composer qa` chain green when run against synced live dir (pint → phpstan L10 → phpmd → pest coverage ≥90%). The two committed tasks each verify their touched files; full-suite qa is the close gate.
- All three fbq-block render sites reference `FbqScriptBuilder::` (grep: `grep -rl 'FbqScriptBuilder::' plugins/logingrupa/metapixel/components plugins/logingrupa/metapixel/classes/adapter/theme` returns both files).
- No inline `sprintf('<script>fbq("track"` remains in PixelHead or ThemeAjaxHandler (grep: `grep -rn 'fbq(\\"track\\"' plugins/logingrupa/metapixel/components/PixelHead.php plugins/logingrupa/metapixel/classes/adapter/theme/ThemeAjaxHandler.php` shows only FbqScriptBuilder calls, no sprintf literals).
- WORKTREE CAVEAT: sync edited files into live plugin dir before running pest/phpstan; restore live dir before committing in worktree (06-02-SUMMARY.md pattern).
</verification>

<success_criteria>
- One shared `FbqScriptBuilder` is the single source for fbq track-block assembly; PixelHead + both ThemeAjaxHandler branches + the non-subject-type theme block all delegate to it.
- PixelHead deferred-flush output byte-identical to pre-refactor (PixelHeadDeferredFlushTest 6/6 green, no behavior change).
- Offer-switch AJAX browser ViewContent carries `SKU-{pid}-{oid}` + value + currency + test_event_code (when Settings set) + eventID — mirrors CAPI, dedups by eventID.
- Generic-theme AJAX branch carries test_event_code + eventID with empty `{}` custom_data (no invented content).
- `dispatchForOfferSwitch` returns `OfferSwitchResult`; AJAX handler consumes its custom_data without re-deriving.
- phpstan L10 clean; coverage ≥90%; two atomic commits (one per task).
</success_criteria>

<output>
Create `.planning/quick/260619-osv-offer-switch-viewcontent/260619-osv-SUMMARY.md` when done.
</output>
