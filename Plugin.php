<?php

namespace Logingrupa\Metapixel;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Models\Settings;
use System\Classes\PluginBase;

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
    }

    public function boot(): void {}

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
