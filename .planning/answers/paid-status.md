# Paid Status Code Investigation

## DEFINITIVE ANSWER
**Status Code for "Paid" Orders:** `new-payment-received`

**Status ID:** 5

---

## Database Query Results (Ground Truth)

### All Statuses in System
```
ID=1: code="new" → "Pasūtījums saņemts"
ID=2: code="in_progress" → "Rēķins nosūtīts uz epastu - gaidām apmaksu"
ID=3: code="complete" → "Pasūtījums pabeigts"
ID=4: code="canceled" → "Pasūtījums atcelts"
ID=5: code="new-payment-received" → "Pasūtījums saņemts - Apmaksa saņemta" ← PAID
ID=6: code="new-payment-canceled" → "Pasūtījums saņemts - Apmaksa atcelta"
ID=7: code="new-payment-error" → "Pasūtījums saņemts - apmaksas netika veikta"
ID=8: code="sent" → "Pasūtījums izsūtīts"
```

### Payment Methods Configuration
Both payment gateways set successful payments to status ID=5:

1. **PayPal** (id=1)
   - `after_status_id=5` → status.code=`new-payment-received`
   - Preview: "Apmaksa par jūsu pasūtījumu tika saņemta - tuvākajā laikā izsūtīsim Jūsu pasūtījumu"

2. **Vipps** (id=4)
   - `after_status_id=5` → status.code=`new-payment-received`
   - Same preview text

3. **COD** (id=2) & **Bank Transfer** (id=3)
   - No after_status configured (null/0)

---

## Code References

### Default Status Seeder
File: `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/updates/seeder_default_status.php`
- Only creates base statuses: new, in_progress, complete, canceled
- Status ID=5 (`new-payment-received`) is a custom addition (not in base seeder)

### Payment Flow
File: `/home/forge/nailscosmetics.lv/plugins/lovata/ordersshopaholic/classes/helper/AbstractPaymentGateway.php:155-169`
```php
protected function setSuccessStatus()
{
    $obStatus = $this->obPaymentMethod->after_status;  // Loads status from PaymentMethod.after_status_id
    if (empty($obStatus)) {
        return;
    }
    
    $this->obOrder->status_id = $obStatus->id;
    $this->obOrder->save();
    
    Event::fire(self::EVENT_PAYMENT_SUCCESS, [$this->obOrder]);
}
```

File: `/home/forge/nailscosmetics.lv/plugins/logingrupa/vippsshopaholic/classes/helper/VippsPaymentGateway.php:249`
- Vipps calls `$this->setSuccessStatus()` on successful payment authorization

---

## Verification Method
Admin can verify in Backend:
1. **Backend → Settings → Payment Methods**
2. Select "Maksāt ar PayPal" or "Maksāt ar Vipps"
3. Field: "Status after successful payment" = `new-payment-received` (ID=5)

OR query DB directly:
```sql
SELECT pm.name, s.code, s.name 
FROM lovata_orders_shopaholic_payment_methods pm
JOIN lovata_orders_shopaholic_statuses s ON pm.after_status_id = s.id
WHERE pm.after_status_id IS NOT NULL AND pm.after_status_id > 0;
```

---

## Summary
- **Status Code:** `new-payment-received`
- **Status ID:** 5
- **When Used:** Immediately after successful payment via PayPal or Vipps
- **Not Hardcoded:** Configured per PaymentMethod.after_status_id, not in code constants
- **Fallback:** If after_status_id is null/0, no status change occurs
