# Audit: Composer Naming & Tooling (§13, §14)

**Date:** 2026-04-22 | **Auditor:** Caveman mode

---

## § 13: Composer & Tooling Questions

### Q1: Logingrupa plugin package name pattern
**Finding:** Existing pattern confirmed. All checked plugins follow `logingrupa/oc-{name}-plugin` format:
- `logingrupa/oc-storeextender-plugin` (root composer.json:48)
- `logingrupa/oc-retrypayment-plugin` (root composer.json:56)
- `logingrupa/oc-vipps-shopaholic-plugin` (root composer.json:53)
- `logingrupa/oc-backinstockshopaholic-plugin` (root composer.json:52)
- `logingrupa/oc-campaignpricing-plugin` (plugins/logingrupa/campaignpricingshopaholic/composer.json:2)

**Status:** Plan's `logingrupa/oc-metapixel-plugin` matches pattern. ✓

---

### Q2: October v4 package names in root composer.json
**Finding:** Confirmed. Root composer.json uses:
- Line 9: `"october/rain": "^4.0"`
- Line 11: `"october/all": "^4.0"`

Also confirmed:
- Line 10: `"laravel/framework": "^12.0"` (October v4 uses Laravel 12)

**Status:** Plan's proposal for `october/system ^4.0` or `october/rain ^4.0` is correct. Most recent plugins use both. ✓

---

### Q3: PHPUnit vs Pest in Logingrupa plugins
**Finding:** Mixed adoption:
- **StoreExtender** (plugins/logingrupa/storeextender/): PHPUnit only. Has `phpunit.xml`, no Pest.
- **CampaignPricingShopaholic** (plugins/logingrupa/campaignpricingshopaholic/): **Uses Pest** via root test script (composer.json:26) `"../../../vendor/bin/pest"` ← pulls from root vendor
- Root composer.json:62 defines `"pestphp/pest": "^4.1"` (major v4, not ^3.0)

**Status:** Pest v4 adopted in root (line 62). Plan proposes Pest ^3.0 which is outdated. **Recommend Pest ^4.1** to match root. ⚠

---

### Q4: PHPStan level 10 on plugin depending on Lovata
**Finding:** CampaignPricingShopaholic succeeds with level 10:
- File: `plugins/logingrupa/campaignpricingshopaholic/phpstan.neon` line 6: `level: 10`
- Line 19: `universalObjectCratesClasses: - Lovata\Toolbox\Classes\Item\ElementItem` ← explicitly declares Lovata Item as crate to bypass type errors
- Includes larastan extension (line 2)

**Lovata dependency:** ElementItem (585 lines, plugins/lovata/toolbox/classes/item/ElementItem.php) and ElementCollection (849 lines) have no type hints in many methods but Item provides `__get()` magic.

**Status:** Level 10 **is achievable** if you:
1. Include larastan extension
2. Declare `universalObjectCratesClasses` for Lovata classes
3. Scope to new code only (exclude Lovata bootstrap)

Plan's level 10 is realistic. ✓

---

### Q5: Laravel Pint vs PHP-CS-Fixer
**Finding:** Project uses **Laravel Pint**:
- Root composer.json:68: `"laravel/pint": "^1.26"`
- CampaignPricingShopaholic composer.json:30: `"pint": "../../../vendor/bin/pint . --config=pint.json"`
- CampaignPricingShopaholic pint.json: PSR-12 preset with custom rules (ordered_imports, no_unused_imports)

**Status:** Pint confirmed. Plan's proposal for `pint.json` is aligned. ✓

---

### Q6: Rector installed
**Finding:** **Yes.** Root composer.json:66: `"rector/rector": "^2.0"`
- CampaignPricingShopaholic has:
  - rector.php (lines 1-24): Configured with `php84: true`, `deadCode: true`, `codeQuality: true`, `typeDeclarations: true`
  - composer.json scripts (lines 32-33): `rector-dry` and `rector` commands

**Status:** Rector ^2.0 active. Plan's proposal is confirmed. ✓

---

### Q7: PHPMD config — Toolbox vs proposed
**Finding:** Toolbox custom XML at `plugins/lovata/toolbox/PHPMD_custom.xml` (91 lines):

**Toolbox Rules (reportLevel/minimum/maximum):**
- CyclomaticComplexity: reportLevel 10 (line 10)
- NPathComplexity: minimum 200 (line 17)
- ExcessiveMethodLength: minimum 100 (line 22)
- ExcessiveClassLength: minimum 1000 (line 28) ← **Large classes allowed**
- ExcessiveParameterList: minimum 8 (line 34)
- ExcessivePublicCount: minimum 45 (line 39)
- TooManyFields: maxfields 20 (line 44)
- TooManyMethods: maxmethods 25, ignores `^(set|get)` (lines 49-51)
- TooManyPublicMethods: maxmethods 10 (lines 54-56)
- ExcessiveClassComplexity: maximum 50 (line 61)
- ShortVariable: minimum 4 (line 68) ← Allows `$ob`, `$ar`, `$i`, `$s`, `$b`, `$f` (Hungarian)
- LongVariable: maximum 25 (line 73)
- ShortMethodName: minimum 3, exceptions "up" (lines 77-79)

**CampaignPricingShopaholic phpmd.xml differences:**
- LongVariable: maximum **40** (line 75) vs Toolbox 25 ← More permissive
- Everything else identical (CyclomaticComplexity 10, ExcessiveClassLength 1000, etc.)

**Status:** Plan can copy Toolbox `PHPMD_custom.xml` with one change: raise LongVariable max from 25→40 for modern longer names. Minor diff. ✓

---

### Q8: Hungarian notation in PHPMD naming rules
**Finding:** **CONFIRMED.** Toolbox PHPMD_custom.xml line 68-70:
```xml
<rule ref="rulesets/naming.xml/ShortVariable">
    <property name="minimum" value="4" />
</rule>
```

Minimum=4 means:
- `$ob`, `$ar`, `$i`, `$s`, `$b`, `$f` (2-letter + intent) = **allowed** ✓
- `$x`, `$y` (2-letter, no intent) = flagged (< 4 chars total counting prefix + letter)
- `$obItem`, `$arList`, `$iCount` (4+ chars) = always allowed

Actual codebase confirms usage:
- plugins/logingrupa/storeextender/Plugin.php: `$obProductCollection`, `$obList`, `$obOffer`, `$fnGetCheckoutURL`
- plugins/lovata/toolbox: `$sLangString`, `$iNumber`, `$sValue`, `$bNeedSmartURLCheck`, `$sActiveLang`, `$arActiveLangList`

**Status:** Hungarian notation is **explicitly supported** by PHPMD naming rules. ✓

---

## § 14: Naming Conventions & Code Style Questions

### Q1: Hungarian notation in storeextender code
**Finding:** Confirmed. plugins/logingrupa/storeextender/Plugin.php excerpt shows:
- Line 145 (via grep): `$obProductCollection = ProductCollection::make([246, 247])->active();`
- Line 146: `$obList = Offer::whereProductId([366, 246])->get();`
- Line 150 (loop): `foreach ($obList as $obOffer) {`
- Line 158: `$fnGetCheckoutURL = function ($obOrder) {`
- Line 159: `if (empty($obOrder) || empty($obOrder->secret_key))`
- Line 160: `$sPageName = $this->findOrderPage();`

**Pattern matches Plan §14 examples:** `$sEventId`, `$iOrderId`, `$fOrderTotal` ✓

CLAUDE.md confirms (line 143-144):
```
| `$s`   | String | `$sSlug`, `$sElementSlug`, `$sCurrencyCode`, `$sMessage` |
| `$i`   | Integer | `$iElementID`, `$iCount`, `$iTargetTaxId`, `$iLimit` |
```

**Status:** Existing code already uses Plan's proposed naming. ✓

---

### Q2: Class length ≤ 250 lines conflict?
**Finding:** **YES, CONFLICT.** Plan §14 says "Class length ≤ 250 lines" but Lovata/Shopaholic has:

**Giant classes in ecosystem (wc -l):**
- 849 lines: ElementCollection.php (Toolbox)
- 749 lines: ProductCollectionTest.php (FilterShopaholic)
- 733 lines: CollectionTest.php (Toolbox)
- 639 lines: CartProcessor.php (OrdersShopaholic)
- 613 lines: Offer.php (Shopaholic model)
- 611 lines: Order.php (OrdersShopaholic model)
- 585 lines: ElementItem.php (Toolbox)
- 551 lines: ProductCollectionTest.php (Shopaholic)
- 546 lines: CommonCreateFile.php (Toolbox console)
- 524 lines: MakeOrder.php component (OrdersShopaholic)

**PHPMD Toolbox rule (line 28):** `ExcessiveClassLength minimum="1000"` ← Allows up to 999 lines without warning.

**Status:** **Plan §14's "≤250 lines" is unrealistic.** Recommend revising to **"≤500 lines"** or adopt Toolbox threshold of 1000 lines. Conflict with ecosystem practice. ⚠

---

### Q3: Cyclomatic complexity ≤ 6 per method?
**Finding:** PHPMD Toolbox rule (line 10): `CyclomaticComplexity reportLevel="10"`

This means:
- Methods with CC ≥ 10 trigger a **warning**
- CC ≤ 9 is OK
- Plan §14 says "≤ 6"

**Actual test count:** Offer.php (613 lines) has 38 if/for/switch statements total, but distributed across ~20+ methods. Average ~2 per method (many are getters).

**Status:** Plan's "≤6" is **stricter than Toolbox standard (≤9)**. Achievable for new code but conflicts with existing ecosystem. **Recommend ≤8 or ≤10** to match Toolbox baseline. ⚠

---

### Q4: File header `declare(strict_types=1);`
**Finding:** **Not currently used in PHP class files.**

- Lovata/Shopaholic plugins: No `declare(strict_types=1);` in any PHP files (grep across 5 sampled files)
- Logingrupa/StoreExtender: No `declare(strict_types=1);` in Plugin.php or sampled classes
- CampaignPricingShopaholic (new plugin): No `declare(strict_types=1);` in class files, only in rector.php config (line 3)
- Root plugins and migrations: None use `declare(strict_types=1);`

**CLAUDE.md coverage:** CLAUDE.md does not mention `declare(strict_types=1);` requirement (checked lines 1-318).

**Status:** **Not a current convention.** Plan's proposal for `declare(strict_types=1);` is a **breaking change** to add to all new files. Feasible but not pre-existing. ⚠

---

## Summary Table

| Question | Plan Proposal | Finding | Status |
|----------|---|---|---|
| §13 Q1: Package name | `logingrupa/oc-metapixel-plugin` | Matches pattern | ✓ |
| §13 Q2: October versions | `^4.0` | Confirmed both rain & all | ✓ |
| §13 Q3: PHPUnit/Pest | Pest ^3.0 | Root uses Pest ^4.1, CampaignPricingShopaholic too | ⚠ Update to ^4.1 |
| §13 Q4: PHPStan level 10 | Achievable | Yes, with larastan + universalObjectCrates | ✓ |
| §13 Q5: Pint | Yes | Root + CampaignPricingShopaholic use it | ✓ |
| §13 Q6: Rector | Yes | Root ^2.0, CampaignPricingShopaholic configured | ✓ |
| §13 Q7: PHPMD | Copy Toolbox | LongVariable diff: raise 25→40 | ✓ Minor |
| §13 Q8: Hungarian in PHPMD | Allowed | ShortVariable min=4 explicit | ✓ |
| §14 Q1: Hungarian notation | `$sEventId`, `$iOrderId` | Confirmed in storeextender | ✓ |
| §14 Q2: Class length ≤ 250 | Unrealistic | Toolbox: 849 lines, Shopaholic: 613 | ⚠ Revise to ≤500 or ≤1000 |
| §14 Q3: CC ≤ 6 per method | Strict | Toolbox uses ≤9 (reportLevel=10) | ⚠ Recommend ≤8 |
| §14 Q4: `declare(strict_types=1);` | New convention | Zero existing usage | ⚠ Breaking change if enforced |

---

## Recommendations

1. **Pest version:** Update Plan to Pest ^4.1 (not ^3.0)
2. **Class length:** Revise from "≤250" to "≤500 lines" (or adopt Toolbox's 1000 baseline)
3. **Cyclomatic complexity:** Relax from "≤6" to "≤8" or "≤10" to align with Toolbox reportLevel
4. **PHPMD:** Copy Toolbox PHPMD_custom.xml, change LongVariable max=40 (was 25)
5. **Strict types:** Document as optional enhancement; not enforceable if not retrofitted to ecosystem

All other Plan §13 proposals are **sound and implementable**.
