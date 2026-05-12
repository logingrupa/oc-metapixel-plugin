<?php

namespace Logingrupa\Metapixelshopaholic;

use Backend;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use System\Classes\PluginBase;

/**
 * Class Plugin
 *
 * @author Logingrupa
 *
 * Plugin entry point. Phase 2 surface (SKEL-01):
 *   - Declares the narrowed `$require` list (Buddies dependency removed — optional via composer suggest).
 *   - Returns lang-keyed `pluginDetails()` so RainLab.Translate resolves backend labels.
 *   - `registerSettings()` maps the SettingsManager category to `Models\Settings`.
 *   - `boot()` is intentionally empty — middleware push and PluginGuard prime
 *     land in Plans 02-02 (PluginGuard) and 02-03 (kernel pushMiddleware).
 *
 * Hungarian notation applies to runtime variables only; class properties use
 * the sibling-plugin convention (`$require`, `$settingsCode` etc.) per
 * Lovata.Toolbox precedent.
 */
class Plugin extends PluginBase
{
    /**
     * Required plugins. The optional user-plugin (Buddies) is intentionally
     * absent — runtime user-plugin detection happens via
     * Lovata\Toolbox\Classes\Helper\UserHelper (which probes
     * PluginManager::instance()->exists() against the supported user plugins).
     * Hard-requiring an optional user plugin would break clean OctoberCMS +
     * Shopaholic installs.
     *
     * @var list<string>
     */
    public $require = [
        'Lovata.Toolbox',
        'Lovata.Shopaholic',
        'Lovata.OrdersShopaholic',
    ];

    /**
     * Returns information about this plugin (lang-keyed for RainLab.Translate).
     *
     * @return array{name: string, description: string, author: string, icon: string, homepage: string}
     */
    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name' => 'logingrupa.metapixelshopaholic::lang.plugin.name',
            'description' => 'logingrupa.metapixelshopaholic::lang.plugin.description',
            'author' => 'Logingrupa',
            'icon' => 'icon-shopping-cart',
            'homepage' => 'https://logingrupa.lv',
        ];
    }

    /**
     * Boot method, called right before the request route.
     *
     * Boot wiring lands in Plan 02-02 (PluginGuard prime) and 02-03 (kernel
     * pushMiddleware). Phase 2 keeps boot minimal per CONTEXT Area 1 Q1 —
     * registering Event::subscribe handlers for classes that don't yet exist
     * would make the plugin unbootable.
     */
    public function boot(): void
    {
        // Boot wiring lands in Plan 02-02 (PluginGuard prime) and 02-03 (kernel pushMiddleware). Phase 2 keeps boot minimal per CONTEXT Area 1 Q1.
    }

    /**
     * Register backend Settings menu entry.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function registerSettings(): array
    {
        return [
            'logingrupa-metapixelshopaholic-settings' => [
                'label' => 'logingrupa.metapixelshopaholic::lang.settings.label',
                'description' => 'logingrupa.metapixelshopaholic::lang.settings.description',
                'category' => 'lovata.shopaholic::lang.tab.settings',
                'icon' => 'icon-shopping-cart',
                'class' => Settings::class,
                'order' => 500,
            ],
        ];
    }
}
