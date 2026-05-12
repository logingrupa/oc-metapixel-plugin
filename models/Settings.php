<?php

namespace Logingrupa\Metapixelshopaholic\Models;

use Lovata\OrdersShopaholic\Models\Status;
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
     * Dropdown options for the `paid_status_code` field. Auto-invoked by
     * October's form builder via `options: getPaidStatusCodeOptions` in
     * fields.yaml. Uses Status::lists('name','code') (NOT pluck) — the
     * CodeField scope on Status provides the lists() method via
     * Kharanenka\Scope\CodeField.
     *
     * @return array<string, string>
     */
    public function getPaidStatusCodeOptions(): array
    {
        $arRaw = (array) Status::lists('name', 'code');
        $arResult = [];
        foreach ($arRaw as $mCode => $mName) {
            if (! is_scalar($mName)) {
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
}
