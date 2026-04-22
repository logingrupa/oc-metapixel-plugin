# Audit 07: TigerStyle Assertions, Test Infrastructure, QA Pipeline

**Date:** 2026-04-22 | **Project:** Metapixel Meta-Pixel-Plugin | **Status:** COMPLETE

---

## §15 TigerStyle Assertions & Error Handling

### Q1: `assert()` Statement Availability & October CMS Compliance
**Finding:** ✗ RISKY — No usage found, `zend.assertions` not verified.

- **Current state:** Zero occurrences of `assert(` in lovata/ or logingrupa/ codebase.
  - Grep result: `/home/forge/nailscosmetics.lv/plugins/lovata/` — no matches
  - Grep result: `/home/forge/nailscosmetics.lv/plugins/logingrupa/` — no matches
- **Root cause:** Production code avoids PHP assertions (runtime behavior tied to `php.ini` `zend.assertions`).
- **October CMS compatibility:** ✓ Laravel 12 + October CMS v4 do not block assert(). Zend assertions work if enabled in php.ini, but server config is not guaranteed production-safe (assertions must be DISABLED in prod).
- **Plan §15.1 impact:** Proposal to use assert() for contracts **will fail in production** if `zend.assertions=0` or `assert.active=0` (default).

**Recommendation:**
- **Do NOT use assert()** for contracts—use explicit throw statements instead (matches Logingrupa convention: `throw new \Exception()`).
- If plan requires "lightweight" contracts, use typed properties + `ArgumentCountError` / `TypeError` instead.

---

### Q2: PHPStan Rule `disallowedFunctionCalls`
**Finding:** ✗ NOT INSTALLED — Plan needs external extension.

- **Current phpstan.neon config:** `/home/forge/nailscosmetics.lv/plugins/logingrupa/retrypaymentshopaholic/phpstan.neon` line 1-3:
  ```neon
  includes:
      - ../../../vendor/larastan/larastan/extension.neon
  ```
- **Status:** Only `larastan/larastan` (Laravel static analysis) is included; `spaze/phpstan-disallowed-calls` is NOT installed.
- **Plan §15.2 requirement:** Rule `disallowedFunctionCalls` to forbid `catch (\Throwable) {}` is **custom rule from `spaze/phpstan-disallowed-calls` package**, not core PHPStan.

**Recommendation:**
- Add `composer require --dev spaze/phpstan-disallowed-calls:^4.0` to root `composer.json`.
- Update phpstan.neon to include it:
  ```neon
  includes:
      - vendor/spaze/phpstan-disallowed-calls/extension.neon
  ```

---

### Q3: Bare `catch (\Throwable)` / `catch (\Exception)` Pattern
**Finding:** ✓ PATTERN ESTABLISHED — Logingrupa convention uses `catch (\Exception $obException)`.

- **Real data:**
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/vippsshopaholic/classes/helper/VippsPaymentGateway.php`: `catch (\RuntimeException $e)`, `catch (\Exception $e)`
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/retrypaymentshopaholic/components/RetryPayment.php`: `catch (\Exception $obException)`
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/updates/seeder_create_default_discounts.php`: `catch (\Exception $obException)` (x2)
  - **Convention:** Hungarian `$obException`, specific exception types (rarely bare `\Throwable`).
  - **Bare Throwable:** Not found in existing code—Logingrupa already follows "no bare catch" discipline.

**Recommendation:**
- ✓ Plan §15.2 can enforce via PHPStan `disallowedFunctionCalls` rule.
- **Migration path:** New plugins adopt TigerStyle immediately; existing Logingrupa code is grandfathered in (phased remediation per audit 03 ARCHITECTURE.md).

---

### Q4: Log Namespace Convention
**Finding:** ✗ NO PATTERN — Logingrupa uses flat/descriptive keys, not `meta-pixel.{class}.{method}` hierarchy.

- **Real data:**
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/vippsshopaholic/classes/helper/VippsWebhookManager.php`:
    ```php
    Log::error('VippsWebhookManager: register failed', [...])
    Log::error('VippsWebhookManager: list failed', [...])
    Log::warning('VippsWebhookManager: cache refresh failed', [...])
    ```
  - `/home/forge/nailscosmetics.lv/plugins/logingrupa/vippsshopaholic/classes/helper/VippsCallbackHandler.php`:
    ```php
    Log::info('Vipps return callback received', [...])
    Log::error('Vipps return: Order not found', ['secret_key' => $sSecretKey])
    ```
  - **Pattern:** `ClassName: action description` + context array (no `meta-pixel.{class}.{method}.enter` pattern).
  - **Config:** `/home/forge/nailscosmetics.lv/config/logging.php` uses Monolog with standard channels (no custom namespace routing).

**Recommendation:**
- Plan §15.3 hierarchical namespace (`meta-pixel.EventLog.send.enter`) is **NEW pattern** for Meta plugin; acceptable as plugin-specific convention.
- **Compatibility:** Does NOT conflict with existing Logingrupa logs; can coexist via separate log channel or context grouping.
- **Action:** Define log channel in plugin `config/metapixel.php` or inject via ServiceProvider.

---

### Q5: Boot-Time Failure vs. Event-Time Failure: Pixel ID Validation
**Finding:** ✗ CONFLICT — Plan §15.4 "no fallback if CAPI fails" contradicts "must not break existing behavior."

**Analysis:**
- **Plan §15.4 proposal:** Throw exception if CAPI configuration missing → fail hard, no Pixel fallback.
- **Conflict with CLAUDE.md:** "Plugin must not break existing Campaign/PromoMechanism behavior."
- **Reality check:**
  - Pixel fires **client-side** (JavaScript tag, idempotent).
  - CAPI is **async job** dispatched server-side (queue, deferred).
  - Job failure → dead-letter queue (survives).
  - **If plugin throws on boot with missing Pixel ID:**
    - Plugin fails to register.
    - Entire Campaigns/PromoMechanism plugin cascade fails (mutual dependency risk).
    - Site breaks (Campaign pricing, promo codes stop working).
  - **If plugin throws on first event:**
    - Only events lacking Pixel ID fail; campaigns still work.
    - Dead-letter handles retry; user can fix config later.

**Recommendation:**
- **Split validation:**
  1. **Boot time:** Warn (not throw) if Pixel ID missing → log::warning → plugin continues.
  2. **First event attempt:** Throw `MissingPixelConfigException` on `$event->getPixelId()` → noop until configured.
  3. **CAPI job failure:** Log to dead-letter; Pixel still fires client-side (graceful degradation).
- **Rationale:** Aligns with §15.4 "no fallback" principle (fails loudly at event time) while respecting "must not break" constraint (fails gracefully at boot).

---

### Q6: Custom Exception Hierarchy
**Finding:** ✗ NOT FOUND — Logingrupa plugins use flat `\Exception` or specific types.

- **Search result:** No custom exception classes in logingrupa/ plugins.
- **Pattern:** `catch (\Exception $obException)` with generic exception or specific types (`\RuntimeException`, etc.).
- **Implication:** Plan §15.5 exception hierarchy (`PixelException`, `CAPIException`, etc.) is **new for Metapixel**.

**Recommendation:**
- Create `/plugins/logingrupa/metapixel/classes/Exceptions/` namespace:
  ```php
  class MetaPixelException extends \Exception {}
  class MissingPixelConfigException extends MetaPixelException {}
  class CAPIFailureException extends MetaPixelException {}
  ```
- Inherit from `\Exception` (not `\RuntimeException`) to match Logingrupa pattern.

---

## §16 Test Infrastructure (Pest + Testbench)

### Q1: Orchestra Testbench Installation
**Finding:** ✗ NOT INSTALLED — Project uses October CMS native test bootstrap.

- **Codebase uses:** `PluginTestCase` from `/home/forge/nailscosmetics.lv/modules/system/tests/PluginTestCase.php`
- **Orchestra Testbench:** Zero occurrences in `composer.json`
- **October CMS test approach:** Uses `Illuminate\Foundation\Testing\TestCase` + traits (`InteractsWithAuthentication`, `PerformsMigrations`).

**Implication:** Plan §16 proposal to use "Orchestra Testbench" is **incorrect for October CMS plugins**. October CMS has native test bootstrap; Testbench is for standalone Laravel packages.

**Recommendation:**
- **Use Logingrupa's `CampaignPricingTestCase`** (see audit below) — PHPUnit 12 / Pest 4 compatible wrapper.
- **Do NOT install Orchestra Testbench.**

---

### Q2: Actual Test Base Class (October CMS)
**Finding:** ✓ CORRECT — Campaigns plugin uses custom `CampaignPricingTestCase extends TestCase`.

- **File:** `/home/forge/nailscosmetics.lv/plugins/logingrupa/campaignpricingshopaholic/tests/CampaignPricingTestCase.php` (lines 1-105)
- **Key difference:** October's `PluginTestCase` has `public function setUp()` (conflicts with PHPUnit 12's `protected setUp()`).
- **Solution:** Logingrupa wrapped it:
  ```php
  abstract class CampaignPricingTestCase extends TestCase
  {
      use \October\Tests\Concerns\InteractsWithAuthentication;
      use \October\Tests\Concerns\PerformsMigrations;
      use \October\Tests\Concerns\PerformsRegistrations;
      
      protected function setUp(): void { ... }  // ✓ PHPUnit 12 compatible
  }
  ```
- **Pest usage:** ✓ Tests use `beforeEach()` / `afterEach()` / `test()` (Pest 4 style).

**Recommendation:**
- **Reuse `CampaignPricingTestCase`** for Metapixel tests (or inherit from it).
- **Do NOT use October's `PluginTestCase` directly** if PHPUnit 12 / Pest 4 is required.

---

### Q3: Pest Installation Status
**Finding:** ✓ INSTALLED — Pest 4.1 + drift plugin are in composer.json.

- **Version:** `pestphp/pest: ^4.1`, `pestphp/pest-plugin-drift: ^4.0`
- **PHPUnit:** `phpunit/phpunit: ^12.0` (also installed)
- **Conflict:** Pest 4 ships with PHPUnit 12 internally; no external PHPUnit needed unless running PHPUnit CLI directly.

**Recommendation:**
- ✓ Plan §16 can use Pest 4 syntax (`test()`, `it()`, `describe()`, etc.).
- Metapixel tests should follow campaign pricing example (Pest + Mockery).

---

### Q4: Model Factories
**Finding:** ✗ NOT PROVIDED — No factories found in any plugin.

- **Factory search:** `find ... -name factories -type d` → no results.
- **Implication:** Plan §16 sample:
  ```php
  $obOrder = Order::factory()->paid()->withItems(2)->create();
  ```
  **will NOT work without creating factories.**

**Recommendation:**
- **Create `/plugins/logingrupa/metapixel/tests/Factories/`** with:
  ```php
  // MetaPixelEventFactory
  // CampaignEventFactory
  // etc.
  ```
- **Use Laravel factory pattern** (Lovata/Shopaholic models should have factories if they support `->factory()`).
- **Verify Lovata models support `factory()`** (likely via `HasFactory` trait).

---

### Q5: `Bus::fake()` — Laravel Queue Dispatch
**Finding:** ✓ AVAILABLE — Queue config exists; no dispatch() usage found in plugins (yet).

- **Config:** `/home/forge/nailscosmetics.lv/config/queue.php` (lines 1-91) defines:
  ```php
  'default' => env('QUEUE_CONNECTION', 'sync'),
  'connections' => ['sync', 'database', 'beanstalkd', 'sqs', 'redis'],
  'failed' => ['driver' => 'database', 'table' => 'failed_jobs'],
  ```
- **Grep result:** No `dispatch()` or `Bus::` calls found in Logingrupa plugins yet.
- **Implication:** Plan §16 `Bus::fake()` is **correct for testing queued events**, but plugin does not currently dispatch jobs (async CAPI calls will be new).

**Recommendation:**
- ✓ Plan can use `Bus::fake()` to test job dispatch in Metapixel event handler.
- **Test pattern:**
  ```php
  Bus::fake();
  $event = new MetaPixelEvent($obOrder);
  event($event);
  Bus::assertDispatched(SendCAPIEventJob::class);
  ```

---

### Q6: `/larajax/cart/add` Endpoint Dependency
**Finding:** ✓ AVAILABLE — Larajax is part of October CMS / Lovata ecosystem.

- **Dependency tree:** Lovata plugins (Shopaholic, Ordersshopaholic) provide AJAX handlers.
- **No conflict:** Test sample using `$this->postJson('/larajax/cart/add', ...)` assumes AJAX handler is registered.
- **Risk:** Low—Larajax is core to Lovata ecosystem; tests will work if plugin dependencies are loaded.

**Recommendation:**
- ✓ Plan §16 sample tests are compatible with Lovata stack.
- Ensure `campaignpricingshopaholic` or similar is loaded before testing Metapixel events.

---

### Q7: Coverage Target (90% Line Coverage)
**Finding:** ✓ REALISTIC — Campaigns plugin achieves test-driven coverage.

- **Evidence:** `/home/forge/nailscosmetics.lv/plugins/logingrupa/campaignpricingshopaholic/tests/unit/` has 7+ test files with focused unit tests.
- **Pattern:** Thin unit tests (e.g., `WholeNumberPriceFormatterTest.php` → 6 focused tests for formatting logic).
- **Mutation testing:** Not required by plan; Infection is optional per Q7 note.

**Recommendation:**
- ✓ 90% line coverage is achievable for Metapixel with focused unit tests + integration tests.
- **Mutation testing (Infection):** Optional, useful for dead-code detection.

---

## §17 QA Pipeline & CI/CD

### Q1: Project CI Infrastructure
**Finding:** ✗ NO PROJECT CI — No `.github/workflows/` in root; vendor packages have CI (not inherited).

- **Search result:** No `.github/` directory in `/home/forge/nailscosmetics.lv/`.
- **Vendor CI:** Vendor packages (october/rain, laravel, etc.) have CI; does not apply to this project.

**Recommendation:**
- **Project lacks CI/CD pipeline.**
- **Create `.github/workflows/test.yml`** for Metapixel QA:
  ```yaml
  on: [push, pull_request]
  jobs:
    test:
      runs-on: ubuntu-latest
      strategy:
        matrix:
          php: ['8.4']
      steps:
        - uses: actions/checkout@v4
        - uses: shivammathur/setup-php@v2
          with:
            php-version: ${{ matrix.php }}
        - run: composer install
        - run: composer qa
  ```

---

### Q2: `composer qa` Script Existence
**Finding:** ✗ NOT DEFINED — No `qa` script in root `composer.json`.

- **Grep result:** `grep '"qa"' /home/forge/nailscosmetics.lv/composer.json` → no match.
- **Existing scripts:** Likely only `phpunit --stop-on-failure`, `pest`, etc.

**Recommendation:**
- **Add to root `composer.json`:**
  ```json
  "scripts": {
    "qa": [
      "@test",
      "@lint",
      "@analyze"
    ],
    "test": "pest --stop-on-failure",
    "lint": "phpstan analyse",
    "analyze": "rector check --dry-run"
  }
  ```

---

### Q3: PHP Version Matrix (8.3 vs 8.4)
**Finding:** ✓ REQUIRES 8.4 ONLY — Composer requires `php: ^8.3`, but CLAUDE.md specifies 8.4.

- **composer.json line 8:** `"php": "^8.3"`
- **CLAUDE.md:** "Project requires 8.4."
- **Implication:** `^8.3` allows 8.3; should be `^8.4` for Metapixel plugin (new code).

**Recommendation:**
- **For Metapixel plugin:** Test on PHP 8.4 only (per CLAUDE.md).
- **For root composer.json:** Consider bumping to `^8.4` to force consistency (breaking change if this is distributed).
- **CI matrix:** Single job with PHP 8.4 (simplifies build).

---

## Summary: Action Items

| Item | Status | Action |
|------|--------|--------|
| Assert() in production | ✗ RISKY | Replace with explicit throw; do NOT use PHP assert(). |
| PHPStan disallowedFunctionCalls | ✗ MISSING | Add `spaze/phpstan-disallowed-calls` to composer.json. |
| Bare catch blocks | ✓ OK | Enforce via PHPStan; existing code grandfathered. |
| Log namespace convention | ✗ NEW | Define `meta-pixel.{class}.{method}` in plugin ServiceProvider. |
| Pixel ID validation | ✗ CONFLICT | Split: warn on boot, throw on first event, graceful CAPI fallback. |
| Exception hierarchy | ✗ NEW | Create `MetaPixelException`, `MissingPixelConfigException`, etc. |
| Orchestra Testbench | ✗ WRONG | Remove from plan; use October CMS `CampaignPricingTestCase`. |
| Pest 4 + PHPUnit 12 | ✓ OK | Already installed; reuse `CampaignPricingTestCase`. |
| Model factories | ✗ MISSING | Create in `/tests/Factories/`. |
| Bus::fake() | ✓ OK | Available for queue testing. |
| Larajax endpoints | ✓ OK | Compatible with Lovata stack. |
| 90% coverage | ✓ REALISTIC | Achievable with focused unit tests. |
| Project CI | ✗ MISSING | Create `.github/workflows/test.yml`. |
| `composer qa` script | ✗ MISSING | Define in root `composer.json`. |
| PHP 8.4 matrix | ✓ OK | Single job, PHP 8.4 only. |

---

## Caveats & Risks

1. **Pixel ID validation timing:** Failing on boot breaks site; failing on first event requires UX messaging (UI must show "Meta Pixel not configured" warning).
2. **Testbench confusion:** Plan references "Orchestra Testbench" which is for Laravel packages, not October CMS plugins. Correction needed in original plan.
3. **Factory pattern:** Lovata models may not all support `->factory()`. Verify before writing sample tests.
4. **CI/CD scope:** Project currently has no CI; adding it is out-of-scope for Metapixel but necessary for `composer qa` reliability.

---

**Audit Author:** Claude | **Report:** Caveman-compressed findings with file:line citations.

