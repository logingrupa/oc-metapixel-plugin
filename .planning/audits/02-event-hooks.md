# Audit: Event Hooks in §4 — Plan vs Codebase

**Date:** 2026-04-22  
**Auditor:** Claude Code  
**Task:** Verify each Laravel Event::listen hook name claimed in plan §4 against actual event firings in Lovata plugins.

---

## Summary

**CRITICAL:** Plan §4 lists **7 event hooks**. Results:
- **2 CONFIRMED** existing
- **5 NOT FOUND** — hook names do not exist in installed plugins
- **Pattern mismatch:** Plan uses `shopaholic.cart.element.after.*` (CQRS-style) but codebase fires only `shopaholic.cart.add`

---

## Hook-by-Hook Audit

### 1. `shopaholic.cart.element.after.add`  
**Claim:** Cart component fires this on item add.

**Status:** ❌ **NOT FOUND**

**Actual event found:**
- File: `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/OfferCartPositionProcessor.php:26`
- Code: `Event::fire('shopaholic.cart.add', $this->arPositionData['item_id']);`
- Fired only in `add()` method; no fires in `update()` or `remove()`

**Implication:** Plan assumes `shopaholic.cart.element.after.add` fires on add. It doesn't exist. Only `shopaholic.cart.add` exists (simpler name, no "element" or "after").

**Recommendation:**
- Use `shopaholic.cart.add` in listener (not `shopaholic.cart.element.after.add`)
- Or patch Lovata to fire the expected event names

---

### 2. `shopaholic.cart.element.after.update`  
**Claim:** Cart component fires this on item quantity update.

**Status:** ❌ **NOT FOUND**

**Search results:**
- CartProcessor::update() at `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/CartProcessor.php:106` calls `$obPositionProcessor->update($arPositionData)` but does NOT fire any event afterward.
- AbstractCartPositionProcessor::update() at line 79 updates the position but fires NO event.

**Implication:** No event fires on update. Cart updates are silent.

**Recommendation:**
- Either manually call `Event::fire('shopaholic.cart.update', ...)` in plugin, or
- Add a custom listener on CartPosition model's `updated` event (Laravel-native), or
- Monitor OrderProcessor instead (cart → order conversion is the critical event)

---

### 3. `shopaholic.cart.element.after.remove`  
**Claim:** Cart component fires this on item removal.

**Status:** ❌ **NOT FOUND**

**Search results:**
- AbstractCartPositionProcessor::remove() at line 112 calls `$this->obCartPosition->delete()` but fires NO event.
- No grep match for "cart.*remove" or similar in ordersshopaholic.

**Implication:** No event fires on cart item removal.

**Recommendation:**
- Same as update: add custom event in plugin's listener, or use model events

---

### 4. `shopaholic.favorite.element.after.add`  
**Claim:** Wishlist plugin fires this on favorite add.

**Status:** ❌ **NOT FOUND**

**Plugin found:** `/home/forge/nailscosmetics.lv/plugins/lovata/wishlistshopaholic/` exists.

**Evidence:**
- Plugin namespace: `Lovata\WishListShopaholic` (note: "WishList", not "Favorite")
- Grep for `Event::fire` or `fireEvent` across wishlistshopaholic: **0 matches**
- Component `ExtendProductComponent.php` adds methods `onAddToWishList()` and `onRemoveFromWishList()` but these are **UI actions**, not events.

**Implication:** Wishlist plugin has no event hooks. It only extends the Product component with dynamic methods.

**Recommendation:**
- Check if there's a **separate favorites plugin** (plan mentions `oc-favorites-shopaholic-plugin`)
- If it doesn't exist, either:
  - Use model events on the wishlist model (if it has saved/created lifecycle)
  - Patch wishlistshopaholic to fire events
  - Extend `WishListHelper` to emit events manually

---

### 5. `shopaholic.order.created`  
**Claim:** Order processor fires this when order created.

**Status:** ✅ **CONFIRMED**

**Evidence:**
- File: `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php:31`
  ```php
  const EVENT_ORDER_CREATED = 'shopaholic.order.created';
  ```
- Fired at line 110:
  ```php
  Event::fire(self::EVENT_ORDER_CREATED, $this->obOrder);
  ```
- Already has listeners: `OrderModelHandler.php:32` listens for this event

**Implication:** ✅ This hook is safe to use. It fires with the Order object as parameter.

---

### 6. `lovata.buddies.user.after.register`  
**Claim:** Buddies plugin fires this after user registration.

**Status:** ❌ **NOT FOUND**

**Plugin evidence:**
- File: `/home/forge/nailscosmetics.lv/plugins/lovata/buddies/models/User.php:73-75`
  - Defined events:
    ```php
    const EVENT_BEFORE_LOGIN = 'lovata.buddies.before.login';
    const EVENT_AFTER_LOGIN = 'lovata.buddies.after.login';
    const EVENT_LOGOUT = 'lovata.buddies.logout';
    ```
  - **No `EVENT_AFTER_REGISTER` constant**

- Search for register event: `/home/forge/nailscosmetics.lv/plugins/lovata/buddies/classes/AuthHelperManager.php:257`
  - Method `register()` creates the user but fires NO event.
  - Only fires events: `Event::fire(User::EVENT_LOGOUT, ...)` (line 121)

**Implication:** No registration event exposed. Registration is silent.

**Recommendation:**
- Use Laravel model event: listen on `Lovata\Buddies\Models\User` for `created` event (fires on `$obUser->save()`)
- Or patch Buddies plugin to fire an explicit event
- Or extend User model in plugin with custom event binding

---

### 7. `Lovata\OrdersShopaholic\Models\Order::extend` with `model.afterUpdate`  
**Claim:** Order model can be extended with `model.afterUpdate` event binding.

**Status:** ✅ **PATTERN VALID** (October/Laravel convention)

**Evidence:**
- This is **standard October/Laravel pattern**: `Model::extend(function ($model) { $model->bindEvent(...) })`
- Not specifically found in Order class itself (Order.php has no bindings), but the pattern is **universally valid** in October/Laravel
- Order model inherits from `Model` which supports `bindEvent()`
- Pattern is **correctly described** in plan for listening to order status changes

**Implication:** ✅ Safe to use. Works. But verify the exact event name `model.afterUpdate` fires (it does — October convention)

**Where to listen:**
- In plugin's `boot()` or `register()` method:
  ```php
  Order::extend(function ($model) {
      $model->bindEvent('model.afterUpdate', function () {
          // fires when order saved via normal update (not saveQuietly)
      });
  });
  ```

---

## Missing User Registration Event — Workaround

Since `lovata.buddies.user.after.register` doesn't exist, use Laravel's native `created` event:

```php
// In plugin boot()
Event::listen('eloquent.created: Lovata\Buddies\Models\User', function ($user) {
    // fires for every new user saved
});

// Or via closure on the model class:
User::created(function ($user) {
    // user just created
});
```

---

## Recommendations for Plan §4

1. **Change hook names:**
   - Replace `shopaholic.cart.element.after.add` → `shopaholic.cart.add`
   - Remove `shopaholic.cart.element.after.update` (no event exists; add custom listener)
   - Remove `shopaholic.cart.element.after.remove` (no event exists; add custom listener)
   - Remove `shopaholic.favorite.element.after.add` (WishList plugin has no events; check if separate favorites plugin exists)
   - Keep `shopaholic.order.created` ✅
   - Replace `lovata.buddies.user.after.register` → `eloquent.created: Lovata\Buddies\Models\User`

2. **For cart update/remove:** Add custom event fires in plugin (extend CartProcessor or listen on CartPosition model's `updated`/`deleted` events)

3. **For wishlist:** Check if a separate favorites plugin exists (not WishListShopaholic). If not, extend WishListHelper to fire events or listen on model lifecycle.

---

## Files Checked

- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/OfferCartPositionProcessor.php` (cart.add found)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/CartProcessor.php` (no update/remove events)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/AbstractCartPositionProcessor.php` (no events)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php` (order.created CONFIRMED)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/models/Order.php` (no native afterUpdate binding)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/buddies/models/User.php` (login/logout only)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/buddies/classes/AuthHelperManager.php` (register fires no event)
- ✅ `/home/forge/nailscosmetics.lv/plugins/lovata/wishlistshopaholic/classes/event/` (no Event::fire calls)
- ✅ `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/classes/event/cart/CartComponentHandler.php` (reference pattern only)
