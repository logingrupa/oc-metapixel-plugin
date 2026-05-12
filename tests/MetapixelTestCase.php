<?php

namespace Logingrupa\Metapixelshopaholic\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Foundation\Testing\TestCase;
use October\Rain\Database\Model as ActiveRecord;

/**
 * PHPUnit 12 / Pest 4 compatible test case for October CMS plugins.
 *
 * October's PluginTestCase declares setUp() as public, which conflicts
 * with PHPUnit 12's protected setUp(). This class reimplements the same
 * bootstrap logic with correct visibility.
 */
abstract class MetapixelTestCase extends TestCase
{
    use \October\Tests\Concerns\InteractsWithAuthentication;
    use \October\Tests\Concerns\PerformsMigrations;
    use \October\Tests\Concerns\PerformsRegistrations;

    protected $autoMigrate = true;
    protected $autoRegister = true;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;
            return AuthManager::instance();
        });

        return $app;
    }

    protected function setUp(): void
    {
        $this->pluginTestCaseMigratedPlugins = [];
        $this->pluginTestCaseLoadedPlugins = [];

        parent::setUp();

        if ($this->autoRegister === true) {
            $this->loadCurrentPlugin();
        }

        if ($this->autoMigrate === true) {
            $this->migrateModules();
            $this->migrateCurrentPlugin();
        }

        \Mail::pretend();
    }

    protected function tearDown(): void
    {
        $this->flushModelEventListeners();
        parent::tearDown();
        unset($this->app);
    }

    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if ($class == \October\Rain\Database\Pivot::class) {
                continue;
            }

            $reflectClass = new \ReflectionClass($class);
            if (
                !$reflectClass->isInstantiable() ||
                !$reflectClass->isSubclassOf(\October\Rain\Database\Model::class) ||
                $reflectClass->isSubclassOf(\October\Rain\Database\Pivot::class)
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    protected function guessPluginCodeFromTest()
    {
        $reflect = new \ReflectionClass($this);
        $path = $reflect->getFilename();
        $pluginPath = $this->app->pluginsPath();

        if (strpos($path, $pluginPath) === 0) {
            $result = ltrim(str_replace('\\', '/', substr($path, strlen($pluginPath))), '/');
            $result = implode('.', array_slice(explode('/', $result), 0, 2));
            return $result;
        }

        return false;
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }
}
