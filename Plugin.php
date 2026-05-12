<?php

namespace Logingrupa\Metapixelshopaholic;

/**
 * Minimal plugin shell so October recognises the plugin during Pest boot.
 *
 * No boot(), no register(), no event subscribers — those land in Phase 2 (SKEL-01).
 * The pluginDetails() method is the single contract October's PluginManager
 * relies on to enumerate this plugin during migrateModules() in the test harness.
 */
class Plugin extends \System\Classes\PluginBase
{
    public function pluginDetails(): array
    {
        return [
            'name'        => 'Metapixel Shopaholic',
            'description' => 'Meta Pixel + CAPI server-deduplicated tracking for Lovata Shopaholic.',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-shopping-cart',
        ];
    }
}
