<?php

namespace Logingrupa\Metapixel;

use Backend;
use Cms\Classes\Controller as CmsController;
use Cms\Classes\ThisVariable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Classes\Helper\PixelHeadDeferredFlushBuffer;
use Logingrupa\Metapixel\Components\EventPixel;
use Logingrupa\Metapixel\Components\PixelHead;
use Logingrupa\Metapixel\Components\ProductPixel;
use Logingrupa\Metapixel\Console\PurgeEventLog;
use Logingrupa\Metapixel\Console\RefreshPsl;
use Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Models\Product;
use System\Classes\PluginBase;
use System\Classes\PluginManager;

/**
 * Meta Pixel + Conversions API tracking plugin.
 *
 * register() binds the AdapterRegistry as a service-container singleton.
 * Third parties register their own adapters from their plugin's boot() via
 * AdapterRegistry::register($sSubjectClass, $sAdapterClass).
 */
class Plugin extends PluginBase
{
    /** @var list<string> */
    public $require = ['Lovata.Toolbox'];

    /**
     * @return array{name: string, description: string, author: string, icon: string, homepage: string}
     */
    public function pluginDetails(): array
    {
        return [
            'name' => 'logingrupa.metapixel::lang.plugin.name',
            'description' => 'logingrupa.metapixel::lang.plugin.description',
            'author' => 'Logingrupa',
            'icon' => 'icon-bullseye',
            'homepage' => 'https://github.com/logingrupa/oc-metapixel-plugin',
        ];
    }

    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
        $this->app->singleton(ThemeEventCollector::class);
        $this->app->singleton(
            HostIndexResolver::class,
            fn () => new HostIndexResolver(
                base_path('plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat')
            )
        );
        $this->app->singleton(PixelHeadDeferredFlushBuffer::class);
        $this->registerConsoleCommand('metapixel:purge-event-log', PurgeEventLog::class);
        $this->registerConsoleCommand('metapixel:refresh-psl', RefreshPsl::class);
    }

    public function boot(): void
    {
        if ($this->isShopaholicEnabled()) {
            // One-guard pattern (RESEARCH §10): OrdersShopaholic transitively requires Shopaholic.
            $obRegistry = App::make(AdapterRegistry::class);
            $obRegistry->register(Order::class, ShopaholicOrderAdapter::class);
            $obRegistry->register(CartPosition::class, ShopaholicCartPositionAdapter::class);
            $obRegistry->register(Product::class, ShopaholicProductAdapter::class);
            Event::subscribe(OrderStatusWatcher::class);
            Event::subscribe(CartPositionWatcher::class);
            Event::subscribe(ProductPageWatcher::class);
        }

        Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {
            $mThis = $obController->vars['this'] ?? null;
            if (! $mThis instanceof ThisVariable) {
                return;
            }
            $mThis->config['metapixel'] = App::make(ThemeEventCollector::class);
        });

        // Second cms.page.beforeRenderPage listener — drains ThemeEventCollector
        // into the deferred-flush buffer. Pure observer; fires AFTER all
        // page-tier component onRun() per Cms\Classes\Controller line 421
        // lifecycle anchor.
        Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {
            PixelHead::flushDeferredFromController($obController);
        });

        // ThemeActionAdapter registers unconditionally — Theme path works on any
        // OctoberCMS install regardless of cart-plugin presence (D-13).
        App::make(AdapterRegistry::class)->register(
            ThemeActionEvent::class,
            ThemeActionAdapter::class,
        );
        Event::subscribe(ThemeAjaxHandler::class);

        $this->app[Kernel::class]->pushMiddleware(EnsureFbpFbcCookies::class);
    }

    /**
     * Theme components. EventPixel for server-confirmed adapter subjects;
     * PixelHead for accumulator-based theme events.
     *
     * @return array<class-string, string>
     */
    public function registerComponents(): array
    {
        return [
            EventPixel::class => 'eventPixel',
            PixelHead::class => 'pixelHead',
            ProductPixel::class => 'productPixel',
        ];
    }

    /**
     * Twig markup-tag surface. THEM-04 ships as a dot-notation hard contract
     * via the Controller::extend mount in boot(); the bare-function fallback
     * was dropped in revision iteration 1. Empty arrays preserve the method
     * shell so future revisions can layer additional Twig filters or
     * functions without re-introducing the dropped fallback.
     *
     * @return array{functions: array<string, callable>, filters: array<string, callable>}
     */
    public function registerMarkupTags(): array
    {
        return [
            'functions' => [
                'renderDeferredBlocks' => fn (): string => PixelHead::renderDeferredBlocks(),
            ],
            'filters' => [],
        ];
    }

    /**
     * Whether Lovata.OrdersShopaholic is installed + enabled. Resolves via the
     * container-bound PluginManager so tests can swap a fake via
     * $this->app->instance(PluginManager::class, $obFakePM) instead of relying
     * on the Mockery overload pattern (which requires runInSeparateProcess).
     */
    protected function isShopaholicEnabled(): bool
    {
        /** @var PluginManager $obPluginManager */
        $obPluginManager = App::make(PluginManager::class);

        return $obPluginManager->exists('Lovata.OrdersShopaholic');
    }

    /**
     * Wire the daily TTL purge of EventLog rows older than 7 days.
     * October fires console.schedule on each `php artisan schedule:run` and forwards
     * to every plugin's registerSchedule. Param is untyped to match
     * PluginBase::registerSchedule($schedule) signature (LSP variance); the concrete
     * Illuminate\Console\Scheduling\Schedule is documented via @param.
     *
     * @param  Schedule  $obSchedule
     */
    public function registerSchedule($obSchedule): void
    {
        $obSchedule->command('metapixel:purge-event-log')->daily();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => 'logingrupa.metapixel::lang.settings.label',
                'description' => 'logingrupa.metapixel::lang.settings.description',
                'category' => 'logingrupa.metapixel::lang.settings.category',
                'icon' => 'icon-bullseye',
                'class' => Settings::class,
                'order' => 500,
            ],
            // Pitfall 6 Option A — FailedEvents lives under the SettingsManager
            // parent via URL entry rather than registerNavigation; matches the
            // sibling Lovata convention and avoids backend-menu sprawl.
            'failed_events' => [
                'label' => 'logingrupa.metapixel::lang.menu.failed_events',
                'description' => 'logingrupa.metapixel::lang.menu.failed_events_description',
                'category' => 'logingrupa.metapixel::lang.settings.category',
                'icon' => 'icon-bell',
                'url' => Backend::url('logingrupa/metapixel/failedevents'),
                'order' => 510,
            ],
        ];
    }
}
