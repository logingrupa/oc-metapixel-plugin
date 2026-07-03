---
phase: 02-adapter-system-core-contracts-registry-extension-hooks
plan: 2
slug: tooling-deltas-phpstan-phpunit
type: execute
wave: 1
depends_on: []
files_modified:
  - plugins/logingrupa/metapixel/phpstan.neon
  - plugins/logingrupa/metapixel/phpunit.xml
  - plugins/logingrupa/metapixel/CLAUDE.md
autonomous: true
requirements: []
maps_to:
  pitfalls:
    - P-01
    - P-13
  decisions:
    - D-17
    - D-18
must_haves:
  truths:
    - "phpstan.neon bans `request()`, `Illuminate\\Http\\Request::*`, `October\\Rain\\Cms\\Site::*`, and `System\\Classes\\SiteManager::*` calls inside `classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*` directories — P-01 enforcement uses `disallowIn` deny-list per RESEARCH §5.1 verbatim (H-1 — NEVER `allowIn` allow-list, which would silently exempt classes/Helper/ + classes/Meta/ where SiteResolver + EventLogWriter + MetaClient live)."
    - "phpstan.neon adds the verified FQN for Site facade + SiteManager (10-minute spike confirms the exact October 4.x namespace path before writing the rule)."
    - "phpunit.xml `Metapixel Adapter Tests` testsuite includes `tests/Contract/Adapter` directory in addition to the existing Unit/Adapter + Feature/Adapter entries."
    - "phpunit.xml `<source><include>` adds `./classes` and `./models` (when those folders appear in plan 02-01 + 02-03a), bringing coverage scope to all production code."
    - "plugins/logingrupa/metapixel/CLAUDE.md gains an extensibility section: third parties prefer `Event::fire` hooks over `Component::extend` + `addDynamicMethod` (P-13 convention)."
    - "After this plan, `composer analyse` still exits 0 against the Phase 1 / 02-01 state — the new disallowed-calls rules do not regress existing code (Phase 2 code paths in adapter/queue/event dirs do not yet exist beyond what 02-01 ships)."
  artifacts:
    - path: "plugins/logingrupa/metapixel/phpstan.neon"
      provides: "P-01 PHPStan enforcement — disallowed-calls bans Request / SiteManager / Site facade scoped to adapter/queue/event dirs via disallowIn (H-1)."
      contains: "disallowedMethodCalls"
    - path: "plugins/logingrupa/metapixel/phpunit.xml"
      provides: "Contract testsuite directory + source-coverage include for classes + models."
      contains: "tests/Contract"
    - path: "plugins/logingrupa/metapixel/CLAUDE.md"
      provides: "P-13 convention — `Event::fire` over `Component::extend` + `addDynamicMethod` for third-party hooks."
      contains: "Component::extend"
  key_links:
    - from: "plugins/logingrupa/metapixel/phpstan.neon"
      to: "plugins/logingrupa/metapixel/classes/Adapter/"
      via: "disallowIn glob pattern"
      pattern: "classes/Adapter/\\*"
    - from: "plugins/logingrupa/metapixel/phpunit.xml"
      to: "plugins/logingrupa/metapixel/tests/Contract/Adapter"
      via: "testsuite directory entry"
      pattern: "<directory>./tests/Contract/Adapter</directory>"
---

<objective>
Land the three Phase 2 tooling deltas that enforce P-01 (cross-context resolution drift) statically and prepare phpunit.xml + CLAUDE.md for the FakeAdapter contract scaffolding (which lands in plan 02-07). Specifically: extend `phpstan.neon` with `disallowedMethodCalls` for the Request facade + Site facade + SiteManager class scoped to `classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*` via `disallowIn` deny-list (H-1 — NEVER `allowIn` allow-list); extend `phpunit.xml` `Metapixel Adapter Tests` testsuite to include `tests/Contract/Adapter`; extend `<source><include>` to cover `classes/` + `models/`; add a short P-13 convention paragraph to the plugin's CLAUDE.md.

Purpose: cross-cuts the Phase 2 plan set by enforcing P-01 statically (the disallowed-calls scope kicks in when any adapter/queue/event code lands — plan 02-04 SiteResolver, plan 02-06 SendCapiEvent, future Phase 3 adapters all benefit). Also primes phpunit.xml for plan 02-07's contract directory.

Output: 1 phpstan.neon edit, 1 phpunit.xml edit, 1 CLAUDE.md addendum.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@plugins/logingrupa/metapixel/CLAUDE.md
@plugins/logingrupa/metapixel/.planning/PROJECT.md
@plugins/logingrupa/metapixel/.planning/ROADMAP.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-CONTEXT.md
@plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-RESEARCH.md
@plugins/logingrupa/metapixel/phpstan.neon
@plugins/logingrupa/metapixel/phpunit.xml

<interfaces>
Current `phpstan.neon` (Phase 1 state):
- `level: 10`, `phpVersion: 80300`, larastan + spaze/phpstan-disallowed-calls includes.
- `paths: [Plugin.php]` (plan 02-01 expands to add `classes`; this plan does NOT re-edit paths).
- `disallowedFunctionCalls` bans `assert()`, `@`, `array_find/any/all/find_key`.
- `disallowedAttributes` bans `Deprecated`.
- Phase 2 adds `disallowedMethodCalls` block — currently absent.

Current `phpunit.xml`:
- 3 testsuites: `Metapixel Unit Tests` (tests/Unit), `Metapixel Feature Tests` (tests/Feature), `Metapixel Adapter Tests` (tests/Unit/Adapter + tests/Feature/Adapter).
- `<source><include>` lists only `Plugin.php`. Plan 02-01 + 02-03a land classes/ + models/; this plan expands the include.

OctoberCMS 4.x facade FQN resolution (RESEARCH.md §9 A1 — `[ASSUMED]`):
- `Site` facade — assumed `October\Rain\Cms\Site` (research uses this) or `Cms\Facades\Site`. Spike at task 1.
- `SiteManager` — assumed `System\Classes\SiteManager`.
- `Request` — Laravel `Illuminate\Http\Request` (well-known).
- `request()` — Laravel global helper (well-known).

RESEARCH.md §5.1 disallowedMethodCalls config shape — verbatim (H-1 lock — `disallowIn` deny-list, NOT `allowIn`):

```
disallowedMethodCalls:
    -
        method: 'October\Rain\Cms\Site::*'
        message: 'metapixel: site_id MUST come from subject (SiteResolver::forSubject)'
        disallowIn:
            - 'classes/Queue/*'
            - 'classes/Event/*'
            - 'classes/Adapter/*'
    -
        method: 'System\Classes\SiteManager::*'
        message: 'metapixel: site_id MUST come from subject'
        disallowIn:
            - 'classes/Queue/*'
            - 'classes/Event/*'
            - 'classes/Adapter/*'
    -
        method: 'Illuminate\Http\Request::*'
        message: 'metapixel: per-event attributes from subject only'
        disallowIn:
            - 'classes/Queue/*'
            - 'classes/Event/*'
            - 'classes/Adapter/*'

disallowedFunctionCalls:
    # ... existing Phase 1 entries kept ...
    -
        function: 'request()'
        message: 'metapixel: per-event attributes MUST come from the subject via adapter'
        disallowIn:
            - 'classes/Queue/*'
            - 'classes/Event/*'
            - 'classes/Adapter/*'
```

VERIFIED: `vendor/spaze/phpstan-disallowed-calls/extension.neon:27-50` documents `disallowIn` config key accepting file-path glob patterns.

**H-1 design decision (REVISION R1):**

The original plan substituted `allowIn` (allow-list) for `disallowIn` (deny-list) under a "fail-closed" framing. The plan-checker flagged this: the proposed allowIn list explicitly whitelisted `classes/Helper/*` and `classes/Meta/*` — which is where `SiteResolver`, `EventLogWriter` (Helper), and `MetaClient` (Meta) live. P-01's enforcement DEPENDS on those classes NEVER calling SiteManager — yet the allowIn list told phpstan to PERMIT those calls there.

The revision honors RESEARCH §5.1 verbatim: deny-list scoped to the three dirs (`classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*`). Default semantics: outside those three dirs, the calls are PERMITTED (Phase 1 middleware/, controllers/, components/ legitimately read Request; classes/Helper/ + classes/Meta/ may legitimately call SiteManager if Phase 4 needs it — though SiteResolver itself MUST NOT, enforced separately by Plan 02-04 Task 3's static-source regex grep on `SiteResolver.php`).

This is fail-OPEN for non-adapter/queue/event dirs. The acceptable tradeoff:
- The phpstan rule covers the high-risk dirs explicitly.
- SiteResolver's static-source regex grep test (Plan 02-04 Task 3) catches the SiteResolver-specific cross-context bug.
- EventLogWriter never calls SiteManager because its design reads subject_type via AdapterRegistry — not from request context (verified by code review + grep guard in Plan 02-04 Task 2's verify step).
- MetaClient has no need to call SiteManager — credentials come per-call from the caller (D-19).

If a future Phase 4 plan adds code under `classes/Helper/` or `classes/Meta/` that DOES need site_id, that code must go through SiteResolver::forSubject (which delegates to adapter). The phpstan rule does not block it; code review enforces.

CLAUDE.md current state (relevant section excerpt):

```
## Extensibility contract

Third parties hook the plugin via:
- `AdapterRegistry::register($sSubjectClass, $sAdapterClass)` from their `Plugin::boot()`
- `Event::listen('metapixel.event.before_dispatch', ...)`
- `Event::listen('metapixel.event.after_dispatch', ...)`
- `Event::listen('metapixel.event.dead_letter', ...)`
- `Component::extend(PixelHead::class, ...)` + `addDynamicMethod(...)` — custom script injection
- `App::bind(MetaClientInterface::class, ...)` — HTTP client swap
```

Phase 2 addition: explicitly tag `Component::extend` + `addDynamicMethod` as the LAST resort hook surface (per P-13). Prefer `Event::fire` hooks.
</interfaces>

<spike_evidence>
RESEARCH.md §9 risk A1 logs the Site facade FQN as `[ASSUMED]`. Plan 02-02 RESOLVES this assumption via Task 1 spike before writing the disallowed-calls rule. Without the spike, the rule lands with a wrong FQN and silently fails to ban anything (phpstan accepts unknown class FQNs in disallowed-calls config — no warning).

Spike commands (Task 1):
```
grep -rn "namespace.*;$" vendor/october/rain/src/ | grep -i 'Site\b\|SiteManager' | head -20
grep -rn "class\s\+SiteManager" vendor/october/ | head -5
grep -rn "class\s\+Site\b" vendor/october/ | head -5
grep -rn "facade.*site\|Site::class" vendor/october/system/ | head -10
```

Likely results based on OctoberCMS 4 patterns:
- `System\Classes\SiteManager` — full class with `setSite()`, `getCurrent()`, etc.
- Facade alias `Site` registered via `System\ServiceProviders\...` mapping `Site` → `System\Classes\SiteManager` (or a dedicated facade subclass).

Output of spike: the verified FQN(s) to put in the `disallowedMethodCalls` config. If both a facade class AND the underlying class exist, ban BOTH.
</spike_evidence>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Spike — resolve Site / SiteManager FQN for OctoberCMS 4.x</name>
  <files>
    (verification-only — no source edits in this task; output captured into Task 2's rule)
  </files>
  <action>
Run from repo root `/home/forge/nailscosmetics.lv/`:

```
grep -rn "^class\s\+SiteManager" vendor/october/ 2>/dev/null
grep -rn "^class\s\+Site\b" vendor/october/ 2>/dev/null | head -10
grep -rn "^namespace.*;" vendor/october/rain/src/Cms/ 2>/dev/null | head -20
grep -rn "Site::class\|'Site'\s*=>" vendor/october/system/ 2>/dev/null | head -10
ls vendor/october/system/classes/ 2>/dev/null | grep -i site
ls vendor/october/rain/src/Support/Facades/ 2>/dev/null | grep -i site
```

Capture the verified FQNs:

- `System\Classes\SiteManager` (or whatever the spike confirms — could be `October\Rain\Cms\SiteManager` in some builds).
- `Site` facade class FQN — likely `System\Facades\Site` OR `October\Rain\Support\Facades\Site` OR none (facade defined inline at register-time via `AliasLoader`).

Output a one-paragraph note in the commit message OR in the SUMMARY: "Site facade FQN verified as `<FQN>`; SiteManager class verified at `<FQN>`. phpstan.neon rule uses these FQNs."

If the facade is registered via AliasLoader without a dedicated class (rare in modern October 4), the disallowed-calls rule on `'Site::*'` short-name would NOT match because phpstan resolves the short name to its target class. In that case the rule's `method:` value MUST be the underlying SiteManager FQN — banning `SiteManager::*` covers both the facade calls and direct class calls.

Conservative belt-and-suspenders: ban BOTH the assumed facade FQN AND the underlying SiteManager FQN. If the facade FQN turns out to not exist, phpstan accepts the unknown class and the rule is a no-op for that line — harmless. The SiteManager rule provides the real safety net.

Spike output validation (M-1 hardening — exit criterion must be substantive): require the grep output to contain `System\Classes\SiteManager` literally OR `October\Rain\Cms\` OR similar Cms/Site namespace declaration. If the file is empty or grep returns zero matches, FAIL the spike + revisit with broader grep terms before Task 2 writes the rule.

Save the spike output to `/tmp/site-fqn-spike.txt`. Task 2 reads it and writes the rules.
  </action>
  <verify>
    <automated>test -f /tmp/site-fqn-spike.txt &amp;&amp; test -s /tmp/site-fqn-spike.txt &amp;&amp; grep -qE 'class\s+SiteManager|namespace.*Cms|namespace.*Site' /tmp/site-fqn-spike.txt</automated>
  </verify>
  <done>/tmp/site-fqn-spike.txt non-empty + contains the grep output with at least one match for SiteManager or a Cms/Site namespace line. Spike conclusion documented for Task 2.</done>
</task>

<task type="auto">
  <name>Task 2: Add disallowed-calls rules to phpstan.neon (H-1 disallowIn deny-list)</name>
  <files>
    plugins/logingrupa/metapixel/phpstan.neon
  </files>
  <action>
Edit `plugins/logingrupa/metapixel/phpstan.neon`. Read the file first (Phase 1 state has 4 `disallowedFunctionCalls` entries + `disallowedAttributes` block).

Append a new `disallowedFunctionCalls` entry for `request()` (the global Laravel helper) — INSIDE the existing `disallowedFunctionCalls:` block, after the `array_all()` entry. Add a new top-level `disallowedMethodCalls:` block after `disallowedFunctionCalls` and before `disallowedAttributes`.

**H-1 lock — use `disallowIn` deny-list scoped to three dirs only** (per RESEARCH §5.1 verbatim). Do NOT use `allowIn` allow-list — that would require listing every legitimate-call dir and silently fail-open if a new dir is added (or worse, if `classes/Helper/` or `classes/Meta/` is included in the allowlist, the rule no-ops for SiteResolver / EventLogWriter / MetaClient — the exact files P-01 is supposed to protect).

Final phpstan.neon shape (relevant excerpt):

```yaml
    disallowedFunctionCalls:
        -
            function: 'assert()'
            message: 'use throw — assert() is a silent no-op when zend.assertions=0 (production default)'
        -
            function: '@'
            message: 'no @ suppression — handle errors explicitly'
        -
            function: 'array_find()'
            message: 'PHP 8.4-only — use array_filter + early return or foreach'
        -
            function: 'array_find_key()'
            message: 'PHP 8.4-only — use array_keys + array_filter or foreach'
        -
            function: 'array_any()'
            message: 'PHP 8.4-only — use array_filter or foreach with early return'
        -
            function: 'array_all()'
            message: 'PHP 8.4-only — use array_filter or foreach'
        -
            function: 'request()'
            message: 'metapixel: per-event attributes MUST come from the subject via adapter — request() banned in adapter/queue/event dirs'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'

    disallowedMethodCalls:
        -
            method: '<VERIFIED_SITEMANAGER_FQN>::*'
            message: 'metapixel: site_id MUST come from subject via SiteResolver::forSubject — cross-context determinism'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'
        -
            method: '<VERIFIED_SITE_FACADE_FQN>::*'
            message: 'metapixel: site_id MUST come from subject via SiteResolver::forSubject'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'
        -
            method: 'Illuminate\Http\Request::*'
            message: 'metapixel: per-event attributes MUST come from the subject via adapter'
            disallowIn:
                - 'classes/Queue/*'
                - 'classes/Event/*'
                - 'classes/Adapter/*'

    disallowedAttributes:
        -
            attribute: 'Deprecated'
            message: 'PHP 8.4-only attribute — use @deprecated docblock instead'
```

REPLACE `<VERIFIED_SITEMANAGER_FQN>` and `<VERIFIED_SITE_FACADE_FQN>` with the FQNs from Task 1's spike. If the facade has no dedicated class (registered via AliasLoader), drop that block and rely on the SiteManager rule.

The deny-list approach means: outside the three adapter/queue/event dirs, all these calls are PERMITTED. Phase 1 middleware/ and controllers/ legitimately read Request; classes/Helper/SiteResolver MUST NOT call SiteManager but that's enforced by Plan 02-04 Task 3's static-source regex grep test (defence-in-depth). classes/Meta/MetaClient has no need to call SiteManager — credentials come per-call from the caller (D-19); code review enforces.

NOTE: the existing `disallowedFunctionCalls.assert()` rule stays universal (no `disallowIn`) — assert is banned everywhere. Only the new `request()` + the 3 disallowedMethodCalls entries get `disallowIn` scoping.
  </action>
  <verify>
    <automated>grep -q 'disallowedMethodCalls:' plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -qE "method: '.*SiteManager.*'" plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -q "method: 'Illuminate\\\\Http\\\\Request::\*'" plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -q "function: 'request()'" plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; grep -q 'disallowIn:' plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; ! grep -q 'allowIn:' plugins/logingrupa/metapixel/phpstan.neon &amp;&amp; cd plugins/logingrupa/metapixel &amp;&amp; composer analyse 2&gt;&amp;1 | tail -5 | grep -Eq '(\[OK\]|No errors)'</automated>
  </verify>
  <done>phpstan.neon has disallowedMethodCalls block with SiteManager + Request entries + `request()` function ban; uses disallowIn deny-list (NOT allowIn allow-list — H-1); `composer analyse` exits 0 against the current code (Phase 1 + plan 02-01 state).</done>
</task>

<task type="auto">
  <name>Task 3: Extend phpunit.xml with Contract testsuite dir + source-coverage includes</name>
  <files>
    plugins/logingrupa/metapixel/phpunit.xml
  </files>
  <action>
Edit `plugins/logingrupa/metapixel/phpunit.xml`. Current state:

```xml
<testsuite name="Metapixel Adapter Tests">
    <directory>./tests/Unit/Adapter</directory>
    <directory>./tests/Feature/Adapter</directory>
</testsuite>
```

Add `tests/Contract/Adapter` to the Adapter testsuite:

```xml
<testsuite name="Metapixel Adapter Tests">
    <directory>./tests/Unit/Adapter</directory>
    <directory>./tests/Feature/Adapter</directory>
    <directory>./tests/Contract/Adapter</directory>
</testsuite>
```

Add a new `Metapixel Contract Tests` testsuite for plan 02-07's contract scaffold:

```xml
<testsuite name="Metapixel Contract Tests">
    <directory>./tests/Contract</directory>
</testsuite>
```

Update `<source><include>` from:

```xml
<source>
    <include>
        <file>./Plugin.php</file>
    </include>
</source>
```

To:

```xml
<source>
    <include>
        <file>./Plugin.php</file>
        <directory>./classes</directory>
        <directory>./models</directory>
    </include>
</source>
```

Note: `./classes` exists after plan 02-01. `./models` exists after plan 02-03a. The xmllint validator does NOT care whether the directories exist at parse time — Pest/PHPUnit handles missing dirs gracefully at runtime (no error). Coverage on missing dirs contributes 0 lines.

Coverage gate (≥90%) applies to Run A only per existing `metapixel-qa.yml`. Adding models + classes to the include scope means Plan 02-03a's storage classes need decent coverage too — plan 02-03a's tests T25–T28 cover models + migrations; plan 02-03b's tests T7 + T15 + T23 + T24 cover Settings + PluginGuard + exceptions.
  </action>
  <verify>
    <automated>xmllint --noout plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; grep -q 'tests/Contract/Adapter' plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; grep -q 'Metapixel Contract Tests' plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; grep -q '<directory>./classes</directory>' plugins/logingrupa/metapixel/phpunit.xml &amp;&amp; grep -q '<directory>./models</directory>' plugins/logingrupa/metapixel/phpunit.xml</automated>
  </verify>
  <done>phpunit.xml is xmllint-valid; Adapter testsuite includes Contract subdir; new Contract testsuite present; source coverage scope includes classes + models.</done>
</task>

<task type="auto">
  <name>Task 4: Add P-13 convention paragraph to plugin CLAUDE.md</name>
  <files>
    plugins/logingrupa/metapixel/CLAUDE.md
  </files>
  <action>
Edit `plugins/logingrupa/metapixel/CLAUDE.md`. Find the `## Extensibility contract` section. Replace the bullet list with this updated form:

```
## Extensibility contract

Third parties hook the plugin via, in order of preference:

1. **`AdapterRegistry::register($sSubjectClass, $sAdapterClass)`** from their `Plugin::boot()` — register an adapter for any subject class.
2. **`Event::listen('metapixel.event.before_dispatch', ...)`** — halt-able payload mutation hook (third arg `$halt = true`; listener returning `false` vetoes dispatch). MUST NOT mutate `event_id` or `event_time` (dedup contract anchor).
3. **`Event::listen('metapixel.event.after_dispatch', ...)`** — observe-only successful-dispatch tap.
4. **`Event::listen('metapixel.event.dead_letter', ...)`** — observe-only permanent-failure alert hook.
5. **`App::bind(MetaClientInterface::class, ...)`** — HTTP client swap (testing or alternative transport).
6. **`Component::extend(PixelHead::class, ...)` + `addDynamicMethod(...)`** — LAST RESORT. Use ONLY when an Event::fire hook does not exist for your use case. Unbounded surface (every method can be replaced) — third parties must scope dynamic methods with an `onMetapixel*` prefix to avoid collisions.

Additional 5 `Event::fire` hooks deferred to v2.1 (adapter.resolve, value.resolve, user_data.resolve, pixel.before_render, settings.lookup). Add when a real third-party use case surfaces.
```

The original line:

```
- `Component::extend(PixelHead::class, ...)` + `addDynamicMethod(...)` — custom script injection
```

is REPLACED by item 6 in the new list above (now flagged as LAST RESORT with prefix-scope guidance).

This is the P-13 convention deliverable — no new code, just a stronger doc. The plugin's CLAUDE.md is the project lock — every future Claude (planner, executor, reviewer) reads this section.
  </action>
  <verify>
    <automated>grep -q 'LAST RESORT' plugins/logingrupa/metapixel/CLAUDE.md &amp;&amp; grep -q 'onMetapixel\*' plugins/logingrupa/metapixel/CLAUDE.md &amp;&amp; grep -q 'in order of preference' plugins/logingrupa/metapixel/CLAUDE.md &amp;&amp; grep -q 'MUST NOT mutate' plugins/logingrupa/metapixel/CLAUDE.md</automated>
  </verify>
  <done>plugin CLAUDE.md "Extensibility contract" section ranks hooks 1–6, flags Component::extend as LAST RESORT, mandates `onMetapixel*` dynamic-method prefix, and warns event_id mutation in before_dispatch.</done>
</task>

<task type="auto">
  <name>Task 5: Run composer qa + commit</name>
  <files>
    plugins/logingrupa/metapixel/phpstan.neon
    plugins/logingrupa/metapixel/phpunit.xml
    plugins/logingrupa/metapixel/CLAUDE.md
  </files>
  <action>
From `plugins/logingrupa/metapixel/`:

```
composer qa 2>&1 | tee /tmp/02-02-qa.log | tail -20
```

Expected: All scripts exit 0. The new disallowedMethodCalls rules apply to no code in plan 02-01 (interfaces + AdapterRegistry — none of these call SiteManager/Request/request()). Plan 02-02 lands the rules ahead of plan 02-04 (SiteResolver) + plan 02-06 (SendCapiEvent) which are the first downstream consumers that would trigger violations if mis-written.

If `composer test` fails on plan 02-01 tests due to missing Contract/Adapter directory: Pest 4 tolerates empty directories. If it complains: create `tests/Contract/Adapter/.gitkeep` so the directory exists pre-plan-02-07. Note: M-8 — the verify step below uses `test 4 -ge` (at least 3 files) so the .gitkeep fallback does NOT break the verify.

Commit:

```
git add plugins/logingrupa/metapixel/phpstan.neon \
        plugins/logingrupa/metapixel/phpunit.xml \
        plugins/logingrupa/metapixel/CLAUDE.md

git commit -m "$(cat <<'EOF'
chore(metapixel): tooling deltas for P-01 PHPStan enforcement + Contract testsuite

phpstan.neon adds disallowedMethodCalls rules for SiteManager, Site facade,
and Illuminate\Http\Request with `disallowIn` deny-list scoped to
classes/Queue, classes/Event, classes/Adapter dirs (per RESEARCH §5.1
verbatim). Also bans the global request() helper under the same disallowIn
scope. Verified FQNs via vendor/october/ grep spike (see
/tmp/site-fqn-spike.txt).

phpunit.xml adds the Metapixel Contract Tests testsuite and includes
tests/Contract/Adapter in the Adapter testsuite. Source-coverage include
extends to classes + models for the upcoming 02-03a/03b/04/05 plan output.

CLAUDE.md extensibility section ranks third-party hooks 1-6 with explicit
prose flagging Component::extend + addDynamicMethod as LAST RESORT and
mandating an onMetapixel* prefix to avoid collisions. Documents that
metapixel.event.before_dispatch listeners must not mutate event_id /
event_time (dedup contract anchor).
EOF
)"
```
  </action>
  <verify>
    <automated>cd plugins/logingrupa/metapixel &amp;&amp; composer qa 2&gt;&amp;1 | tail -5 | grep -Eq '(OK|PASS|0 errors|tests passed|No issues found)' &amp;&amp; git log -1 --pretty=format:'%s' | grep -q 'tooling deltas' &amp;&amp; git diff-tree --no-commit-id --name-only -r HEAD | sort -u | grep -E '(phpstan\.neon|phpunit\.xml|CLAUDE\.md|\.gitkeep)' | wc -l | xargs test 3 -le</automated>
  </verify>
  <done>composer qa exits 0; commit on HEAD touches at least phpstan.neon + phpunit.xml + CLAUDE.md (plus optional .gitkeep); commit message references P-01 PHPStan enforcement.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| phpstan.neon disallowed-calls config → code-quality enforcement | A typo in the rule FQN silently disables the rule. Task 1's spike + Task 2's belt-and-suspenders (ban both facade AND class FQNs) reduces the blast radius. |
| CLAUDE.md convention → future Claude planner / executor | A future Claude session reads CLAUDE.md as project lock. The `LAST RESORT` framing on Component::extend is normative; future plans must honor it. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-02-01 | Tampering | A future plan author adds an adapter that calls `Site::setSite()` to "fix" a site_id problem | mitigate | phpstan.neon `disallowedMethodCalls` with `disallowIn` scoping fires the rule on `classes/Adapter/*`. composer qa fails the PR. The error message names SiteResolver::forSubject as the correct path. |
| T-02-02-02 | Spoofing | phpstan rule's FQN is wrong; rule silently passes everything | mitigate | Task 1 spike verifies. Task 2 bans both facade and underlying class for belt-and-suspenders. If both turn out wrong, the rule is harmless (allows what was already allowed); the test coverage in plan 02-04 + 02-06 + 02-07 catches the actual cross-context bug at runtime. |
| T-02-02-03 | Repudiation | A reviewer claims they did not know Component::extend was discouraged | accept | CLAUDE.md is checked into git; reviewer responsibility to read. The numbered list + LAST RESORT framing makes the convention unmistakable. |
| T-02-02-04 | Information Disclosure | phpstan.neon leaks information about internal architecture | accept | phpstan.neon is in the public repo. The rules describe extension boundaries — which is exactly what the marketplace docs (Phase 5 CUSTOM-ADAPTERS.md) advertise. No secrets. |
| T-02-02-05 | Denial of Service | An overly-strict disallowedMethodCalls rule blocks legitimate Phase 4 middleware code that needs Request access | accept | The deny-list scopes to adapter/queue/event dirs ONLY; middleware/, controllers/, components/ are unaffected. Phase 4 HOST-04 EnsureFbpFbcCookies sits under middleware/, so its Request access is permitted by default. |
| T-02-02-06 | Elevation of Privilege | A test file under tests/Contract/Adapter inherits the wrong test base | accept | Pest.php (Phase 1) binds MetapixelTestCase to tests/Unit + tests/Feature; ShopaholicAdapterTestCase to tests/Unit/Adapter/Shopaholic + tests/Feature/Adapter/Shopaholic. Contract subdir is NOT bound — plan 02-07 ships the contract test base under classes/Testing/ (per H-3 resolution) and concrete subclasses extend it directly. No privilege escalation risk in this plan. |

</threat_model>

<verification>
## Goal-Backward Reachability Audit

1. "phpstan.neon bans SiteManager + Request + request() inside adapter/queue/event dirs via disallowIn deny-list (H-1)" — Task 2 writes the rules; spike (Task 1) verifies FQNs.
2. "phpunit.xml Metapixel Adapter Tests includes Contract dir" — Task 3 edits XML.
3. "phpunit.xml source coverage covers classes + models" — Task 3 extends include block.
4. "CLAUDE.md ranks third-party hooks; Component::extend = LAST RESORT" — Task 4 rewrites Extensibility section.
5. "composer qa still exits 0 after the rules land" — Task 5 verifies.

No must-have is UNREACHABLE.

## Multi-Source Coverage Audit

| Source item | Type | Coverage | Notes |
|-------------|------|----------|-------|
| REQ ADAP-06 SECONDARY (PHPStan disallowed-calls bans SiteManager / request / Request in adapter/queue/event dirs) | Requirement | Task 2 | ADAP-06 PRIMARY (SiteResolver::forSubject logic) lands in plan 02-04 |
| CONTEXT D-17 (SiteResolver::forSubject only authoritative; PHPStan bans SiteManager/Request) | Decision | Task 2 | Verified |
| RESEARCH §5.1 disallowedMethodCalls config shape | Reference | Task 2 | Followed verbatim — `disallowIn` deny-list scoped to 3 dirs (H-1 lock) |
| RESEARCH §5.3 phpunit.xml Contract dir + source coverage | Reference | Task 3 | Followed verbatim |
| RESEARCH §9 A1 (Site facade FQN [ASSUMED]) | Risk | Task 1 | RESOLVED via spike before rule writes |
| PITFALLS P-01 (cross-context resolution drift — static enforcement) | Pitfall | Task 2 | Owned (disallowIn deny-list across 3 dirs; H-1) |
| PITFALLS P-13 (Component::extend unbounded surface — convention only) | Pitfall | Task 4 | Owned (CLAUDE.md ranks Component::extend LAST RESORT with `onMetapixel*` prefix mandate) |
| Plan-checker H-1 (allowIn → disallowIn flip) | Revision | Task 2 | Reverts the original plan's allowIn-with-classes/Helper-and-classes/Meta wildcard; restores RESEARCH §5.1 disallowIn deny-list verbatim |
| Plan-checker M-8 (git diff file count) | Revision | Task 5 verify | `test 3 -le` (at least 3 files) accepts the optional .gitkeep fallback file |
| CONTEXT "no comment pollution" | Constraint | All tasks | phpstan/phpunit XML is config not code (comments OK); CLAUDE.md uses standard markdown prose |

No gaps. RESEARCH §9 A4 (Pest 4 `pest()->extend()` vs `uses()` — plan 02-06/07 picks); not relevant to this plan.

## Acceptance gate

`composer qa` exits 0 from `plugins/logingrupa/metapixel/` after Task 5's commit. The disallowedMethodCalls rules are dormant (no code path in plans 01-01..02-01 violates them) but live for plans 02-04, 02-06, and all Phase 3+ adapter code.
</verification>

<success_criteria>
Plan 02-02 ships when ALL of the following hold:

1. `phpstan.neon` has `disallowedMethodCalls` block with at least 2 rules (SiteManager + Request) using `disallowIn` scoping (H-1 — NOT `allowIn`); banned-call message references `SiteResolver::forSubject` as the correct path. Scope is exactly the 3 dirs: `classes/Queue/*`, `classes/Event/*`, `classes/Adapter/*`.
2. `phpstan.neon` `disallowedFunctionCalls` block adds a `request()` entry with the same `disallowIn` scoping.
3. `phpunit.xml` `Metapixel Adapter Tests` testsuite includes `./tests/Contract/Adapter`; new `Metapixel Contract Tests` testsuite present; `<source><include>` covers `Plugin.php`, `./classes`, `./models`.
4. `plugin CLAUDE.md` Extensibility section ranks hooks 1–6; flags Component::extend + addDynamicMethod as LAST RESORT; mandates `onMetapixel*` dynamic-method prefix; warns event_id mutation in before_dispatch.
5. `composer qa` exits 0 from `plugins/logingrupa/metapixel/`.
6. Single commit on HEAD touches phpstan.neon + phpunit.xml + CLAUDE.md (plus optional .gitkeep — M-8 verify allows >= 3).
7. Site facade + SiteManager FQNs in phpstan.neon are VERIFIED (via Task 1 grep spike) — not the literal `[ASSUMED]` placeholder strings from RESEARCH.md.
</success_criteria>

<output>
After completion, create `plugins/logingrupa/metapixel/.planning/phases/02-adapter-system-core-contracts-registry-extension-hooks/02-02-SUMMARY.md` documenting:

- Single commit SHA.
- Site facade + SiteManager FQNs verified (output from `/tmp/site-fqn-spike.txt`).
- `composer qa` tail output proving green.
- Phase 2 plan-state update: 02-02 closed; disallowIn deny-list scoping is now live for adapter/queue/event dirs. Plans 02-03a, 02-03b, 02-04, 02-05, 02-06 land code into those dirs and benefit from the static enforcement.
- Spike log shows which FQN approach phpstan landed on (facade + class, or class-only).
</output>

## Revision History
- 2026-05-17 R1: Address plan-checker findings H-1 (Task 2 + interfaces block flip from `allowIn` allow-list back to `disallowIn` deny-list scoped to the three dirs per RESEARCH §5.1 verbatim — eliminates the silent classes/Helper/* + classes/Meta/* exemption that defeated P-01 enforcement for SiteResolver / EventLogWriter / MetaClient), M-8 (Task 5 verify uses `test 3 -le` to accept the optional `.gitkeep` fallback), M-1 (Task 1 spike exit criterion hardened — file must be non-empty AND match the SiteManager/Cms/Site grep).
