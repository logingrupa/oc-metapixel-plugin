---
phase: quick-260616-wcp
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - components/PixelHead.php
  - classes/meta/PayloadBuilder.php
  - tests/Feature/Components/PixelHeadDeferredFlushTest.php
  - tests/Unit/Meta/PayloadBuilderTest.php
autonomous: true
requirements: [QUICK-260616-WCP]
must_haves:
  truths:
    - "Deferred ViewContent fbq() blocks carry test_event_code when Settings.test_event_code is set, so the event surfaces in Meta Test Events tab"
    - "Deferred fbq() blocks emit NO test_event_code key when Settings.test_event_code is unset (production default unchanged)"
    - "Contentless PageView CAPI custom_data omits content_type and content_ids (no product-shaped noise)"
    - "ViewContent/AddToCart/Purchase CAPI custom_data still carries content_type=product + content_ids"
    - "value and currency remain present on every event including contentless PageView"
  artifacts:
    - path: "components/PixelHead.php"
      provides: "test_event_code parity in flushDeferredFromController both render branches"
      contains: "test_event_code"
    - path: "classes/meta/PayloadBuilder.php"
      provides: "conditional content_type + content_ids gating on non-empty content_ids"
    - path: "tests/Feature/Components/PixelHeadDeferredFlushTest.php"
      provides: "test_event_code present-when-set / absent-when-unset assertions on deferred blocks"
    - path: "tests/Unit/Meta/PayloadBuilderTest.php"
      provides: "empty-content_ids omission + non-empty retention assertions"
  key_links:
    - from: "components/PixelHead.php flushDeferredFromController"
      to: "Settings::get('test_event_code')"
      via: "is_string runtime guard + json_encode(..., self::JS)"
      pattern: "Settings::get\\('test_event_code'"
    - from: "classes/meta/PayloadBuilder.php buildEventPayload"
      to: "$obResolver->resolveContentIds($obSubject)"
      via: "non-empty array gate"
      pattern: "resolveContentIds"
---

<objective>
Phase 6 follow-up bugfix. Two independent concerns, one plan, two atomic commits.

CONCERN 1 — Deferred browser ViewContent (and any pushed event) never appears in Meta's Test Events tab. Root cause: `components/PixelHead.php::flushDeferredFromController()` (lines 191-256) builds `fbq("track", ...)` blocks WITHOUT `test_event_code`, while the base PageView block in `emitBasePixel()` (lines 112-121) injects it conditionally. Meta's Test Events tab only displays events carrying the matching `test_event_code`, so the deferred events are invisible there even though the browser genuinely fires them.

CONCERN 2 — Contentless PageView CAPI events carry product-shaped noise: `content_type=product`, `content_ids=[]`. Root cause: `classes/meta/PayloadBuilder.php` line 43 hardcodes `'content_type' => 'product'` for EVERY event, and line 42 always emits `content_ids` even when the resolver returns `[]` (theme.action PageView). A contentless PageView should not look like a product event.

Purpose: Make deferred events debuggable in Meta Test Events, and stop sending semantically-wrong product fields on contentless events. Both are diagnostic/data-quality fixes; neither changes production fbq output when no test code is set, nor changes any event that actually has content_ids.

Output: 2 source edits + 2 test-file extensions. composer qa green (pint, phpstan L10, phpmd, pest ≥90% coverage).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@components/PixelHead.php
@classes/meta/PayloadBuilder.php
@classes/adapter/theme/ThemeActionValueResolver.php
@tests/Feature/Components/PixelHeadDeferredFlushTest.php
@tests/Unit/Meta/PayloadBuilderTest.php
@tests/doubles/FakeValueResolver.php

## Worktree / live-dir validation caveat (MANDATORY — read before running tests)

This plugin's composer PSR-4 autoload AND October Rain's class manifest bind `Logingrupa\Metapixel\` to the LIVE plugin directory `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/`, NOT to any worktree checkout. Host vendor tooling (pest, phpstan) therefore sees only the live directory. Prior Phase 6 executors (see `06-02-SUMMARY.md`, "PHPStan + Pest see the LIVE plugin directory") worked around this by:

1. Editing files in the worktree branch (canonical commits live there).
2. Syncing the edited files into the live plugin dir for validation.
3. Running pest/phpstan against the live dir.
4. Restoring the live dir to its pre-edit state BEFORE committing.

The current working directory IS the live plugin dir. Confirm `git rev-parse --show-toplevel` and `git status` at start. If you are operating directly in the live dir on a branch, edit in place, run validation in place, commit in place — no sync/restore dance needed. If you are operating in a separate worktree, follow the sync-validate-restore pattern above so the live dir is clean before the SUMMARY commit. Determine which situation applies before editing.

## Vendor binaries (NOT plugin-local)

- pest:    `/home/forge/nailscosmetics.lv/vendor/bin/pest`
- phpstan: `/home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --memory-limit=512M`
  (phpstan config auto-detected at plugin-root `phpstan.neon`, level 10, phpVersion 80300)

Run from the live plugin dir `/home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel/`.
Adapter-tagged tests use `#[Group('adapter')]`; `PixelHeadDeferredFlushTest` is class-tagged `#[Group('adapter')]`. Coverage gate ≥90% must hold.

## Code-style locks (plugin CLAUDE.md)

- Hungarian notation for locals: `$sTestCode`, `$arContentIds`, `$arCustomData`, `$ob...`.
- NO `// Phase N` / `// CR-XX` / `// concern` markers in source. Workflow refs belong in commits only.
- NO `assert()`. Use `is_string()` runtime guard for `Settings::get()` mixed return (project-locked pattern: `$mValue = Settings::get(...); is_string($mValue) ? $mValue : ''`).
- Dual PHP 8.3/8.4 — no 8.4-only syntax.
- `json_encode(..., self::JS)` flags for any JS-embedded value (same as base pixel).
- Laravel short docblocks; no narrative.
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: test_event_code parity in deferred fbq blocks (Concern 1)</name>
  <files>components/PixelHead.php, tests/Feature/Components/PixelHeadDeferredFlushTest.php</files>
  <behavior>
    - When Settings.test_event_code is non-empty, a deferred fbq block WITH an event_id renders the 4th-arg object containing BOTH `eventID:` and `test_event_code:` keys (e.g. `{eventID: "eid-001", test_event_code: "TEST123"}`).
    - When Settings.test_event_code is non-empty, a deferred fbq block WITHOUT an event_id renders a 4th-arg object containing `test_event_code:` (e.g. `fbq("track", NAME, DATA, {test_event_code: "TEST123"})`).
    - When Settings.test_event_code is unset/empty (default), deferred blocks render EXACTLY as today: `{eventID: X}` for the event_id branch and no 4th arg for the no-event_id branch. No `test_event_code` substring present.
  </behavior>
  <action>
In `flushDeferredFromController()` read the test code once at the top of the try block (mirror `emitBasePixel` lines 112-113): `$mTestCode = Settings::get('test_event_code', '');` then `$sTestCode = is_string($mTestCode) ? $mTestCode : '';`. `Settings` is already imported (line 20). Compute the JS-encoded value once when non-empty: `$sTestCodeJson = $sTestCode !== '' ? (string) json_encode($sTestCode, self::JS) : null;`.

Edit the two sprintf templates (currently lines ~219-224 eventID branch, ~226-230 no-eventID branch) so the fbq 4th-arg object conditionally includes test_event_code:
  - eventID branch: when $sTestCodeJson is non-null emit `{eventID: %s, test_event_code: %s}`, else keep `{eventID: %s}`.
  - no-eventID branch: when $sTestCodeJson is non-null emit a 4th arg `{test_event_code: %s}`, else keep the current 3-arg call (no 4th arg).
Keep both branches readable — build the trailing object fragment in a small local (Hungarian-named, e.g. `$sObjFragment` / `$sExtra`) rather than nesting sprintf, or use two sprintf format strings selected by an if. Whichever you pick, the production (no test code) output must be byte-identical to today. Do NOT add Phase/concern comment markers. Keep the function under 70 lines; if the added branching pushes it over, extract a private helper that builds the fbq 4th-arg object string from ($mEventId, $sTestCodeJson).

In the test file, add two test methods to the existing `PixelHeadDeferredFlushTest` (class already `#[Group('adapter')]`, setUp already seeds pixel_id):
  - `test_deferred_blocks_inject_test_event_code_when_set`: in setUp-style preamble set `test_event_code => 'TEST123'` via `Settings::set([...]); Settings::clearInternalCache(); PluginGuard::reset();` (mirror existing `test_test_event_code_flows_to_fbq_script_block`). `Bus::fake()`. Push one event WITH event_id + content_ids, flush, assert the block contains BOTH `eventID: "..."` and `test_event_code: "TEST123"`. Push a second scenario (separate test or second push) WITHOUT event_id and assert the block contains `test_event_code: "TEST123"` and `fbq("track"` with a 4th-arg object but no `eventID`.
  - `test_deferred_blocks_omit_test_event_code_when_unset`: default setUp (no test_event_code). Push an event, flush, assert the block does NOT contain the substring `test_event_code`. Cover both event_id and no-event_id branches.
Use `assertStringContainsString` / `assertStringNotContainsString` against the rendered block strings from `PixelHeadDeferredFlushBuffer::getBlocks()`.
  </action>
  <verify>
    <automated>cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel && /home/forge/nailscosmetics.lv/vendor/bin/pest tests/Feature/Components/PixelHeadDeferredFlushTest.php</automated>
  </verify>
  <done>New tests pass: deferred fbq blocks contain test_event_code (both branches) when set, and contain no test_event_code substring when unset. Existing PixelHeadDeferredFlushTest cases still pass (no regression to eventID/SKU assertions). phpstan L10 clean on PixelHead.php. Atomic commit: `fix(metapixel): inject test_event_code into deferred fbq blocks for Meta Test Events parity`.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Gate content_type + content_ids on non-empty content_ids (Concern 2)</name>
  <files>classes/meta/PayloadBuilder.php, tests/Unit/Meta/PayloadBuilderTest.php</files>
  <behavior>
    - When the resolver returns a NON-empty content_ids array (ViewContent/AddToCart/Purchase, Shopaholic product subject), custom_data still contains `content_type => 'product'` and `content_ids => [...]`. Existing assertions in PayloadBuilderTest (`content_type === 'product'`) keep passing because FakeValueResolver defaults to `['SKU-1']`.
    - When the resolver returns an EMPTY content_ids array (theme.action PageView), custom_data contains NEITHER `content_type` NOR `content_ids` keys.
    - `value` and `currency` are present on every event regardless of content_ids (Meta tolerates value 0).
    - `$arEventExtras` overlay still works: an extra `content_type => 'article'` overrides the gated default (the `test_event_extras_merge_into_custom_data` case must still pass — extras merge AFTER the gated base, so an extras-supplied content_type lands even when content_ids is empty).
  </behavior>
  <action>
In `buildEventPayload()` (lines 37-44), resolve content_ids into a local first: `$arContentIds = $obResolver->resolveContentIds($obSubject);`. Build the base custom_data with only the always-present keys: `currency`, `value`, `num_items`, `contents`. Then conditionally add the product-shaped keys only when `$arContentIds !== []`:
    if ($arContentIds !== []) { $arCustomData['content_ids'] = $arContentIds; $arCustomData['content_type'] = 'product'; }
Keep the existing `$arEventExtras` array_merge overlay AFTER this gate (extras must still be able to override content_type for non-ecommerce events — preserves the locked PayloadBuilder behavior and the `test_event_extras_merge_into_custom_data` test).

num_items decision: KEEP num_items unconditionally (do NOT gate it). Rationale to document in the SUMMARY: num_items is a generic counter (resolver returns 0 for contentless, a meaningful count otherwise), it is not product-shaped the way content_type/content_ids are, Meta tolerates 0, and gating it would add branching with no diagnostic benefit. `contents` likewise stays unconditional (the theme resolver returns `[]` for contentless, which is the correct neutral value). This keeps the change minimal and the gate scoped strictly to the two product-identity keys that caused the noise.

Do NOT introduce any comparison against `$sEventName` — the H-9 grep gate (`! grep -E '\$sEventName\s*(===|!==|==)|switch\s*\(\s*\$sEventName|match\s*\(\s*\$sEventName|in_array\s*\(\s*\$sEventName' classes/meta/PayloadBuilder.php`) must still exit 0. The gate is on the resolved content_ids array, never on the event name. No Phase/concern comment markers.

In `tests/Unit/Meta/PayloadBuilderTest.php` add two test methods:
  - `test_empty_content_ids_omits_content_type_and_content_ids`: construct `new FakeValueResolver(arContentIds: [], arContents: [], iNumItems: 0)`, build a 'PageView' payload, assert `array_key_exists('content_type', $arCustom)` is false AND `array_key_exists('content_ids', $arCustom)` is false, AND assert `currency` and `value` ARE present (use `assertArrayHasKey`).
  - `test_non_empty_content_ids_retains_product_shape`: construct default `new FakeValueResolver` (content_ids `['SKU-1']`), build a 'ViewContent' payload, assert `content_type === 'product'` and `content_ids === ['SKU-1']`.
Confirm the existing three tests still pass unchanged (they use default FakeValueResolver with non-empty content_ids, so content_type stays present).
  </action>
  <verify>
    <automated>cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel && /home/forge/nailscosmetics.lv/vendor/bin/pest tests/Unit/Meta/PayloadBuilderTest.php && /home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --memory-limit=512M classes/meta/PayloadBuilder.php</automated>
  </verify>
  <done>Empty content_ids omits content_type+content_ids; non-empty retains both; value+currency always present; extras-override test still passes. H-9 grep gate exits 0 (no $sEventName comparison). phpstan L10 clean. Atomic commit: `fix(metapixel): drop product content_type/content_ids from contentless PageView CAPI payload`.</done>
</task>

</tasks>

<verification>
Full qa gate on the live plugin dir before SUMMARY:

```
cd /home/forge/nailscosmetics.lv/plugins/logingrupa/metapixel
/home/forge/nailscosmetics.lv/vendor/bin/phpstan analyse --memory-limit=512M
/home/forge/nailscosmetics.lv/vendor/bin/pest --coverage --min=90
```

- phpstan L10 clean (full plugin scope).
- pest green, coverage ≥90% (adapter-tagged tests included on full-Lovata matrix cell).
- H-9 PayloadBuilder grep gate exits 0 (no `$sEventName` comparison introduced).
- If operating in a worktree: live dir restored to clean pre-edit state before the SUMMARY commit (per worktree caveat).
</verification>

<success_criteria>
- Concern 1: deferred fbq blocks carry test_event_code when set (both eventID and no-eventID branches), and are byte-identical to today when unset.
- Concern 2: contentless PageView CAPI custom_data has no content_type/content_ids; product events retain both; value+currency always present; num_items kept (documented).
- Two atomic commits, one per concern.
- composer qa green (pint, phpstan L10, phpmd, pest ≥90%).
- No Phase/concern comment markers in source; Hungarian locals; no assert(); no 8.4-only syntax.
</success_criteria>

<output>
Create `.planning/quick/260616-wcp-pixelhead-testcode-pageview-noise/260616-wcp-SUMMARY.md` when done. Document the num_items keep-vs-gate decision (Concern 2) and which worktree/live-dir situation applied.
</output>
