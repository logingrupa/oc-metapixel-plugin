<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Lovata\OrdersShopaholic\Models\Status;

/**
 * Dropdown option helpers for Settings YAML fields that depend on Lovata
 * models. Lives inside classes/adapter/shopaholic/ so the Lovata imports stay
 * within the composer-dependency-analyser-permitted boundary (P-03) and
 * models/Settings.php remains Lovata-free.
 */
final class ShopaholicSettingsOptions
{
    /**
     * Options for the Settings paid_status_code dropdown. Sourced from the
     * current rows of lovata_orders_shopaholic_statuses ordered by sort_order.
     * Returns an empty array when Lovata.OrdersShopaholic is not installed
     * so the minimal-install cell does not crash at field render time.
     *
     * @return array<string, string>
     */
    public static function getPaidStatusCodeOptions(): array
    {
        if (! class_exists(Status::class)) {
            return [];
        }

        /** @var array<string, string> $arOptions */
        $arOptions = Status::orderBy('sort_order')->pluck('name', 'code')->all();

        return $arOptions;
    }

    /**
     * Options for the Settings default_currency_code dropdown. Small static
     * map covering the ISO-4217 currencies the multisite cluster uses
     * (NOK, EUR, USD, GBP). Extend at Phase 4 if operator demand surfaces.
     *
     * @return array<string, string>
     */
    public static function getDefaultCurrencyCodeOptions(): array
    {
        return [
            'EUR' => 'EUR',
            'NOK' => 'NOK',
            'USD' => 'USD',
            'GBP' => 'GBP',
        ];
    }
}
