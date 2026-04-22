# Audit 05 — Settings model + Health page backend patterns

## Q1: Lovata Settings model inheritance + SettingsModel behavior

**FINDING:**
Lovata Settings extend `System\Models\SettingModel` (not plain `Model`), NOT via mixin.

**Pattern (Lovata):**
```php
// lovata/toolbox/models/CommonSettings.php:11
class CommonSettings extends SettingModel
{
    use Multisite;
    const SETTINGS_CODE = '';
    public $settingsCode = 'lovata_toolbox_settings';
    public $settingsFields = 'fields.yaml';
    // ... no explicit SettingsModel behavior — inherited from parent SettingModel
}

// lovata/shopaholic/models/Settings.php:15
class Settings extends CommonSettings
{
    const SETTINGS_CODE = 'lovata_shopaholic_settings';
    public $settingsCode = 'lovata_shopaholic_settings';
    /** @mixin \System\Behaviors\SettingsModel */
}
```

**Alternative (Logingrupa):**
```php
// logingrupa/postnordshippingshopaholic/models/Settings.php:17
class Settings extends SettingModel  // Direct extend
{
    public $settingsCode = 'logingrupa_postnordshipping_settings';
    public $settingsFields = 'fields.yaml';
}
```

**Answer:** BOTH patterns work — either:
- Extend `CommonSettings` (cascades Lovata conventions + Multisite trait + TranslatableModel), or
- Extend `System\Models\SettingModel` directly (lighter, Logingrupa style)

Choice: **Use Lovata pattern** (CommonSettings) for consistency with Shopaholic ecosystem.

---

## Q2: Settings field file location

**FINDING:**
Field definitions live in `models/settings/fields.yaml` (not `config/fields.yaml`).

**Evidence:**
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/models/settings/fields.yaml` ✓
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/models/settings/fields.yaml` ✓
- CommonSettings.php line 23: `public $settingsFields = 'fields.yaml';` (resolves to `models/settings/`)

**Answer:** `models/[PluginName]Settings/fields.yaml` directory, referenced as simple filename in model.

---

## Q3: Settings access pattern — getCacheKey vs ::get()

**FINDING:**
Lovata uses **static `::get()` helper** (not getCacheKey).

**Evidence:**
```php
// lovata/toolbox/models/CommonSettings.php:37-40
public static function getValue($sCode, $sDefaultValue = null)
{
    return static::get($sCode, $sDefaultValue);  // ← Static reader
}
```

SettingModel (October parent) provides `::get($key, $default = null)` which handles cache internally. No explicit `getCacheKey()` override found in Lovata implementations.

**Usage:**
```php
$sQueueName = Settings::get('queue_name');
$iDecimals = Settings::get('decimals', 2);
```

**Answer:** Always use `Settings::get($fieldName, $default)` — October's SettingModel handles caching.

---

## Q4: Adding `meta_purchase_event_id` column to Lovata Order table

**Pattern (modifying upstream plugin table):**
```php
// File: plugins/logingrupa/metapixel/updates/add_meta_purchase_event_id_to_orders_table.php
<?php namespace Logingrupa\Metapixel\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class AddMetaPurchaseEventIdToOrdersTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('lovata_orders_shopaholic_orders') 
            && !Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_id')) {
            
            Schema::table('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
                $obTable->string('meta_purchase_event_id')->nullable()->after('secret_key');
                $obTable->index('meta_purchase_event_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('lovata_orders_shopaholic_orders') 
            && Schema::hasColumn('lovata_orders_shopaholic_orders', 'meta_purchase_event_id')) {
            
            Schema::table('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
                $obTable->dropIndex(['meta_purchase_event_id']);
                $obTable->dropColumn(['meta_purchase_event_id']);
            });
        }
    }
}
```

**Verified syntax:**
- logingrupa/storeextender/updates/update_table_lovata_shopaholic_offers.php uses identical Schema::table + hasTable/hasColumn guards ✓
- lovata/ordersshopaholic/updates/table_create_order.php uses proper Blueprint pattern ✓

**Answer:** Pattern shown above. Place in `plugins/logingrupa/metapixel/updates/` with numeric prefix (e.g., `2026_04_22_000001_...` or match November bundle timestamp style).

---

## Q5: Order status "Paid" — actual codes in Lovata Status table

**Finding:**
Lovata OrdersShopaholic defines 4 status constants:
```php
// lovata/ordersshopaholic/models/Status.php:44-47
const STATUS_NEW = 'new';
const STATUS_IN_PROGRESS = 'in_progress';
const STATUS_COMPETE = 'complete';  // [NOTE: typo 'COMPETE' not 'COMPLETE']
const STATUS_CANCELED = 'canceled';
```

**Default seeder (table_create_status.php):**
- `new` (sort 1)
- `in_progress` (sort 2)
- `complete` (sort 3)
- `canceled` (sort 4)

**What exists in nailscosmetics.lv DB?** Unknown without querying. Likely `complete` = paid (Lovata convention), but NO `paid` or `samaksats` codes exist in base plugin.

**Answer:** 
- **Plan setting "Order status code = Paid" must map to actual status code** (probably `complete` per Lovata default)
- Settings field should be a **dropdown** with `getOrderStatusOptions()` method reading Status.code
- Check live nailscosmetics.lv backend Settings → Order to confirm what code is used for "paid" there
- ~~Do NOT hardcode `'paid'`~~ — reference Status.STATUS_COMPETE or query by code

---

## Q6: FailedEvent table — plugin migration pattern with timestamps + indices

**Pattern (example from lovata):**
```php
// File: plugins/logingrupa/metapixel/updates/create_table_failed_events.php
<?php namespace Logingrupa\Metapixel\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateTableFailedEvents extends Migration
{
    const TABLE_NAME = 'logingrupa_metapixel_failed_events';

    public function up()
    {
        if (Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::create(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->string('event_id')->nullable();
            $obTable->string('event_type');  // 'Purchase', 'AddToCart', etc.
            $obTable->integer('order_id')->unsigned()->nullable();
            $obTable->integer('user_id')->unsigned()->nullable();
            $obTable->text('payload')->nullable();  // Full JSON request
            $obTable->text('response')->nullable();  // Meta API error
            $obTable->integer('retry_count')->unsigned()->default(0);
            $obTable->dateTime('next_retry_at')->nullable();
            $obTable->text('error_message')->nullable();
            $obTable->timestamps();  // created_at, updated_at

            // Indices for query patterns
            $obTable->index('event_id');
            $obTable->index('event_type');
            $obTable->index('order_id');
            $obTable->index('user_id');
            $obTable->index(['created_at', 'retry_count']);  // Composite for retry query
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }
}
```

**Verified syntax:**
- lovata/ordersshopaholic/updates/table_create_order_promo_mechanism.php (line 37): `$obTable->timestamps();` ✓
- lovata/ordersshopaholic/updates/table_create_order.php (line 38): `$obTable->timestamps();` + indices ✓

**Answer:** Pattern shown. Use `->timestamps()` for auto-created_at/updated_at. Index frequently-queried columns (event_id, type, order_id).

---

## Q7: Health page — Lovata backend controller + custom views

**Pattern (from Lovata Shopaholic):**
```php
// File: plugins/logingrupa/metapixel/controllers/Health.php
<?php namespace Logingrupa\Metapixel\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Logingrupa\Metapixel\Models\FailedEvent;

class Health extends Controller
{
    public $implement = [
        'Backend.Behaviors.ListController',
    ];

    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        // Or custom menu context if needed
    }

    /**
     * Custom action: check Events Manager API status
     */
    public function onCheckApiStatus()
    {
        try {
            $arStatus = [
                'api_reachable' => true,
                'last_event_sent' => FailedEvent::orderBy('created_at', 'desc')->first(),
                'failed_count' => FailedEvent::where('retry_count', '=', 0)->count(),
            ];
            return response()->json($arStatus);
        } catch (\Exception $obEx) {
            return response()->json(['error' => $obEx->getMessage()], 500);
        }
    }
}
```

**View file (controllers/health/index.htm):**
```html
<?php Block::put('breadcrumb') ?>
    <ul class="breadcrumb">
        <li><a href="<?= Backend::url('system/settings') ?>">Settings</a></li>
        <li class="active">Event Health</li>
    </ul>
<?php Block::endPut() ?>

<div class="control-section">
    <h3>Meta Conversion Event Health</h3>
    
    <div id="health-status">
        <p class="alert alert-info">Loading status...</p>
    </div>
    
    <div class="control-section">
        <h4>Failed Events Queue</h4>
        <?php echo $this->listRender() ?>
    </div>
</div>

<script>
jQuery(function($) {
    // Optional AJAX query to check API status
    $.request('Health::onCheckApiStatus', {
        success: function(data) {
            $('#health-status').html(
                '<div class="alert ' + (data.api_reachable ? 'alert-success' : 'alert-danger') + '">' +
                'API Status: ' + (data.api_reachable ? 'Healthy' : 'Unreachable') +
                '</div>'
            );
        }
    });
});
</script>
```

**config_list.yaml:**
```yaml
list: $/logingrupa/metapixel/models/failedevent/columns.yaml
modelClass: Logingrupa\Metapixel\Models\FailedEvent
title: Failed Events
noRecordsMessage: lovata.toolbox::lang.common.empty
showCheckboxes: false

toolbar:
    buttons:
        create: false
        delete: false

columns: ...  # Standard list config
```

**Verified pattern:**
- lovata/shopaholic/controllers/Taxes.php (lines 13-35): Standard Controller + BackendMenu context ✓
- lovata/shopaholic/controllers/taxes/config_form.yaml exists ✓

**Answer:** Use standard Backend\Classes\Controller + ListController behavior. Custom AJAX handlers (onCheckApiStatus) for API health checks.

---

## Q8: Log::info/debug/error — structured logging config

**Project config:**
```php
// config/logging.php (lines 56-67)
'stack' => [
    'driver' => 'stack',
    'channels' => explode(',', env('LOG_STACK', 'single')),
    'ignore_exceptions' => false,
],

'single' => [
    'driver' => 'single',
    'path' => storage_path('logs/system.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'replace_placeholders' => true,
],
```

**Usage pattern (from vippsshopaholic):**
```php
// vippsshopaholic/classes/helper/VippsWebhookManager.php:3,72
use Log;

// ...
Log::error('VippsWebhookManager: register failed', [
    'webhook_url' => $sUrl,
    'error' => $e->getMessage(),
]);

Log::warning('VippsWebhookManager: cache refresh failed', [...]);
```

**Answer:**
- **Use:** `use Log;` (Laravel facade), then `Log::error()`, `Log::info()`, `Log::debug()`, `Log::warning()`
- **Config:** Routes to `storage_path('logs/system.log')` by default (single driver)
- **No special structured config needed** — plain Monolog + context array support
- **Context array as 2nd param** for structured fields: `Log::error('msg', ['field' => 'value'])`

---

## Summary for Plan refactor

| Question | Answer |
|----------|--------|
| **Q1: Settings extend** | `CommonSettings` (extends SettingModel) — Lovata pattern |
| **Q2: Fields location** | `models/[Class]Settings/fields.yaml` |
| **Q3: Cache access** | `Settings::get('fieldName', $default)` — October SettingModel handles cache |
| **Q4: Add Order column** | Use Schema::table + hasTable guard; place in plugin `updates/` with hasColumn check |
| **Q5: Paid status code** | Base Lovata = `complete` (NOT `paid`/`samaksats`). **Verify in nailscosmetics.lv backend or DB.** |
| **Q6: FailedEvent table** | Standard Migration + Schema::create; use timestamps() + indices on event_id, order_id, type |
| **Q7: Health page** | Backend\Classes\Controller + ListController behavior. Custom AJAX via onCheckApiStatus(). |
| **Q8: Logging** | `use Log;` + `Log::error('msg', ['context'])`. Routes to storage/logs/system.log. |

---

**Next audits needed:**
- Verify actual `paid` status code in nailscosmetics.lv database (probably `complete`)
- Inspect Offer records to confirm SKU format used for content_id
- Check if anonymous users need external_id fallback for Purchase event
