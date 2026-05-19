# Phase 4: Settings rework — Multisite + TrustedHosts + Cookie + FailedEvents + translations — Pattern Map

**Mapped:** 2026-05-19
**Files analyzed:** 22 (new + modified)
**Analogs found:** 22 / 22 (100% — Phase 4 is heavy on idiomatic October/Lovata patterns already present in-tree)

## File Classification

| New / Modified File | New? | Role | Data Flow | Closest Analog | Match Quality |
|---------------------|------|------|-----------|----------------|---------------|
| `models/Settings.php` | modify | settings model | CRUD + per-site lookup | self (Phase 2 `lookupForSite` stub) + `plugins/lovata/toolbox/models/CommonSettings.php` (base) | exact |
| `models/settings/fields.yaml` | modify | YAML config | declarative form schema | self (current file) | exact |
| `models/FailedEvent.php` | modify | dead-letter model | CRUD | self (current file) | exact |
| `models/failedevent/columns.yaml` | new | YAML config | declarative list schema | `plugins/lovata/ordersshopaholic/models/paymentmethod/columns.yaml` (sibling) | exact |
| `updates/AddMultisitePixelIdAndToken.php` | new | migration (no-op) | schema-additive | `updates/AddPayloadToMetapixelEventLogTable.php` (in-plugin idempotent shape) | exact |
| `updates/AddDedupColumnsToFailedEvents.php` | new | migration | schema-additive (3 cols) | `updates/AddPayloadToMetapixelEventLogTable.php` (in-plugin) | exact |
| `updates/version.yaml` | modify | YAML registration | append | self | exact |
| `classes/helper/HostIndexResolver.php` | new | helper service (PSL wrapper) | request-response | `classes/helper/PluginGuard.php` (in-plugin `final class` + Hungarian + Log facade) + RESEARCH Pattern 4 | role-match |
| `console/RefreshPsl.php` | new | artisan command | file I/O + HTTP fetch | `console/PurgeEventLog.php` (in-plugin) | exact |
| `middleware/EnsureFbpFbcCookies.php` | new | HTTP middleware | request-response (cookie write) | RESEARCH Pattern 7 (fresh derivation per D-20); no in-plugin middleware analog yet | partial (research-derived) |
| `controllers/FailedEvents.php` | new | backend controller | request-response (List + AJAX) | `plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php` | exact |
| `controllers/failedevents/config_list.yaml` | new | YAML config | declarative list schema | `plugins/lovata/ordersshopaholic/controllers/paymentmethods/config_list.yaml` | exact |
| `controllers/failedevents/_list_toolbar.htm` | new | partial (toolbar) | template | `plugins/lovata/ordersshopaholic/controllers/paymentmethods/_list_toolbar.htm` | exact |
| `controllers/failedevents/index.htm` | new | partial (list page) | template | `plugins/lovata/ordersshopaholic/controllers/paymentmethods/index.htm` | exact |
| `classes/meta/MetaClient.php` | modify | API client | request-response | self (`sendForPixel` body, add `fetchTestEventsStatus`) | exact |
| `Plugin.php` | modify | plugin registration | boot wiring | self + `plugins/lovata/shopaholic/Plugin.php::registerSettings` | exact |
| `resources/data/public_suffix_list.dat` | new | data fixture | static file | n/a (Mozilla PSL snapshot — verbatim copy) | external |
| `lang/en/lang.php` | modify | translations | static data | self (Phase 2 nested array) + `plugins/lovata/ordersshopaholic/lang/en/lang.php` (nested-tree depth) | exact |
| `lang/lv/lang.php` | modify | translations | static data | self (Phase 2 LV file — currently subset) | exact |
| `phpstan.neon` | modify | config | static-analysis rules | self (current `disallowed-calls` block) | exact |
| `composer.json` | modify | config | dependency manifest | self (add `jeremykendall/php-domain-parser ^6.4` to `require:` per Pitfall 7) | exact |
| `tests/Wave 0` (12 files) | new | Pest tests | test | `tests/Feature/Settings/SettingsLookupForSiteTest.php` + `tests/Feature/Console/PurgeEventLogTest.php` + `tests/Feature/Migrations/AddPayloadColumnTest.php` + `tests/Feature/Models/FailedEventModelTest.php` (in-plugin) | exact |

## Pattern Assignments

### `models/Settings.php` (settings model, modify)

**Analogs:**
- Self (current file at `plugins/logingrupa/metapixel/models/Settings.php`) — Phase 2 stub for `lookupForSite`; existing `beforeSave` + `splitEventNameInput` + `partitionEventNames` template for D-14 strict validation.
- `plugins/lovata/toolbox/models/CommonSettings.php` — parent class; already declares `use Multisite;` and `protected $propagatable = []`.

**CommonSettings inheritance (verbatim from `plugins/lovata/toolbox/models/CommonSettings.php` lines 1-29):**

```php
namespace Lovata\Toolbox\Models;

use October\Rain\Database\Traits\Multisite;
use System\Models\SettingModel;

class CommonSettings extends SettingModel
{
    use Multisite;

    public $implement = [
        '@RainLab.Translate.Behaviors.TranslatableModel',
    ];

    public $translatable = [];
    public $settingsCode = '';
    public $settingsFields = 'fields.yaml';

    protected $propagatable = [];
}
```

**Existing partition + flash pattern to mirror for `trusted_hosts` strict validation** (verbatim from `plugins/logingrupa/metapixel/models/Settings.php` lines 47-113 — the `theme_custom_event_names` sanitization):

```php
public function beforeSave(): void
{
    $arLines = $this->splitEventNameInput($this->getAttribute('theme_custom_event_names'));
    if ($arLines === null) {
        return;
    }
    [$arClean, $arDropped] = $this->partitionEventNames($arLines);
    $this->setAttribute('theme_custom_event_names', implode("\n", $arClean));
    if ($arDropped !== []) {
        Flash::warning('metapixel: dropped invalid event names: '.implode(', ', $arDropped));
    }
}

/** @return list<mixed>|null */
private function splitEventNameInput(mixed $mValue): ?array
{
    if (is_array($mValue)) {
        return array_values($mValue);
    }
    if (! is_string($mValue)) {
        return null;
    }
    $mLines = preg_split('/\R/', $mValue);
    return $mLines === false ? [] : $mLines;
}

/** @return array{0: list<string>, 1: list<string>} */
private function partitionEventNames(array $arLines): array
{
    $arClean = [];
    $arDropped = [];
    foreach ($arLines as $mLine) {
        $sTrimmed = is_string($mLine) ? trim($mLine) : '';
        if ($sTrimmed === '') {
            continue;
        }
        $bMatches = preg_match('/^[A-Za-z0-9_]{1,50}$/', $sTrimmed) === 1;
        $bMatches ? $arClean[] = $sTrimmed : $arDropped[] = $sTrimmed;
    }
    return [$arClean, $arDropped];
}
```

**Phase 4 changes to copy in:**
1. Re-implement `lookupForSite` body per RESEARCH Pattern 2 / Example 2 — `Site::withGlobalContext` + `Site::withContext($iSiteId, fn)` closures with explicit `Settings::clearInternalCache()` inside each closure (Pitfall 1 anchor).
2. Add a sibling `splitHostInput` + `partitionHosts` pair structurally identical to `splitEventNameInput` + `partitionEventNames`; `partitionHosts` calls `App::make(HostIndexResolver::class)->resolve($sHost)` and pushes to `$arRejected` on null. Rejected non-empty → `Flash::error` + throw `October\Rain\Database\ModelException` to halt save (per D-14 strict, vs the existing `Flash::warning` + auto-cleanup pattern for theme_custom_event_names).
3. Keep `$propagatable = []` (already in-tree at line 29 — make it explicit at the descendant level per RESEARCH Example 1).

---

### `models/settings/fields.yaml` (YAML config, modify)

**Analog:** self (current file).

**Current shape** (verbatim from `plugins/logingrupa/metapixel/models/settings/fields.yaml`):

```yaml
fields:
    pixel_id:
        label: logingrupa.metapixel::lang.settings.fields.pixel_id_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.pixel_id_comment
        type: text
        span: full
    capi_access_token:
        label: logingrupa.metapixel::lang.settings.fields.capi_access_token_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.capi_access_token_comment
        type: password
        span: full
    test_event_code:
        label: logingrupa.metapixel::lang.settings.fields.test_event_code_label
        commentAbove: logingrupa.metapixel::lang.settings.fields.test_event_code_comment
        type: text
        span: full
    # paid_status_code, default_currency_code, theme_custom_event_names …
```

**Phase 4 changes (per D-15 / D-16 / Pattern 6):**
- Switch top-level key from `fields:` to `tabs:\n    fields:` (October 4-tab layout — verified via `Settings::beforeSave` reading `getAttribute` not nested).
- Add `tab:` to every existing field per D-15 mapping (`Pixel & CAPI`, `Hosts & Cookies`, `Theme Tracking`, `Advanced`).
- Add `trusted_hosts` textarea (size: small, span: full) + `ensure_fbp_fbc_server_side` switch (type: switch, default: true) — both with `commentAbove` lang keys.
- Rename lang key paths from `settings.fields.*` to `field.*` per D-17 nested structure decision (LANG-01 ships a flat-ish `field.*` group rather than `settings.fields.*` to match RESEARCH Pattern 11 example).

---

### `models/FailedEvent.php` (dead-letter model, modify)

**Analog:** self (current file at `plugins/logingrupa/metapixel/models/FailedEvent.php`).

**Current shape** (verbatim, lines 14-40):

```php
class FailedEvent extends Model
{
    public $table = 'logingrupa_metapixel_failed_events';

    protected $fillable = [
        'event_id', 'event_name', 'adapter_type', 'subject_type', 'subject_id',
        'payload', 'http_status', 'graph_error', 'attempts',
    ];

    protected $jsonable = ['payload'];

    protected $casts = [
        'attempts' => 'int',
        'http_status' => 'int',
    ];
}
```

**Phase 4 additions (D-06 + AddDedupColumnsToFailedEvents migration):**

```php
protected $fillable = [
    // existing 9 entries +
    'dedup_pct',
    'emq',
    'dedup_checked_at',
];

protected $casts = [
    'attempts' => 'int',
    'http_status' => 'int',
    'dedup_pct' => 'float',
    'emq' => 'float',
    'dedup_checked_at' => 'datetime',
];
```

Do NOT add `Validation` trait or `$rules` (per Plugin CLAUDE.md "Internal append-only log / dead-letter models intentionally skip Validation" — controller-side `(int) post('record_id')` + `findOrFail` validates, per Pitfall 10).

---

### `models/failedevent/columns.yaml` (YAML config, new)

**Analog:** `plugins/lovata/ordersshopaholic/models/paymentmethod/columns.yaml` shape (referenced from `controllers/paymentmethods/config_list.yaml:3` `list: $/lovata/ordersshopaholic/models/paymentmethod/columns.yaml`).

**Pattern (per RESEARCH Pattern 8):**

```yaml
columns:
    id:
        label: 'logingrupa.metapixel::lang.failed_events.column_id'
        searchable: false
        sortable: true
    event_id:
        label: 'logingrupa.metapixel::lang.failed_events.column_event_id'
        searchable: true
    event_name:
        label: 'logingrupa.metapixel::lang.failed_events.column_event_name'
        searchable: true
        sortable: true
    adapter_type:
        label: 'logingrupa.metapixel::lang.failed_events.column_adapter_type'
        searchable: true
    http_status:
        label: 'logingrupa.metapixel::lang.failed_events.column_http_status'
        sortable: true
    attempts:
        label: 'logingrupa.metapixel::lang.failed_events.column_attempts'
        sortable: true
    graph_error:
        label: 'logingrupa.metapixel::lang.failed_events.column_graph_error'
        type: partial
        path: $/logingrupa/metapixel/models/failedevent/_graph_error.htm
    dedup_pct:
        label: 'logingrupa.metapixel::lang.failed_events.column_dedup_pct'
        sortable: true
    emq:
        label: 'logingrupa.metapixel::lang.failed_events.column_emq'
        sortable: true
    dedup_checked_at:
        label: 'logingrupa.metapixel::lang.failed_events.column_dedup_checked_at'
        type: datetime
    created_at:
        label: 'logingrupa.metapixel::lang.failed_events.column_created_at'
        type: datetime
        sortable: true
```

---

### `updates/AddMultisitePixelIdAndToken.php` (migration, new — no-op-safe per D-03)

**Analog:** `plugins/logingrupa/metapixel/updates/AddPayloadToMetapixelEventLogTable.php` (idempotent additive migration in-plugin).

**In-plugin idempotent pattern (verbatim from `updates/AddPayloadToMetapixelEventLogTable.php` lines 18-43):**

```php
namespace Logingrupa\Metapixel\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddPayloadToMetapixelEventLogTable extends Migration
{
    public const TABLE = 'logingrupa_metapixel_event_log';

    public function up()
    {
        if (Schema::hasColumn(self::TABLE, 'payload')) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->longText('payload')->nullable()->after('event_time');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn(self::TABLE, 'payload')) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $obTable): void {
            $obTable->dropColumn('payload');
        });
    }
}
```

**Phase 4 derivation (per D-03 / RESEARCH Pattern 3):** body is a `Schema::hasTable('system_settings')` guard + no-op (Multisite operates at row layer, not column layer). The class exists purely for marketplace install-log traceability of MULT-06.

---

### `updates/AddDedupColumnsToFailedEvents.php` (migration, new)

**Analog:** same as above — `updates/AddPayloadToMetapixelEventLogTable.php`. Hungarian closure parameter `$obTable`, `Schema::hasColumn` guard for idempotency, `nullable()->after(...)` for column placement.

**Concrete derivation:**

```php
public function up()
{
    if (Schema::hasColumn(self::TABLE, 'dedup_pct')) {
        return;
    }
    Schema::table(self::TABLE, function (Blueprint $obTable): void {
        $obTable->decimal('dedup_pct', 5, 2)->nullable()->after('graph_error');
        $obTable->decimal('emq', 4, 2)->nullable()->after('dedup_pct');
        $obTable->dateTime('dedup_checked_at')->nullable()->after('emq');
    });
}
```

`down()` mirrors `dropColumn` guarded by `Schema::hasColumn`.

---

### `classes/helper/HostIndexResolver.php` (helper service, new)

**Analog:** `plugins/logingrupa/metapixel/classes/helper/PluginGuard.php` (final class, Log facade import, in-plugin idiomatic helper structure).

**In-plugin helper shape (verbatim from `classes/helper/PluginGuard.php` lines 1-47):**

```php
namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Models\Settings;

/**
 * Boot-time and event-time guard. Empty pixel_id → log + disable; never throws.
 */
final class PluginGuard
{
    private static ?bool $bIsDisabled = null;

    public static function isDisabled(): bool
    {
        // memoised + Log::warning + Settings::get pattern
    }

    public static function reset(): void
    {
        self::$bIsDisabled = null;
    }
}
```

**Phase 4 derivation (per RESEARCH Pattern 4 — PSL-wrapped resolver):**

```php
namespace Logingrupa\Metapixel\Classes\Helper;

use Pdp\Domain;
use Pdp\Rules;
use Pdp\UnableToResolveDomain;

final class HostIndexResolver
{
    private ?Rules $obRules = null;

    /** @var array<string, ?int> request-scoped memo */
    private array $arMemo = [];

    public function __construct(private readonly string $sPslPath) {}

    public function resolve(string $sHost): ?int
    {
        $sHost = strtolower(trim($sHost));
        if ($sHost === '') {
            return null;
        }
        if (array_key_exists($sHost, $this->arMemo)) {
            return $this->arMemo[$sHost];
        }
        $obRules = $this->getRules();
        try {
            $obDomain = Domain::fromIDNA2008($sHost);
            $obResolved = $obRules->resolve($obDomain);
        } catch (UnableToResolveDomain | \Throwable $obException) {
            return $this->arMemo[$sHost] = null;
        }
        $obSuffix = $obResolved->suffix();
        if ($obSuffix->isPublicSuffix() === false || $obResolved->secondLevelDomain()->value() === null) {
            return $this->arMemo[$sHost] = null;
        }
        $iSubdomainLabels = count($obResolved->subDomain()->labels());
        return $this->arMemo[$sHost] = $iSubdomainLabels + 1;
    }

    private function getRules(): Rules
    {
        return $this->obRules ??= Rules::fromPath($this->sPslPath);
    }
}
```

**Binding** (per RESEARCH Example 3, in `Plugin::register()`):

```php
$this->app->singleton(HostIndexResolver::class, function () {
    return new HostIndexResolver(
        base_path('plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat')
    );
});
```

---

### `console/RefreshPsl.php` (artisan command, new)

**Analog:** `plugins/logingrupa/metapixel/console/PurgeEventLog.php` (in-plugin command — `final class`, `$signature` + `$description` properties, `handle(): int` return code).

**In-plugin pattern (verbatim from `console/PurgeEventLog.php` lines 14-37):**

```php
namespace Logingrupa\Metapixel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PurgeEventLog extends Command
{
    /** @var string */
    protected $signature = 'metapixel:purge-event-log';

    /** @var string */
    protected $description = 'Delete EventLog rows older than 7 days (Phase 3 TTL purge)';

    public function handle(): int
    {
        // …
        $this->info(sprintf('Purged %d EventLog rows older than %s', $iDeleted, $sCutoff));
        return self::SUCCESS;
    }
}
```

**Phase 4 derivation (per RESEARCH Pattern 5):** body fetches PSL via Guzzle, validates sentinel `// ===BEGIN ICANN DOMAINS===`, atomic-renames to `resources/data/public_suffix_list.dat`, wipes `storage/app/metapixel/psl/` via `File::cleanDirectory`. Returns `self::SUCCESS` / `self::FAILURE`. URL pinned to `https://publicsuffix.org/list/public_suffix_list.dat` constant (Security domain SSRF mitigation).

**Registration in `Plugin::register()` (append to existing call):**

```php
$this->registerConsoleCommand('metapixel:refresh-psl', RefreshPsl::class);
```

(Mirrors the in-plugin Phase 3 registration on line 58 of `Plugin.php`: `$this->registerConsoleCommand('metapixel:purge-event-log', PurgeEventLog::class);`.)

---

### `middleware/EnsureFbpFbcCookies.php` (HTTP middleware, new — fresh derivation per D-20)

**Analog:** No in-plugin middleware exists. Use RESEARCH Pattern 7 as the canonical derivation (fresh code per D-20, NOT a v1.x port).

**Shape:** classic Laravel middleware `handle(Request $obRequest, Closure $fnNext): Response` body. Constructor injects `HostIndexResolver` (resolved via service-container singleton).

**Key invariants to copy from RESEARCH Pattern 7:**

```php
namespace Logingrupa\Metapixel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Models\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureFbpFbcCookies
{
    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;
    private const COOKIE_FBP = '_fbp';
    private const COOKIE_FBC = '_fbc';
    private const FBCLID_MAX_LENGTH = 255;
    private const FBCLID_ALLOWED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly HostIndexResolver $obResolver) {}

    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $obResponse = $fnNext($obRequest);
        if ($this->shouldSkip($obRequest)) {
            return $obResponse;
        }
        $sHost = strtolower($obRequest->getHost());
        $arTrustedHosts = $this->readTrustedHosts();
        if (! in_array($sHost, $arTrustedHosts, true)) {
            return $obResponse; // CR-02: untrusted host → no cookies
        }
        $iIndex = $this->obResolver->resolve($sHost);
        if ($iIndex === null) {
            return $obResponse; // PSL couldn't resolve → fail-safe NO-OP
        }
        $iCreationMs = (int) (microtime(true) * 1000);
        $bSecure = $obRequest->secure();
        $iExpire = time() + self::COOKIE_TTL_SECONDS;
        $this->maybeSetFbp($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);
        $this->maybeSetFbc($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);
        return $obResponse;
    }
}
```

**`shouldSkip` boundary fail-safe** (Pitfall 8 — Settings read inside try/catch returning `true` on throw — prevents 500 during initial migration when `system_settings` table doesn't exist):

```php
try {
    $mToggle = Settings::get('ensure_fbp_fbc_server_side', true);
    return ! ($mToggle === true || $mToggle === 1 || $mToggle === '1');
} catch (Throwable $obException) {
    Log::warning('metapixel: kill-switch lookup threw — middleware defaults to enabled', [
        'exception' => get_class($obException),
    ]);
    return false;
}
```

**Cookie creation locks** (CR-03, v1.x carry-forward):
- `_fbp` random: `bin2hex(random_bytes(8))` (16 hex chars / CSPRNG).
- `_fbc` value: `sprintf('fb.%d.%d.%s', $iIndex, $iMs, $sFbclid)`.
- TTL 90 days, path `/`, domain `null`, secure mirrors `Request::secure()`, httpOnly false, SameSite Lax.
- fbclid pre-validation: `preg_match('/^[A-Za-z0-9_-]+$/', $sFbclid) === 1` AND `strlen($sFbclid) <= 255`.

**Registration in `Plugin::boot()`** (per RESEARCH Example 4):

```php
$this->app[\Illuminate\Contracts\Http\Kernel::class]
    ->pushMiddleware(\Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies::class);
```

---

### `controllers/FailedEvents.php` (backend controller, new)

**Analog:** `plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php` (canonical sibling — verified in-tree).

**Sibling pattern (verbatim from `plugins/lovata/ordersshopaholic/controllers/PaymentMethods.php` lines 1-35):**

```php
<?php namespace Lovata\OrdersShopaholic\Controllers;

use Event;
use BackendMenu;
use Backend\Classes\Controller;
use System\Classes\SettingsManager;

class PaymentMethods extends Controller
{
    public $implement = [
        'Backend.Behaviors.ListController',
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.ReorderController',
        'Backend.Behaviors.RelationController',
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';
    // …

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Lovata.OrdersShopaholic', 'orders-shopaholic-menu-payment-methods');
    }
}
```

**Phase 4 derivation (per D-08 — ListController-only, no FormController):**

```php
namespace Logingrupa\Metapixel\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Models\Settings;
use System\Classes\SettingsManager;
use Throwable;

class FailedEvents extends Controller
{
    /** @var list<string> */
    public $implement = ['Backend.Behaviors.ListController'];

    /** @var string */
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Logingrupa.Metapixel', 'failed_events');
    }

    public function onReplay(): array { /* per Pattern 9 */ }
    public function onReplayBatch(): array { /* loops onReplay */ }
    public function onCheckDedup(): array { /* per Pattern 9 */ }
    public function onCheckDedupBatch(): array { /* loops onCheckDedup */ }
    public function onDeleteBatch(): array { /* truncates selected rows */ }
}
```

**AJAX handler pattern (per RESEARCH Pattern 9):** `$iId = (int) post('record_id');` → `FailedEvent::findOrFail($iId)` → `AdapterRegistry::resolveByClass((string) $obRow->adapter_type)` → `Settings::lookupForSite(null)` (D-01 default-row fallback per Pattern 9 Open Question Option A — no `site_id` column on `failed_events` in v2.0) → `MetaClient::sendForPixel($arCreds['pixel_id'], $arCreds['capi_access_token'], $arPayload)` → row update + `Flash::success` / `Flash::error` → return `['#failedEventList' => $this->makePartial('list')]`.

Every `catch` block in `onReplay` documents reason ("permanent — write graph_error", "transient — increment attempts only") per Tiger-Style fail-fast rules in plugin CLAUDE.md.

---

### `controllers/failedevents/config_list.yaml` (YAML config, new)

**Analog:** `plugins/lovata/ordersshopaholic/controllers/paymentmethods/config_list.yaml` (canonical sibling — verified in-tree).

**Sibling pattern (verbatim from `plugins/lovata/ordersshopaholic/controllers/paymentmethods/config_list.yaml`):**

```yaml
title: 'lovata.ordersshopaholic::lang.payment_method.list_title'
modelClass: Lovata\OrdersShopaholic\Models\PaymentMethod
list: $/lovata/ordersshopaholic/models/paymentmethod/columns.yaml
recordUrl: 'lovata/ordersshopaholic/paymentmethods/update/:id'
noRecordsMessage: 'backend::lang.list.no_records'
showSetup: true
showCheckboxes: true
defaultSort:
    column: sort_order
    direction: asc
toolbar:
    buttons: list_toolbar
    search:
        prompt: 'backend::lang.list.search_prompt'
```

**Phase 4 derivation (per RESEARCH Pattern 8):**

```yaml
title: 'logingrupa.metapixel::lang.failed_events.list_title'
modelClass: Logingrupa\Metapixel\Models\FailedEvent
list: $/logingrupa/metapixel/models/failedevent/columns.yaml
noRecordsMessage: 'logingrupa.metapixel::lang.failed_events.no_records'
showCheckboxes: true
showSorting: true
defaultSort:
    column: created_at
    direction: desc
recordsPerPage: 30
toolbar:
    buttons: list_toolbar
    search:
        prompt: 'logingrupa.metapixel::lang.failed_events.search_prompt'
filterConfig:
    scopes:
        event_name:
            label: 'logingrupa.metapixel::lang.failed_events.filter_event_name'
            type: text
        adapter_type:
            label: 'logingrupa.metapixel::lang.failed_events.filter_adapter_type'
            type: text
        created_at:
            label: 'logingrupa.metapixel::lang.failed_events.filter_date_range'
            type: daterange
            conditions: created_at >= :after AND created_at <= :before
```

(D-08 omits `recordUrl` because no FormController exists to receive the click — Replay/CheckDedup/Delete are toolbar actions, not row-click destinations.)

---

### `controllers/failedevents/_list_toolbar.htm` (toolbar partial, new)

**Analog:** `plugins/lovata/ordersshopaholic/controllers/paymentmethods/_list_toolbar.htm` (canonical sibling — verified in-tree).

**Sibling pattern (verbatim from `plugins/lovata/ordersshopaholic/controllers/paymentmethods/_list_toolbar.htm`):**

```html
<div data-control="toolbar">
    <button
            class="btn btn-danger oc-icon-trash-o"
            disabled="disabled"
            onclick="$(this).data('request-data', {
                    checked: $('.control-list').listWidget('getChecked')
                })"
            data-request="onDelete"
            data-request-confirm="<?= e(trans('backend::lang.list.delete_selected_confirm')) ?>"
            data-trigger-action="enable"
            data-trigger=".control-list input[type=checkbox]"
            data-trigger-condition="checked"
            data-request-success="$(this).prop('disabled', false)"
            data-stripe-load-indicator>
        <?= e(trans('backend::lang.list.delete_selected')) ?>
    </button>
</div>
```

**Phase 4 derivation (per RESEARCH Pattern 8 — 3 buttons: Replay, CheckDedup, Delete):**
Copy the `data-trigger="checkbox"` + `data-trigger-action="enable"` + `onclick="$(this).data('request-data', { checked: $('.control-list').listWidget('getChecked') })"` pattern verbatim, replace `data-request="onDelete"` with `onReplayBatch` / `onCheckDedupBatch` / `onDeleteBatch`, update icons (`oc-icon-bolt`, `oc-icon-shield`, `oc-icon-trash-o`) and lang keys.

---

### `controllers/failedevents/index.htm` (list page partial, new)

**Analog:** `plugins/lovata/ordersshopaholic/controllers/paymentmethods/index.htm` (a 27-byte one-liner — confirms October auto-renders the list via ListController behavior).

The file is minimal — just `<?= $this->listRender() ?>` or equivalent shell. The 27-byte sibling indicates standard October convention; no creative content here.

---

### `classes/meta/MetaClient.php` (API client, modify)

**Analog:** self (current file).

**Existing pattern to mirror** (verbatim from `classes/meta/MetaClient.php` lines 25-77 — `sendForPixel` boundary classification):

```php
class MetaClient
{
    public const META_GRAPH_API_VERSION = 'v23.0';
    private const META_GRAPH_API_BASE = 'https://graph.facebook.com';
    private const DEFAULT_TIMEOUT_SECONDS = 5;
    private const TRANSIENT_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    public function __construct(private readonly ?ClientInterface $obClient = null) {}

    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
    {
        if ($sPixelId === '') {
            throw new MissingPixelConfigException('metapixel: pixel_id is empty at dispatch time');
        }
        if ($sToken === '') {
            throw new MissingCapiTokenException('metapixel: capi_access_token is empty at dispatch time');
        }
        // POST to /events with json body; classifies 2xx / transient / permanent
    }
}
```

**Phase 4 addition — `fetchTestEventsStatus` method** (per RESEARCH Pattern 10):

```php
/**
 * Meta Dataset Quality endpoint — GET /{pixel_id}/?fields=event_match_quality,deduplication_rate.
 *
 * @return array{event_match_quality: mixed, deduplication_rate: mixed, raw: array<string, mixed>}
 */
public function fetchTestEventsStatus(string $sPixelId, string $sToken, string $sTestEventCode = '', string $sEventId = ''): array
{
    if ($sPixelId === '') {
        throw new MissingPixelConfigException('metapixel: pixel_id is empty');
    }
    if ($sToken === '') {
        throw new MissingCapiTokenException('metapixel: access_token is empty');
    }
    // GET with tolerant parser (?? null on every field read — schema may drift)
}
```

Mirrors the existing throw-on-empty-credentials boundary + transient/permanent classification used in `sendForPixel`.

---

### `Plugin.php` (plugin registration, modify)

**Analog:** self (current file at `plugins/logingrupa/metapixel/Plugin.php`) + `plugins/lovata/shopaholic/Plugin.php::registerSettings` (for the `failed_events` Settings parent registration per RESEARCH Pitfall 6 — D-08 says "under Settings parent").

**Existing register/boot shape (verbatim from `Plugin.php` lines 54-86):**

```php
public function register(): void
{
    $this->app->singleton(AdapterRegistry::class);
    $this->app->singleton(ThemeEventCollector::class);
    $this->registerConsoleCommand('metapixel:purge-event-log', PurgeEventLog::class);
}

public function boot(): void
{
    if ($this->isShopaholicEnabled()) {
        // adapter registration
    }
    Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void { ... });
    App::make(AdapterRegistry::class)->register(ThemeActionEvent::class, ThemeActionAdapter::class);
    Event::subscribe(ThemeAjaxHandler::class);
}

public function registerSettings(): array
{
    return [
        'settings' => [
            'label' => 'logingrupa.metapixel::lang.settings.label',
            'description' => 'logingrupa.metapixel::lang.settings.description',
            'category' => 'logingrupa.metapixel::lang.settings.category',
            'icon' => 'icon-bullseye',
            'class' => Settings::class,
            'order' => 500,
        ],
    ];
}
```

**Lovata `registerSettings` analog for `failed_events` URL-based sub-item (verbatim from `plugins/lovata/shopaholic/Plugin.php` lines 84-110 — `url:` + `class:` two-shape pattern):**

```php
'shopaholic-menu-currency' => [
    'label' => 'lovata.shopaholic::lang.menu.currency',
    'description' => 'lovata.shopaholic::lang.menu.currency_description',
    'category' => 'lovata.shopaholic::lang.tab.settings',
    'icon' => 'oc-icon-usd',
    'url' => Backend::url('lovata/shopaholic/currencies'),
    'order' => 1800,
    'permissions' => ['shopaholic-menu-currency'],
],
```

**Phase 4 additions:**

1. `register()` — append HostIndexResolver singleton binding (per RESEARCH Example 3) + RefreshPsl console command registration.
2. `boot()` — append `$this->app[Kernel::class]->pushMiddleware(EnsureFbpFbcCookies::class);` (per RESEARCH Example 4 / Pitfall 8).
3. `registerSettings()` — append `'failed_events'` entry with `url => Backend::url('logingrupa/metapixel/failedevents')` shape (the URL-based sub-item from the shopaholic analog above), wired under the same `category` as the existing `'settings'` entry (Pitfall 6 Recommendation: Option (a) — under `SettingsManager` parent).

---

### `resources/data/public_suffix_list.dat` (data fixture, new)

**Analog:** none — verbatim snapshot of `https://publicsuffix.org/list/public_suffix_list.dat` committed to git per D-09. License: MPL 2.0 (compatible with plugin MIT license per Mozilla PSL license terms). Plugin operators NEVER re-fetch automatically — refresh is operator-explicit via `php artisan metapixel:refresh-psl`.

---

### `lang/en/lang.php` (translations, modify)

**Analogs:**
- Self (current file at `plugins/logingrupa/metapixel/lang/en/lang.php` — Phase 2 nested array with `plugin`, `settings.fields.*` keys).
- `plugins/lovata/ordersshopaholic/lang/en/lang.php` — nested-tree depth reference (`plugin`, `component`, `tab`, `field`, `menu`, etc.).

**Existing in-plugin shape (verbatim from `lang/en/lang.php` lines 1-27):**

```php
return [
    'plugin' => [
        'name'        => 'Meta Pixel + Conversions API',
        'description' => 'Server-deduplicated Meta Pixel and Conversions API tracking via adapter pattern.',
    ],
    'settings' => [
        'label'       => 'Meta Pixel + CAPI',
        'description' => 'Configure the Pixel ID, CAPI access token, and Test Events code for Meta tracking.',
        'category'    => 'Marketing',
        'fields' => [
            'pixel_id_label'                   => 'Pixel ID',
            'pixel_id_comment'                 => 'Your Meta Pixel ID (digits-only). Acquire from Meta Events Manager > Data sources > Pixel > Settings.',
            'capi_access_token_label'          => 'CAPI Access Token',
            // ... 12 more entries
        ],
    ],
];
```

**Phase 4 derivation (per RESEARCH Pattern 11 / D-17):** preserve existing top-level `plugin` + `settings` groups but flatten the Phase-4-added fields under `tab.*`, `field.*`, `menu.*`, `failed_events.*`, `exception.*` keys (final shape listed verbatim in RESEARCH §"Pattern 11" lines 1295-1366). Total ≈ 60 keys per `$arLine` of the RESEARCH "LANG-01 coverage list" enumeration in D-19.

---

### `lang/lv/lang.php` (translations, modify)

**Analog:** self (current file — currently a subset of EN). LV is native Latvian (Latvian-fluent author per D-18 — not machine-translated).

**Existing in-plugin LV shape (verbatim from `lang/lv/lang.php` lines 1-23):**

```php
return [
    'plugin' => [
        'name'        => 'Meta Pixel + Conversions API',
        'description' => 'Servera puses dedublēta Meta Pixel un Conversions API izsekošana caur adapter modeli.',
    ],
    'settings' => [
        'label'       => 'Meta Pixel + CAPI',
        'description' => 'Konfigurējiet Pixel ID, CAPI piekļuves marķieri un Test Events kodu Meta izsekošanai.',
        'category'    => 'Mārketings',
        'fields' => [
            'pixel_id_label'             => 'Pixel ID',
            'pixel_id_comment'           => 'Jūsu Meta Pixel ID (tikai cipari). ...',
            'capi_access_token_label'    => 'CAPI piekļuves marķieris',
            // ...
        ],
    ],
];
```

**Phase 4 derivation:** mirror EN key shape exactly (LANG-01 Pest coverage gate at `tests/Unit/Lang/LangCoverageTest.php` — flatten + canonical-compare per RESEARCH Pattern 11 final code snippet). Native LV translations for the 60 new keys.

---

### `phpstan.neon` (config, modify)

**Analog:** self (current `disallowed-calls` block enforcing CR-04 bans on `SiteManager`, `request()`, `Request::*` inside `classes/queue/`, `classes/event/`, `classes/adapter/`).

**Phase 4 addition (per D-02):** new `disallowedMethodCalls` rule banning `Logingrupa\Metapixel\Models\Settings::get('pixel_id')` and `Logingrupa\Metapixel\Models\Settings::get('capi_access_token')` outside `Settings::lookupForSite` itself. (Exact rule wording is planner's discretion per CONTEXT "Claude's Discretion" — pattern proven by the existing CR-04 disallowed-calls block.)

---

### `composer.json` (dependency manifest, modify)

**Analog:** self.

**Phase 4 addition (per Pitfall 7):** `jeremykendall/php-domain-parser ^6.4` added to `require:` (NOT `require-dev:` — `HostIndexResolver` is a production-path file). License: MIT. ext-intl already a host requirement (verified — PHP 8.4 stack carries `intl`).

---

### Tests (Wave 0 — 12 new files)

**Analogs (all in-plugin — direct sibling references):**

| New Test File | Sibling Pattern in `tests/` |
|---------------|------------------------------|
| `tests/Unit/Models/SettingsMultisiteTraitTest.php` | `tests/Unit/Helper/PluginGuardTest.php` (unit `extends MetapixelTestCase` shape) |
| `tests/Feature/Settings/LookupForSiteTest.php` (extends current) | `tests/Feature/Settings/SettingsLookupForSiteTest.php` (verbatim shape — Phase 2 file) |
| `tests/Feature/MultisiteEventLogRoutingTest.php` | `tests/Feature/Settings/SettingsLookupForSiteTest.php` + RESEARCH "Wave 0 Gaps" `tests/fixtures/sites.php` hermetic 2-site seed |
| `tests/Feature/Migrations/AddMultisitePixelIdAndTokenTest.php` | `tests/Feature/Migrations/AddPayloadColumnTest.php` |
| `tests/Feature/Migrations/AddDedupColumnsToFailedEventsTest.php` | `tests/Feature/Migrations/AddPayloadColumnTest.php` |
| `tests/Feature/Settings/TrustedHostsValidationTest.php` | `tests/Feature/Settings/SettingsLookupForSiteTest.php` (same `MetapixelTestCase` setUp pattern) |
| `tests/Unit/Helper/HostIndexResolverTest.php` | `tests/Unit/Helper/SiteResolverTest.php` (in-plugin unit helper test) |
| `tests/Feature/Console/RefreshPslTest.php` | `tests/Feature/Console/PurgeEventLogTest.php` |
| `tests/Feature/Middleware/EnsureFbpFbcCookiesTest.php` | `tests/Feature/Components/*` (closest in-plugin "boots controller / asserts HTTP-shaped response" pattern) |
| `tests/Feature/Controllers/FailedEventsListTest.php` | `tests/Feature/Components/*` (no in-plugin controller test yet — fresh shape) |
| `tests/Feature/Controllers/FailedEventsReplayTest.php` | same as above + `tests/doubles/MetaClient*` for Guzzle MockHandler |
| `tests/Feature/Controllers/FailedEventsCheckDedupTest.php` | same as above |
| `tests/Unit/Lang/LangCoverageTest.php` | `tests/Unit/PluginSanityTest.php` (in-plugin unit test top-level) |

**In-plugin test pattern (verbatim from `tests/Feature/Console/PurgeEventLogTest.php` lines 1-40):**

```php
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Console\PurgeEventLog;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class PurgeEventLogTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        $obKernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $obKernel->registerCommand($this->app->make(PurgeEventLog::class));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }
}
```

**Settings test setUp pattern (verbatim from `tests/Feature/Settings/SettingsLookupForSiteTest.php` lines 1-17):**

```php
final class SettingsLookupForSiteTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        // SettingModel keeps a static $instances cache that survives between
        // tests. Clear it so each test sees a fresh resolved instance.
        Settings::clearInternalCache();
    }
}
```

Every Phase 4 Settings test MUST call `Settings::clearInternalCache()` in `setUp` (Pitfall 1 anchor).

---

## Shared Patterns

### Hungarian notation (CRITICAL — applies to every new file)

**Source:** plugin CLAUDE.md `## Code style` table.
**Apply to:** every new `.php` file in Phase 4.

| Prefix | Used For | Examples in Phase 4 |
|--------|----------|---------------------|
| `$ob` | Object / Model / Item / Collection | `$obRow`, `$obAdapter`, `$obResolver`, `$obRules`, `$obResponse`, `$obRequest`, `$obClient`, `$obException` |
| `$ar` | Array | `$arTrustedHosts`, `$arClean`, `$arRejected`, `$arPayload`, `$arCreds`, `$arResult`, `$arLines`, `$arMemo` |
| `$i` | Integer | `$iId`, `$iSiteId`, `$iIndex`, `$iCreationMs`, `$iExpire`, `$iStatus`, `$iSubdomainLabels` |
| `$s` | String | `$sHost`, `$sFbclid`, `$sFbp`, `$sFbc`, `$sPixelId`, `$sToken`, `$sUrl`, `$sBundlePath`, `$sBody` |
| `$b` | Boolean | `$bSecure`, `$bIsDisabled`, `$bMatches` |
| `$f` | Float | `$fDedupPct`, `$fEmq` |

PHPMD enforces `ShortVariable min=4`. Avoid `$id`, `$tmp` — always prefer `$iId`, `$arTmp`.

### October model property convention (CRITICAL — exception to Hungarian rule)

**Source:** plugin CLAUDE.md `### Model property convention`.
**Apply to:** `models/Settings.php`, `models/FailedEvent.php`.

These specific properties STAY Laravel-standard (no `$ar*` prefix):
- `$table`, `$fillable`, `$jsonable`, `$casts`, `$rules`, `$customMessages`, `$attributeNames`, `$propagatable`
- Relationship arrays: `$hasOne`, `$hasMany`, `$belongsTo`, `$belongsToMany`, `$morphTo`, `$morphOne`, `$morphMany`, `$morphToMany`, `$morphedByMany`, `$attachOne`, `$attachMany`

JSON columns use `$jsonable` (October idiom — already in-plugin at `FailedEvent::$jsonable = ['payload']`), NOT `$casts = ['column' => 'array']`.

### Tiger-Style fail-fast (applies to every business-logic boundary)

**Source:** parent CLAUDE.md `### Tiger-Style Rules` + plugin CLAUDE.md `Tiger-Style fail-fast`.
**Apply to:** `Settings::lookupForSite`, `HostIndexResolver::resolve`, `MetaClient::fetchTestEventsStatus`, `Controllers\FailedEvents::onReplay`, `Console\RefreshPsl::handle`.

| Rule | Phase 4 enforcement |
|------|---------------------|
| Throw at boundary | `MetaClient::fetchTestEventsStatus` throws on empty pixel_id / empty token / non-2xx response (mirrors existing `sendForPixel`) |
| Catch only to log-and-rethrow OR dead-letter-persist | `EnsureFbpFbcCookies::shouldSkip` catches `Throwable` on Settings::get → `Log::warning` + returns `false` (boundary fail-safe — Pitfall 8); every other `catch` rethrows or persists. |
| Document every catch | `// silent: Twig render failure must not 500 page` style — every Phase 4 `catch` needs a reason comment. |
| No `assert()` | NEVER. Production `zend.assertions=0` silently no-ops. Use explicit `throw`. |
| No CR-XX / Phase-N markers in code | Workflow refs belong in commits/PRs, not source. The locked CR-02 / CR-03 references go in PR descriptions, not `// CR-02:` comments in middleware. |

### Boundary fail-safe pattern (middleware-specific)

**Source:** RESEARCH Pitfall 8.
**Apply to:** `EnsureFbpFbcCookies::shouldSkip` (Settings::get inside `try { ... } catch (Throwable) { return true|false; }`).
**Reason:** plugin migrations might not have run yet when middleware boots; reading `system_settings` would 500 the initial migration HTTP request.

### Cookie creation locks (v1.x carry-forward)

**Source:** plugin CLAUDE.md `## Locked decisions (carried from v1.1.1)` + RESEARCH Pattern 7 verbatim.
**Apply to:** `Middleware\EnsureFbpFbcCookies::maybeSetFbp` + `::maybeSetFbc`.

| Lock | Value |
|------|-------|
| TTL | `60 * 60 * 24 * 90` (90 days) |
| `_fbp` format | `fb.{index}.{ms}.{bin2hex(random_bytes(8))}` |
| `_fbc` format | `fb.{index}.{ms}.{fbclid}` |
| `creation_time_ms` | `(int) (microtime(true) * 1000)` |
| fbclid regex | `/^[A-Za-z0-9_-]+$/` |
| fbclid max length | 255 |
| Symfony Cookie attrs | path `/`, domain `null`, secure mirrors `Request::secure()`, httpOnly false, SameSite `lax` |

### Hermetic 2-site test seed (MULT-05 specific)

**Source:** D-04 + RESEARCH "Wave 0 Gaps" `tests/fixtures/sites.php`.
**Apply to:** `tests/Feature/MultisiteEventLogRoutingTest.php`.

Seed 2 site rows in `setUp` before any `Settings::set([...])` calls; assert UNIQUE `(subject_type, subject_id, event_name, channel, site_id)` allows both rows with same subject_id + different site_id (NULL-distinct semantics — SQLite ≥ 3.35, MySQL ≥ 8.0.13 parity).

---

## No Analog Found

| File | Role | Data Flow | Reason | Mitigation |
|------|------|-----------|--------|------------|
| `middleware/EnsureFbpFbcCookies.php` | middleware | request-response | No in-plugin middleware analog yet — v2.0 is fresh-code-only per D-20 (v1.x port forbidden). | Use RESEARCH Pattern 7 verbatim as the canonical derivation. Sibling Laravel middleware patterns are uniform (`handle(Request, Closure): Response`) — no creativity needed. |
| `resources/data/public_suffix_list.dat` | data fixture | static file | Mozilla PSL snapshot — verbatim third-party data. | Bundle from `https://publicsuffix.org/list/public_suffix_list.dat` at plugin tag time (D-09). |

---

## Metadata

**Analog search scope:** `plugins/logingrupa/metapixel/**`, `plugins/lovata/{toolbox,shopaholic,ordersshopaholic}/**` (canonical sibling references cited in RESEARCH §"Sources / Primary").

**Files scanned:** ≈ 30 (existing plugin files + 8 Lovata sibling exemplars + October v4 vendor files for inheritance chain verification).

**Pattern extraction date:** 2026-05-19.

**Key insight (RESEARCH §"Don't Hand-Roll" closing line):** Phase 4's surface area is large (5 sub-domains: Multisite, TrustedHosts, Cookie, FailedEvents, i18n) but the implementation lift is small because every sub-domain has a canonical OctoberCMS/Lovata/Laravel pattern already in-tree. The novel work is wiring `jeremykendall/php-domain-parser` into `HostIndexResolver` — everything else is configuration + idiomatic pattern application + Hungarian-notation renaming.
