# Audit 04 — Architecture: Plugin folder structure vs Lovata conventions

## Summary
Plan §6 proposes `classes/{meta,listeners,jobs,middleware,helpers}` structure. Lovata.Toolbox standard is **lowercase plural** (`classes/{collection,item,store,event,helper,parser,console,component,queue,storage}`). **Three concrete issues found; one critical naming conflict.**

---

## Findings

### Q1: Standard Lovata plugin classes/ layout?

**CONFIRMED**: Lovata convention is **lowercase, plural, no file-per-class subdivision**.

Examined:
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/` — backbone reference
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/` — exemplar plugin

Standard folders found (all lowercase):
- `collection/` — Collection classes (e.g., ProductCollection)
- `item/` — Item wrappers (e.g., ProductItem extends ElementItem)
- `store/` — Store classes (e.g., ProductListStore)
- `event/` — Event listeners & handlers (e.g., ProductModelHandler extends ModelHandler)
- `helper/` — Helper classes (e.g., PageHelper, ImportHelper)
- `parser/` — Data parsers (Toolbox only)
- `console/` — Console commands (optional)
- `component/` — ComponentBase subclasses (Toolbox only)
- `queue/` — Queue job handlers (Toolbox: `classes/queue/ImportItemQueue.php`)
- `storage/` — Storage helpers (Toolbox only)

**Issue #1**: Plan uses mixed case: `classes/meta/`, `classes/listeners/`. Toolbox uses all **lowercase**, e.g. `classes/queue/` not `classes/jobs/`.

---

### Q2: Do plugins need Store/Collection/Item for non-catalogue data?

**ANSWER: No, only if you cache and query that data via Toolbox's pattern.**

**Analysis**:
- Lovata plugins that *expose data to frontend* (Product, Category, Offer) wrap models in `Item` (w/ ElementItem base) + `Store`/`Collection` for caching via CCache.
- Plugins that are *purely event-firing* (e.g., FilterShopaholic, SubscriptionsShopaholic) have no Item/Store — only event listeners + controllers.
- FailedEvent (dead-letter table) is **admin-only audit log**, not customer data. No Item wrapper needed.

**BUT — Plan §3 requires user_data hashing per-request**:

Plan says: "per-request for order (§3) — we need cached user_data hashing to avoid re-hashing per event in same request."

**RECOMMENDATION**: Use CCache (Toolbox's TaggedCache) at **request scope** (not permanent cache). Example:
```php
$sCacheKey = 'meta-pixel:user-hash:' . $obUser->id . ':' . $iRequestTime;
$aUserDataHashes = CCache::get(['meta-pixel-request'], $sCacheKey);
if (!$aUserDataHashes) {
    $aUserDataHashes = UserDataHasher::hash($obUser, ...);
    CCache::forever(['meta-pixel-request'], $sCacheKey, $aUserDataHashes);  // Lives until request ends
}
```

This avoids duplicate sha256() calls within the same request (e.g., if Purchase event fires + thank-you page both hash the user). But **not** a persistent Store/Collection — just request-level tagging.

---

### Q3: `classes/jobs/` vs `classes/queue/`?

**ANSWER**: Lovata convention is **singular** `classes/queue/`, NOT plural.

**Evidence**: `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/queue/ImportItemQueue.php` — note **singular** `queue` folder.

Also found:
- `/home/forge/nailscosmetics.lv/plugins/lovata/discountsshopaholic/classes/queue/RunProductPriceProcessor.php`
- `/home/forge/nailscosmetics.lv/plugins/lovata/basecode/classes/queue/ParseOrderItemFromOneC.php`

All use **`classes/queue/`** (singular).

**Issue #2**: Plan proposes `classes/jobs/` (plural). Must be **`classes/queue/`** (singular, lowercase).

---

### Q4: `classes/middleware/` — is that Lovata pattern or Laravel-only?

**ANSWER**: **NOT a Lovata pattern**. No `middleware/` folder found in any Lovata plugin examined.

**Evidence**:
- No `middleware/` in `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/`
- No `middleware/` in `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/`
- No `middleware/` in any Logingrupa plugin examined

**BUT**: Laravel middleware (if needed for HTTP pipeline) belongs in **OctoberCMS plugin root**, typically at the plugin's top level or under a `middleware/` folder in the plugin dir structure (not nested under `classes/`).

**Pattern**: OctoberCMS plugins register middleware via `Plugin.php → boot()`:
```php
$this->registerMiddleware([
    \Logingrupa\MetapixelShopaholic\Middleware\EnsureFbpFbcCookies::class,
]);
```

The middleware file lives at `/plugins/logingrupa/metapixelshopaholic/middleware/EnsureFbpFbcCookies.php`, NOT `classes/middleware/`.

**Issue #3**: Plan shows `classes/middleware/`. Should be **root-level `middleware/`** folder per OctoberCMS convention, registered in Plugin.php.

---

### Q5: Components split per-page or one-per-event-type?

**ANSWER**: Split per page (one component per context), **not** one per event.

**Evidence**: Shopaholic pattern:
- `ProductPage.php` — renders entire product detail page, fires ViewContent event
- `CategoryPage.php` — renders category/listing page, fires ViewCategory event
- `ProductData.php` — exposes single product (data-only, no page wrapper)
- `ProductList.php` — exposes products list (data-only)

Each component is a **page wrapper**, not an event. The event is fired **inside** the component's Twig output (via JavaScript).

Plan §6 proposes:
- `PixelHead.php` — base snippet + per-page event ✓ (correct)
- `ProductPagePixel.php` — ViewContent ✓ (correct)
- `CategoryPagePixel.php` — ViewCategory ✓ (correct)
- `CheckoutPixel.php` — InitiateCheckout ✓ (correct)

Plan pattern is **aligned** with Lovata. Each component wraps a page context.

---

### Q6: Logingrupa.storeextender structure (existing plugin reference)

**EXAMINED**: `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/`

Structure:
```
classes/
  event/              # only folder
  helper/             # only folder
components/
  CustomProductPage.php
  LazyPromoBlockLoader.php
  lazypromoblockloader/
controllers/
  (backend + routes)
models/
  group/              # backend YAML config only (not Eloquent models)
```

**Findings**:
- No `Store/`, `Collection/`, `Item/` — pure extension plugin
- Only `event/` + `helper/` in classes
- Components are page-wrappers + lazy loaders
- Models are backend form configs (YAML), not live data caching

This is **minimal event/helper pattern** — applies only to event-firing plugins without data modeling.

---

### Q7: FailedEvent model — Item-wrapped or plain Eloquent?

**ANSWER**: **Plain Eloquent Model**, NOT Item-wrapped.

**Reasoning**:
1. FailedEvent is **dead-letter audit log** — admin-only, never exposed to frontend or cached.
2. Lovata uses Item + ElementItem + Store only for **customer-facing data** that needs frontend caching (Product, Category, Offer, etc.).
3. System/log models are plain Eloquent with Traits (e.g., `TraitCached` only if the plugin itself queries the log for reporting).

**Pattern in Lovata** (checked OrdersShopaholic):
- Public models (Order, Product) → plain Eloquent + TraitCached + Traits + validation
- Never wrapped in Item unless exposed via Collection to frontend

**FailedEvent structure** (recommended):
```php
<?php namespace Logingrupa\MetapixelShopaholic\Models;

use Model;
use October\Rain\Database\Traits\Validation;

class FailedEvent extends Model
{
    protected $table = 'meta_pixel_failed_events';
    protected $guarded = [];
    protected $casts = [
        'payload' => 'json',
        'failed_at' => 'datetime',
    ];
}
```

**No Item wrapper needed.** Use it directly in backend UI (health page, replay form).

---

## Concrete Path Adjustments

| Plan Proposal | Issue | Recommended Fix | Rationale |
|---|---|---|---|
| `classes/meta/` | Lovata uses lowercase dirs | Keep as **`classes/meta/`** | Meta-specific logic; not a Toolbox folder. Accept plan's deviation. |
| `classes/listeners/` | Lovata uses `classes/event/` | Rename to **`classes/event/`** | Align w/ Lovata pattern. All listeners extend ModelHandler (Toolbox convention). |
| `classes/jobs/` | Lovata uses `classes/queue/` (singular) | Rename to **`classes/queue/`** | Singular, lowercase, consistent w/ toolbox + discountsshopaholic + basecode. |
| `classes/middleware/` | Not a Lovata pattern; goes in OctoberCMS root | Move to **`middleware/`** folder (root of plugin) | OctoberCMS convention; register in Plugin.php → registerMiddleware(). |
| `classes/helpers/` | Lovata uses `classes/helper/` (singular) | Rename to **`classes/helper/`** | Singular. Plan lists two (Consent, ViewBag) — both fit in `classes/helper/`. |
| `models/FailedEvent.php` | Dead-letter should be plain Eloquent | Keep plain Eloquent; **no Item wrapper** | Admin-only audit log, not customer data. Item pattern is for frontend-cacheable models. |
| `components/PixelHead.php` etc. | One-per-page pattern correct | No change | Aligned with ProductPage, CategoryPage, etc. examples. |

---

## CCache Usage for user_data hashing (§3 requirements)

**Plan requirement**: "cached user_data hashing per-request for order (§3) — avoid re-hashing same user within request."

**Implementation**:
```php
class UserDataHasher
{
    public static function hashAndCache(User $obUser, string $sRequestId): array
    {
        $sCacheKey = "meta-pixel:user-hash:{$obUser->id}:{$sRequestId}";
        $aCached = CCache::get(['meta-pixel-request'], $sCacheKey);
        
        if ($aCached) {
            return $aCached;
        }
        
        $aHashes = [
            'em'     => hash('sha256', strtolower(trim($obUser->email))),
            'fn'     => hash('sha256', strtolower(trim($obUser->first_name ?? ''))),
            'ln'     => hash('sha256', strtolower(trim($obUser->last_name ?? ''))),
            // ... more fields
        ];
        
        CCache::forever(['meta-pixel-request'], $sCacheKey, $aHashes);
        return $aHashes;
    }
}
```

Request ID can be from `request()->id()` (Illuminate\Http\Request) or generate once in middleware.

---

## Summary of Corrections

1. **`classes/listeners/` → `classes/event/`** — standardize on Lovata pattern
2. **`classes/jobs/` → `classes/queue/`** — Lovata singular, lowercase
3. **`classes/middleware/` → `middleware/`** — move to plugin root; register in Plugin.php
4. **`classes/helpers/` → `classes/helper/`** — Lovata singular
5. **FailedEvent**: keep plain Eloquent Model; **no Item wrapper**
6. **CCache for user_data**: use request-scoped tagging to avoid duplicate hashing
7. **components/** structure: **keep as-is**, aligned w/ Lovata pattern
8. **models/**: **keep as-is**, plain Eloquent + Traits only

---

## Cited Paths

- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/` (backbone reference)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/` (exemplar)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/queue/ImportItemQueue.php` (queue pattern)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/components/ProductPage.php` (component pattern)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/item/ProductItem.php` (Item pattern)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/event/product/ProductModelHandler.php` (event pattern)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/store/AbstractStore.php` (CCache usage)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/classes/` (minimal event/helper example)

---

**Next**: Answer plan's open questions (Q1–Q5 in audit-01) before implementing §6.
