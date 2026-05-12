<?php

namespace Logingrupa\Metapixelshopaholic;

use Backend;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies;
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
     * Order matters:
     *
     *   1. Prime PluginGuard unconditionally — every context (storefront, backend,
     *      console, queue) MUST see the disabled flag so Phase 3+ handlers can
     *      short-circuit via App::make('metapixel.disabled'). SKEL-05.
     *   2. Skip middleware registration on backend + console contexts. The
     *      EnsureFbpFbcCookies middleware is storefront-only: backend routes
     *      should not poison Set-Cookie headers with tracking cookies, and CLI
     *      contexts (artisan, queue workers) have no HTTP response at all.
     *   3. Push EnsureFbpFbcCookies via Laravel's HTTP Kernel — October's
     *      PluginBase has no `registerMiddleware()` method (verified at
     *      modules/system/classes/PluginBase.php, lines 40-291). The correct
     *      Laravel-native path is `app(HttpKernel::class)->pushMiddleware(...)`
     *      per Kernel.php:362-369. See PATTERNS.md lines 146-164.
     *
     * Event::subscribe(...) handler registration lands in Phase 3+ when the
     * concrete handler classes ship — registering missing classes would make
     * the plugin unbootable (CONTEXT Area 1 Q1).
     */
    public function boot(): void
    {
        // 1) Prime PluginGuard in every context (CONTEXT Area 1 Q2-Q3 + SKEL-05).
        PluginGuard::instance();

        // 2) Storefront-only gate: skip middleware push on backend and CLI contexts.
        if (App::runningInBackend() || App::runningInConsole()) {
            return;
        }

        // 3) Push EnsureFbpFbcCookies onto the global HTTP middleware stack.
        /** @var HttpKernel $obKernel */
        $obKernel = $this->app->make(HttpKernel::class);
        $obKernel->pushMiddleware(EnsureFbpFbcCookies::class);
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
