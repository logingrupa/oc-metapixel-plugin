<?php

namespace Logingrupa\Metapixel\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Model;
use October\Rain\Database\Model as ActiveRecord;
use October\Rain\Database\Pivot;
use October\Tests\Concerns\InteractsWithAuthentication;
use October\Tests\Concerns\PerformsMigrations;
use October\Tests\Concerns\PerformsRegistrations;

/**
 * Base PHPUnit 12 / Pest 4 test case for the Logingrupa.Metapixel plugin core.
 *
 * Boots OctoberCMS in SQLite-in-memory with no cart-plugin migrations. Cart-plugin
 * hermetic helpers live in subclasses (ShopaholicAdapterTestCase, future
 * MallAdapterTestCase, etc.) so Run B (minimal install) can execute
 * MetapixelTestCase-extending tests without Lovata cart packages installed.
 */
abstract class MetapixelTestCase extends TestCase
{
    use InteractsWithAuthentication;
    use PerformsMigrations;
    use PerformsRegistrations;

    /** @var bool */
    protected $autoMigrate = false;

    /** @var bool */
    protected $autoRegister = false;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Force the test DB configuration AFTER kernel bootstrap.
        // Laravel's dotenv loader overwrites the PHPUnit <env force> directives
        // during bootstrap, so we re-pin SQLite-in-memory here. Without this,
        // deferred plugin boot callbacks (e.g. Lovata.GoodNews ArticleModelHandler)
        // can hit the prod MySQL connection in the dev environment.
        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);

        $app['db']->purge();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;

            return AuthManager::instance();
        });

        $this->ensureSystemSettingsTable($app);

        return $app;
    }

    /**
     * Provision system_settings early so deferred plugin boot callbacks that
     * read SettingModel::get() do not crash on a missing table.
     */
    private function ensureSystemSettingsTable($app): void
    {
        $obSchema = $app['db']->connection()->getSchemaBuilder();
        if ($obSchema->hasTable('system_settings')) {
            return;
        }

        $obSchema->create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
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
        $this->dropHermeticSchemas();
        parent::tearDown();
        unset($this->app);
    }

    /**
     * Drop hermetic tables in tearDown. Subclasses override to drop their own
     * cart-plugin-specific tables (e.g. lovata_orders_shopaholic_*).
     */
    protected function dropHermeticSchemas(): void
    {
        Schema::dropIfExists('system_settings');
    }

    protected function flushModelEventListeners(): void
    {
        foreach (get_declared_classes() as $sClass) {
            if ($sClass === Pivot::class) {
                continue;
            }

            $obReflect = new \ReflectionClass($sClass);
            if (
                ! $obReflect->isInstantiable() ||
                ! $obReflect->isSubclassOf(Model::class) ||
                $obReflect->isSubclassOf(Pivot::class)
            ) {
                continue;
            }

            $sClass::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    protected function guessPluginCodeFromTest()
    {
        $obReflect = new \ReflectionClass($this);
        $sPath = $obReflect->getFilename();
        $sPluginPath = $this->app->pluginsPath();

        if (strpos($sPath, $sPluginPath) === 0) {
            $sResult = ltrim(str_replace('\\', '/', substr($sPath, strlen($sPluginPath))), '/');

            return implode('.', array_slice(explode('/', $sResult), 0, 2));
        }

        return false;
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }
}
