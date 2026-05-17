<?php

namespace Logingrupa\Metapixel;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
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
}
