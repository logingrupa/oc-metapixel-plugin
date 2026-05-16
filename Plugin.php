<?php

namespace Logingrupa\Metapixel;

use System\Classes\PluginBase;

/**
 * Meta Pixel + Conversions API tracking plugin — v2.0 scaffold.
 *
 * Phase 1 ships an empty boot/register. Adapter registry, event hooks,
 * and Settings registration land in subsequent phases.
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

    public function register(): void {}

    public function boot(): void {}
}
