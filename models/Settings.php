<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Models;

use Lovata\OrdersShopaholic\Models\Status;
use Lovata\Shopaholic\Models\PriceType;
use Lovata\Toolbox\Models\CommonSettings;

/**
 * Class Settings
 *
 *
 * @property string $pixel_id
 * @property string $capi_access_token
 * @property string $test_event_code
 * @property string $currency_code
 * @property string $phone_country_code
 * @property bool $send_hashed_pii
 * @property string $queue_connection
 * @property string $paid_status_code
 * @property bool $refire_purchase_on_status_flip
 * @property bool $ensure_fbp_fbc_server_side
 * @property int $cost_price_type_id
 * @property bool $cost_price_excludes_vat
 */
class Settings extends CommonSettings
{
    public const SETTINGS_CODE = 'logingrupa_metapixelshopaholic_settings';

    /** @var string */
    public $settingsCode = 'logingrupa_metapixelshopaholic_settings';

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /**
     * RainLab.Translate per-locale fields. Only `pixel_id` is translatable so
     * sites running multiple Pixel IDs per locale (e.g. .lv vs .lt) can vary.
     * `capi_access_token` is deliberately NOT translatable — keeps the secret
     * out of the `rainlab_translate_message_data` table (threat T-02-04).
     *
     * @var array<int, string>
     */
    public $translatable = ['pixel_id'];

    /**
     * Mass-assignment allowlist (WR-02 lock). October's Settings array-form
     * `set([...])` would otherwise hydrate any field name posted by the
     * backend form — including invented keys not in fields.yaml. Explicit
     * allowlist matches the 10 SKEL-02 fields and prevents arbitrary keys
     * (e.g. via XSS-enabled CSRF on the backend Settings page) from being
     * persisted into `system_settings.value`.
     *
     * @var list<string>
     */
    public $fillable = [
        'pixel_id',
        'capi_access_token',
        'test_event_code',
        'currency_code',
        'phone_country_code',
        'send_hashed_pii',
        'queue_connection',
        'paid_status_code',
        'refire_purchase_on_status_flip',
        'ensure_fbp_fbc_server_side',
        'cost_price_type_id',
        'cost_price_excludes_vat',
    ];

    /**
     * Validation rules — backend Settings form save MUST conform.
     *
     * Phase 3 plan 03-06 PH-01 retro-fit (T-04-01 mitigation): Meta Pixel
     * IDs are numeric, 6–20 digits per Meta's documented pixel-create
     * flow (https://developers.facebook.com/docs/meta-pixel/get-started).
     * The regex is defence-in-depth against stored-XSS in
     * components/pixelhead/default.htm AND components/purchasepixel/default.htm
     * where the pixel_id is inlined into a `<script>` string. Backend
     * Settings is auth-gated; this rule protects against a compromised
     * admin account or SQL injection elsewhere in the chain.
     *
     * @var array<string, string>
     */
    public $rules = [
        'pixel_id' => 'nullable|regex:/^\d{6,20}$/',
    ];

    /**
     * Dropdown options for the `paid_status_code` field. Auto-invoked by
     * October's form builder via `options: getPaidStatusCodeOptions` in
     * fields.yaml.
     *
     * WR-06 lock: iterate the Eloquent Status collection explicitly rather
     * than going through `(array) Status::lists()`. The `lists()` return type
     * has drifted across October versions (Collection vs array); the cast
     * happens to flatten the current Collection's internal items array but
     * is fragile — `(array)` semantics on a Collection are not part of
     * October's documented contract. Explicit iteration is also type-stable
     * for status codes that look numeric (`'5'` vs `5` would otherwise
     * collide depending on which return shape `lists()` produces).
     *
     * @return array<string, string>
     */
    public function getPaidStatusCodeOptions(): array
    {
        $obList = Status::orderBy('sort_order')->get();
        $arResult = [];
        foreach ($obList as $obStatus) {
            // Use getAttribute() rather than dynamic property access so phpstan
            // level 10 can verify the call without Status having declared
            // @property docblocks (upstream Lovata.OrdersShopaholic model).
            $mCode = $obStatus->getAttribute('code');
            $mName = $obStatus->getAttribute('name');
            if (! is_scalar($mCode) || ! is_scalar($mName)) {
                continue;
            }
            $arResult[(string) $mCode] = (string) $mName;
        }

        return $arResult;
    }

    /**
     * Dropdown options for the `queue_connection` field. Static three-driver
     * list mirroring Laravel's queue.php config. Default value (`database`)
     * is enforced in fields.yaml, not here.
     *
     * @return array<string, string>
     */
    public function getQueueConnectionOptions(): array
    {
        return [
            'database' => 'database',
            'redis' => 'redis',
            'sync' => 'sync',
        ];
    }

    /**
     * Dropdown options for `cost_price_type_id` — populated from
     * `lovata_shopaholic_price_types` (id → name). Includes only active
     * types. Auto-invoked by October's form builder via
     * `options: getCostPriceTypeIdOptions` in fields.yaml.
     *
     * Same iteration shape as `getPaidStatusCodeOptions` (WR-06 lock):
     * explicit foreach + `getAttribute()` for phpstan-level-10 stability
     * against the upstream Lovata model without @property docblocks.
     *
     * @return array<int, string>
     */
    public function getCostPriceTypeIdOptions(): array
    {
        $obList = PriceType::where('active', 1)->orderBy('id')->get();
        $arResult = [];
        foreach ($obList as $obType) {
            $mId = $obType->getAttribute('id');
            $mName = $obType->getAttribute('name');
            if (! is_scalar($mId) || ! is_scalar($mName)) {
                continue;
            }
            $arResult[(int) $mId] = (string) $mName;
        }

        return $arResult;
    }
}
