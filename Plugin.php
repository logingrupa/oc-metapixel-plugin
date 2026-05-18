<?php

namespace Logingrupa\Metapixel;

use Cms\Classes\Controller as CmsController;
use Cms\Classes\ThisVariable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher;
use Logingrupa\Metapixel\Console\PurgeEventLog;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\OrdersShopaholic\Models\Order;
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
        $this->registerConsoleCommand('metapixel:purge-event-log', PurgeEventLog::class);
    }

    public function boot(): void
    {
        if ($this->isShopaholicEnabled()) {
            $obRegistry = App::make(AdapterRegistry::class);
            $obRegistry->register(Order::class, ShopaholicOrderAdapter::class);
            $obRegistry->register(CartPosition::class, ShopaholicCartPositionAdapter::class);
            Event::subscribe(OrderStatusWatcher::class);
            Event::subscribe(CartPositionWatcher::class);
        }

        Event::listen('cms.page.beforeRenderPage', function (CmsController $obController): void {
            $mThis = $obController->vars['this'] ?? null;
            if (! $mThis instanceof ThisVariable) {
                return;
            }
            $mThis->config['metapixel'] = App::make(ThemeEventCollector::class);
        });
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
            'functions' => [],
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
     * Wire the daily TTL purge of EventLog rows older than 7 days (Phase 3 D-08).
     * October fires console.schedule on each `php artisan schedule:run` and forwards
     * to every plugin's registerSchedule. Param is untyped to match
     * PluginBase::registerSchedule($schedule) signature (LSP variance — RESEARCH
     * pitfall 7); the concrete Illuminate\Console\Scheduling\Schedule is documented
     * via @param.
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
        ];
    }
}
