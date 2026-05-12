<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic;

use Backend;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixelshopaholic\Classes\Event\OrderStatusWatcher;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Components\PixelHead;
use Logingrupa\Metapixelshopaholic\Components\PurchasePixel;
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
     *   2. Skip middleware registration on CLI contexts only. CLI (artisan,
     *      queue workers) has no HTTP response at all so pushing an HTTP
     *      middleware is meaningless. WR-01 lock: backend-vs-storefront
     *      discrimination moved into EnsureFbpFbcCookies::handle() itself —
     *      a path-based check against `config('cms.backendUri')` resolves
     *      against the actual request URL rather than `App::runningInBackend()`,
     *      which at boot time depends on URL detection that may not have
     *      completed yet (especially with non-default BACKEND_URI).
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

        // 1b) Phase 3 plan 03-06 (PAY-03 / PAY-10 / PAY-11): subscribe
        // OrderStatusWatcher globally — storefront, backend, AND queue
        // worker contexts. The backend admin status-flip (bank-transfer
        // path PAY-11) MUST be observed since no browser-side Pixel twin
        // exists there. The queue worker MUST observe model events when a
        // saved-elsewhere Order is later rehydrated inside a job context.
        // Subscription happens BEFORE the CLI gate below — the gate only
        // skips the HTTP middleware push, not Event::subscribe.
        Event::subscribe(OrderStatusWatcher::class);

        // 2) CLI-only gate (WR-01): no HTTP response in CLI = nothing to
        // push to. Backend-vs-storefront discrimination happens inside the
        // middleware against the actual request URL.
        if (App::runningInConsole()) {
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

    /**
     * Register Phase 2 + Phase 3 components.
     *
     * pixelHead — emits fbq('init') + fbq('track', 'PageView', ..., {eventID})
     * with a server-generated UUIDv4 eventID. Renders alongside the theme's
     * existing partials/facebook_pixel.htm per SKEL-04 / CONTEXT Area 2 Q1.
     * Phase 4 FUN-01 will dispatch the CAPI twin from onRun().
     *
     * purchasePixel — Phase 3 plan 03-06 browser-side Pixel twin for the
     * thank-you page. Reads the persisted meta_purchase_event_id +
     * meta_purchase_event_time written atomically by OrderStatusWatcher
     * and emits fbq('track', 'Purchase', custom_data, {eventID}) so Meta
     * dedups Pixel + CAPI by event_id within its ±10 s event_time window.
     *
     * @return array<class-string, string>
     */
    #[\Override]
    public function registerComponents(): array
    {
        return [
            PixelHead::class => 'pixelHead',
            PurchasePixel::class => 'purchasePixel',
        ];
    }
}
