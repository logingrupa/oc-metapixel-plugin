# Agent prompt — Phase 3.1 dead-code + test-failure cleanup

Repo root: `/home/forge/nailscosmetics.lv`
Plugin root: `plugins/logingrupa/metapixelshopaholic` (nested git repo, branch `master`)
Plugin version: v1.1.1 (post phase 03.1-07 cross-context site_id symmetry)

## Why this work exists

Phase 03.1-07 shipped clean (24/24 must_haves verified, `git log --grep="03.1-07"` shows 14 atomic commits 736b3e3..015b374). Subsequent migration cleanup (08a0d12) collapsed `updates/` to 2 surviving migrations + clean version.yaml lineage 1.0.0 → 1.1.0 → 1.1.1. BUT:

1. The phase REVIEW.md flagged 3 medium + 6 low findings, partially unaddressed.
2. 6 pre-existing test failures from prior phases lingered through 03.1-07 unchanged ("baseline" in 03.1-06 SUMMARY). They block `composer qa` exit 0 — milestone v1.1.1 can't claim "qa green" until fixed or formally baselined.
3. Two top-level planning docs (`.planning/PLAN.md`, `.planning/PLAN-v2-original.md`) still narrate the pre-3.1 column-based design (`meta_purchase_event_id` on Orders table). Misleads anyone onboarding.
4. Several test files carry stale comments + non-UUIDv4 literals that work today but trip future contributors.

## Your job

Apply DRY + SRP discipline across the 12 dead-code items + 6 failing tests below. Land atomic commits per logical concern. Caveman-compress any new/edited comments per project convention (PHPDoc functional tags preserved verbatim).

**Tiger-Style + project CLAUDE.md rules apply** (Hungarian notation, explicit return types, methods < 70 LOC, every catch logs+rethrows or carries `// silent: <reason>`, no drive-by refactors outside listed scope).

## Files-to-read (start here)

- `plugins/logingrupa/metapixelshopaholic/CLAUDE.md` — Tiger-Style + Hungarian conventions
- `plugins/logingrupa/metapixelshopaholic/.planning/STATE.md` — current project state
- `plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/03.1-07-REVIEW.md` — 9 findings
- `plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/03.1-07-SUMMARY.md` — phase delivery record
- `plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/03.1-06-SUMMARY.md` — documents the 6 failing tests as "baseline"

## Workflow

Run `composer test` from `plugins/logingrupa/metapixelshopaholic/` after each logical commit. Don't merge unrelated fixes into a single commit. Use the conventional-commit prefixes the plugin uses: `fix(03.1-cleanup):`, `refactor(03.1-cleanup):`, `test(03.1-cleanup):`, `docs(03.1-cleanup):`, `chore(03.1-cleanup):`.

---

## TRACK 1 — Production-code dead/stale (DRY + SRP)

### T1.1 — Fix MED-01: EventLog.php:45 stale docblock

`models/EventLog.php` class-level docblock or property docblock around line 45 says site_id is populated via `SiteResolver::getActiveSiteId()`. After 03.1-07 REFAC-13 the writer is pure I/O — caller passes `?int $iSiteId` from `SiteResolver::forOrder($obOrder)`. Update docblock to reflect:

- Writer accepts explicit `?int $iSiteId` from caller (DRY — resolution at call sites).
- Order-scoped subjects (Watcher, SendCapiEvent, PurchasePixel) use `SiteResolver::forOrder($obOrder)`.
- Future non-Order subjects (Lead, AddToCart, ViewContent — Phase 4) can use `SiteResolver::getActiveSiteId()` if request-scoped.

Caveman-compress. Preserve `@property`/`@var` tags verbatim.

Commit: `docs(03.1-cleanup): EventLog model docblock — reflect REFAC-13 caller-supplied site_id`

### T1.2 — Decision on MED-02: `EventLogWriter::record` 7th param default

Currently:
```php
public static function record(
    string $sEventId,
    string $sEventName,
    string $sChannel,
    object $obSubject,
    ?string $sSecretKey,
    int $iEventTime,
    ?int $iSiteId = null,   // ← MED-02
): bool
```

Default null = Phase 4 caller forgetting to pass site_id silently writes NULL row (cross-context bug class resurrection). Tiger-Style fail-fast prefers required.

**Decision tree:**

(A) **Make required (recommended)** — drop the `= null` default. Existing 2 callers (`SendCapiEvent::raceFenceWon`, `PurchasePixel::onMarkFired`) already pass explicit value. Tests in `MultiSiteEventLogTest` and `SendCapiEventTest::test_handle_then_failed_does_not_double_write_failed_event` may pass null implicitly — audit + fix. Single-site path: caller passes `SiteResolver::forOrder($obOrder)` which returns null when Order.site_id is null — caller-explicit-null is the contract.

(B) **Keep default + add `@deprecated` notice** — soft migration. Defer Phase 4 hardening.

Pick (A) unless the audit surfaces > 3 caller sites that intentionally rely on the default — in which case escalate to user.

**Auditing callers:** `grep -rnE 'EventLogWriter::record' plugins/logingrupa/metapixelshopaholic/ --include='*.php' | grep -v 'classes/helper/EventLogWriter.php'`

Commit: `refactor(03.1-cleanup): EventLogWriter::record requires explicit ?int site_id (fail-fast)` — or if (B): `docs(03.1-cleanup): EventLogWriter::record deprecate null default for site_id`

### T1.3 — LOW-06: SiteResolver class-level PHPDoc bloat

`classes/helper/SiteResolver.php` lines 11-43: 33 lines of class-level PHPDoc for a class with 2 public static methods (`forOrder`, `getActiveSiteId`). Compress to ~8 lines: purpose, two-method contract, REFAC-04/REFAC-12 `@see` refs. Method-level docblocks already carry the detail.

Apply caveman. Preserve `@see` tags.

Commit: `docs(03.1-cleanup): SiteResolver class-level PHPDoc — caveman compress`

---

## TRACK 2 — Test cleanup (DRY)

### T2.1 — LOW-01: replace inline FQN `\Ramsey\Uuid\Uuid::uuid4()` with `use` import

`tests/Feature/MultiSiteCrossContextTest.php` has 3 hits of inline FQN `\Ramsey\Uuid\Uuid::uuid4()->toString()`. DRY violation — other tests in the same dir use `use Ramsey\Uuid\Uuid;` + `Uuid::uuid4()`.

Add `use Ramsey\Uuid\Uuid;` to the imports block (alphabetical), replace 3 inline FQN call sites with `Uuid::uuid4()->toString()`.

Verify: `composer test -- --filter MultiSiteCrossContextTest` — all 3 methods still green.

Commit: `refactor(03.1-cleanup): MultiSiteCrossContextTest use Ramsey Uuid import (DRY)`

### T2.2 — LOW-02 + LOW-03: replace hardcoded non-UUIDv4 event_id literals with `Uuid::uuid4()`

Two test files use 32-char hex literals as `event_id`:

- `tests/Feature/SendCapiEventEventLogTest.php:237` — `'22222222-2222-2222-2222-222222222222'` or similar
- `tests/Feature/PurchaseEndToEndIntegrationTest.php:340` — same pattern

These work today because the test paths bypass `PayloadBuilder::build()`'s UUIDv4 validation. SUMMARY.md already documents this class of issue + the `MultiSiteCrossContextTest` fix that replaced literals with `Uuid::uuid4()->toString()`.

Apply same fix here. Capture the generated UUID in a `$sEventId` local before use so the test can assert against it.

Pattern from `MultiSiteCrossContextTest` is the DRY reference — mirror it.

Run filtered tests after each file edit.

Commit (one per file, atomic):
- `test(03.1-cleanup): SendCapiEventEventLogTest replace hex literal with Uuid::uuid4()`
- `test(03.1-cleanup): PurchaseEndToEndIntegrationTest replace hex literal with Uuid::uuid4()`

### T2.3 — LOW-04: stale `$casts int` rationale comment

`tests/Feature/PurchaseEndToEndIntegrationTest.php:482-487`: comment explains that `$casts int` coerces null → 0 — but 03.1-07 fixed that path (Order.site_id null stays null all the way through). Comment is now wrong.

Either:
- Delete comment (preferred — code is self-explanatory after the fix)
- Replace with a one-line caveman note referencing 03.1-07 REFAC-12

Commit: `test(03.1-cleanup): PurchaseEndToEndIntegrationTest remove stale $casts rationale comment`

### T2.4 — LOW-05: missing `declare(strict_types=1)`

`tests/Feature/OrderStatusWatcherEventLogTest.php` — file header missing `declare(strict_types=1);` after `<?php`. Pre-existing issue but flagged. Add it. Verify tests still green.

Commit: `test(03.1-cleanup): OrderStatusWatcherEventLogTest add declare(strict_types=1)`

### T2.5 — `tests/Feature/SendCapiEventTest.php` setUp docstring sync

After the unique-idx merge (commit 08a0d12), `SendCapiEventTest::setUp` no longer calls `(new AddUniqueIndexToFailedEvents)->up()`. Caveman-compress the existing setUp comment (currently mentions WR-07 layered migration — incorrect post-merge).

Commit: `test(03.1-cleanup): SendCapiEventTest setUp comment — reflect unique idx inline`

---

## TRACK 3 — 6 pre-existing test failures (diagnose + fix OR formally baseline)

These were "baseline" through phases 03.1-01..06 and 03.1-07 without diagnosis. Time to either fix or document why they're acceptable.

**Approach per test (in order, lowest-risk first):**

### T3.1 — `EventLogTest::test_event_id_validation_rejects_longer_than_36`

Test asserts validation REJECTS event_id > 36 chars. Currently throws `ModelException` (October Rain validation) instead of returning false. Likely the model has `public $throwOnValidation = true` or the assertion uses `Schema::create`/`Model::validate` shape mismatch.

Fix path: either
- Update test assertion to `expectException(ModelException::class)` and assert message contains "must not be greater than 36 characters"
- OR change `Model::save()` call site in the test to use `$obModel->save(['force' => true]) === false` shape

Prefer ModelException assertion — matches October Rain's contract.

Commit: `test(03.1-cleanup): EventLogTest expectException ModelException for >36 char event_id`

### T3.2 — `ExceptionHierarchyTest`

Asserts translation key `logingrupa.metapixelshopaholic::lang.exception.missing_pixel_config` resolves to a string NOT containing `::lang.`. Currently the literal key is returned unresolved.

Hypothesis: October translation loader not booted in hermetic test base. Likely fix in `tests/MetapixelTestCase.php` — register the plugin's `lang/` namespace via `App['translator']->addNamespace(...)` in `bootSystemSettings()` or a new `bootTranslations()` hook.

Audit `lang/en/lang.php` exists with key `exception.missing_pixel_config`. If yes, add namespace registration. If no, the test refers to a non-existent key — fix the test or add the key.

Commit: `fix(03.1-cleanup): MetapixelTestCase bootTranslations registers plugin lang namespace`

### T3.3 — `BootsWithoutPixelIdTest::test_isdisabled_returns_false_when_pixel_id_populated`

`Settings::isDisabled()` returns `true` when `pixel_id` populated — inverted. Either:
- `isDisabled` impl checks the wrong field (e.g., `kill_switch` instead of `pixel_id`)
- Settings cache stale — test sets pixel_id but cache returns null

Read `models/Settings.php::isDisabled()` + nearby methods. Check `Settings::clearInternalCache()` call in test setUp.

If impl is wrong → fix impl. If cache stale → fix test setUp to flush before read.

Commit: `fix(03.1-cleanup): Settings::isDisabled return false when pixel_id populated` OR `test(03.1-cleanup): BootsWithoutPixelIdTest clear Settings cache before isDisabled assertion`

### T3.4 — `EnsureFbpFbcCookiesTest`

Cookie `_fbp` SET when `ensure_fbp_fbc_server_side` toggle OFF. Middleware likely missing the feature-flag guard.

Read `middleware/EnsureFbpFbcCookies.php` (or wherever the impl lives — `grep -rn ensure_fbp_fbc` plugin tree). Verify there's a `if (!Settings::get('ensure_fbp_fbc_server_side')) return $next($request);` short-circuit BEFORE the cookie-set block.

If missing → add the guard. If present → test setup may not be flipping the toggle correctly.

Commit: `fix(03.1-cleanup): EnsureFbpFbcCookies middleware short-circuits when toggle OFF`

### T3.5 — `PurchasePixelEventLogGateTest::test_onmarkfired_second_call_returns_ok_true_no_duplicate`

Second `onMarkFired` call with same event_id returns wrong array shape. Test expects `['ok' => true, 'won_race' => false]` (success-for-caller idempotency), gets something else.

Read `components/PurchasePixel.php::onMarkFired` return-shape branches. The race-loser path (writer returns false → row exists already) should still return `ok=true` to the JS caller — keeps the front-end happy without re-firing.

If impl returns `['ok' => false]` on race-loss → fix the return shape. If shape is right but extra/missing keys → adjust whichever side is wrong.

Commit: `fix(03.1-cleanup): PurchasePixel onMarkFired race-loser returns ok=true won_race=false`

### T3.6 — `SendCapiEventEventLogTest::test_second_concurrent_dispatch_returns_false_no_http_post`

Race-loser path POSTed to Meta when it MUST NOT. The race-fence in `SendCapiEvent::handle()` should check writer result BEFORE the HTTP POST — if writer returns false (row already exists), skip the dispatch.

Read `classes/queue/SendCapiEvent.php::handle()` — find the writer call + the HTTP dispatch. Verify ordering: writer first, then `if ($bWon) $obClient->post(...)`.

If ordering is wrong → reorder. If ordering is right → trace why the race-fence isn't returning false on second dispatch (UNIQUE idx not applied? `INSERT IGNORE` semantics broken on SQLite?).

Commit: `fix(03.1-cleanup): SendCapiEvent::handle skip HTTP POST when race fence lost`

**For each T3 fix:** also confirm the pre-existing baseline 6 → 5 → 4 ... drops. Final goal: `composer test` exits 0.

**If a T3 item cannot be fixed without scope-creep:** document it in `tests/SKIP-BASELINE.md` (new file) with rationale, then add `->skip('baselined: <reason>')` to the test method — Pest accepts inline skip. Better to formally skip than leave silent red.

---

## TRACK 4 — Planning-doc cleanup

### T4.1 — Retire `.planning/PLAN.md` + `.planning/PLAN-v2-original.md`

Both narrate the dead `meta_purchase_event_id` column-based design. Three options:

(A) **Archive** — move to `.planning/archive/` with a `RETIRED.md` index pointing to `.planning/phases/03.1-event-log-refactor/BRIEF.md` as the canonical replacement.

(B) **Annotate** — prepend a one-block `> **SUPERSEDED 2026-05-13**: this document describes the pre-3.1 design. See `.planning/phases/03.1-event-log-refactor/BRIEF.md` for current architecture.` and leave the body alone.

(C) **Delete** — git history preserves them.

Pick (B) for least-friction (audit trail preserved, future readers immediately see "stale"). Apply both files.

Verify `.planning/README.md` still accurately points to current docs — adjust if needed.

Commit: `docs(03.1-cleanup): annotate PLAN.md + PLAN-v2-original.md as superseded by phase 03.1`

### T4.2 — Delete `updates/.gitkeep`

`updates/` has 3 entries (version.yaml + 2 migrations) — `.gitkeep` placeholder no longer needed.

Commit: `chore(03.1-cleanup): remove updates/.gitkeep — directory non-empty`

### T4.3 — `composer.json` `_comments` block

Non-standard composer key. Either:
- Move to a `COMPOSER.md` docblock next to `composer.json`
- Delete entirely (the PHP constraint rationale lives in CLAUDE.md tech-stack section)

Run `composer validate --strict` after edit to confirm no schema warnings.

Commit: `chore(03.1-cleanup): composer.json remove non-standard _comments key`

---

## TRACK 5 — Milestone close

### T5.1 — `composer qa` full pass

After Tracks 1-4 land:

```bash
cd plugins/logingrupa/metapixelshopaholic
composer pint-test     # PSR-2 + project pint.json
composer analyse       # phpstan level 10
composer phpmd         # plugin-scoped rules
composer test          # all green
composer qa            # composite — ALL exit 0
```

If `phpstan analyse` still reports the 2 pre-existing errors (`SendCapiEvent::extractFirstEvent` return-type widening + `EventLog::subject` MorphTo):
- Fix them if narrow (likely 5-line PHPDoc add)
- Otherwise regenerate baseline: `composer baseline` → commit `phpstan-baseline.neon`

Commit (composite): `chore(03.1-cleanup): composer qa green — phpstan + pint + phpmd + pest`

### T5.2 — Tag plugin git v1.1.1

After T5.1 green:

```bash
cd plugins/logingrupa/metapixelshopaholic
git tag -a v1.1.1 -m "Phase 3.1-07 cross-context site_id symmetry + 03.1-cleanup dead code removal"
```

Do NOT push without user confirmation.

### T5.3 — STATE.md advance

Append a closure line:

```yaml
status: phase-3.1-milestone-ready
stopped_at: "Phase 3.1 closed end-to-end. v1.1.1 + 03.1-cleanup landed. qa green. Awaiting operator: v1.1.1 deploy on .lv/.lt/.no + STAGING Scenario 5 per site + manual repair of 2 stranded rows on new.nailscosmetics.lv (or accept Pixel-miss). Next: /gsd-plan-phase 4 (Funnel Completion)."
```

Commit: `docs(03.1-cleanup): STATE.md milestone-ready closure`

---

## Constraints

1. **No drive-by refactors outside the 12 + 6 items above.** If you spot something else, add to a `FOLLOWUP.md` list — don't fix inline.
2. **Caveman comments on every PHP/HTM edit.** Functional PHPDoc tags preserved. Code/commits/security: normal style.
3. **Atomic commits — one logical concern per commit.** Conventional-commits prefix mandatory.
4. **No `--no-verify`.** Hooks default ON.
5. **No production-code touch in Tracks 2-4** (test + planning + composer only). Track 1 + Track 3 own all production-code edits.
6. **DRY:** every fix that pattern-matches against a fix you just made → factor a helper or reference the prior commit in the new commit body.
7. **SRP:** don't bundle "fix EventLog docblock" with "fix EventLog validation" — separate commits.
8. **Test after each commit.** `composer test` from plugin dir. Failures must be from items you haven't fixed yet OR formally documented.

## Final report shape

```
## Cleanup Complete

| Track | Items | Commits | Status |
|---|---|---|---|
| T1 prod | 3 | <list> | ✓ all green |
| T2 tests | 5 | <list> | ✓ |
| T3 fixes | 6 | <list> | ✓ |
| T4 docs | 3 | <list> | ✓ |
| T5 close | 3 | <list> | ✓ qa green, tagged |

### Tests delta
- Before: 171 passed / 6 failed
- After: 177 passed / 0 failed

### qa exit codes
- pint-test: 0 | analyse: 0 | phpmd: 0 | test: 0

### Followup deferred
[items added to FOLLOWUP.md, if any]
```
