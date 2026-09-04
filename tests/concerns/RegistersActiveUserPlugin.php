<?php

namespace Logingrupa\Metapixel\Tests\Concerns;

use Illuminate\Support\Facades\Facade;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use System\Classes\PluginManager;

/**
 * MetapixelTestCase pins the backend auth manager on "auth" and never runs
 * plugin register(); registering the user plugin puts its config namespace,
 * frontend auth manager and container bindings back so UserHelper::getUser() resolves.
 */
trait RegistersActiveUserPlugin
{
    /**
     * Register the Toolbox-detected user plugin and return its name.
     *
     * @return string|null Null when no supported user plugin is installed.
     */
    protected function registerActiveUserPlugin(): ?string
    {
        UserHelper::forgetInstance();
        $sPluginName = UserHelper::instance()->getPluginName();
        if ($sPluginName === null || $sPluginName === '') {
            return null;
        }

        $obPluginManager = PluginManager::instance();
        $obPluginManager->registerPlugin($obPluginManager->findByIdentifier($sPluginName), $sPluginName);
        Facade::clearResolvedInstances();

        return $sPluginName;
    }
}
