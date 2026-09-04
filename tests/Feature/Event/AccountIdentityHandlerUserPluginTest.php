<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Event\AccountIdentityHandler;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use System\Classes\PluginManager;
use System\Classes\UpdateManager;

/**
 * Real Toolbox path: whichever user plugin the host runs is migrated into the
 * SQLite memory DB, a customer logs in through its auth facade and the hook
 * returns that account hashed. Skipped when no user plugin is installed.
 */
final class AccountIdentityHandlerUserPluginTest extends MetapixelTestCase
{
    private string $sUserPluginName = '';

    protected function setUp(): void
    {
        parent::setUp();
        UserHelper::forgetInstance();
        $sPluginName = UserHelper::instance()->getPluginName();
        if ($sPluginName === null || $sPluginName === '') {
            $this->markTestSkipped('No user plugin Toolbox supports is installed; the built-in account identity listener has nothing to read.');
        }
        $this->sUserPluginName = $sPluginName;

        // MetapixelTestCase pins the backend auth manager on "auth"; the user
        // plugin's register() puts its own frontend auth manager back.
        PluginManager::instance()->findByIdentifier($this->sUserPluginName)->register();
        Facade::clearResolvedInstances();

        $this->loadPlugins([$this->sUserPluginName]);
        // The base case pre-creates these two stubs; the module migrations own them here.
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('migrations');
        $this->migrateModules();
        UpdateManager::instance()->migratePlugin('Lovata.Toolbox');
        UpdateManager::instance()->migratePlugin($this->sUserPluginName);
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function ($obTable): void {
                $obTable->string('phone')->nullable();
            });
        }

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => '1234567890',
            'account_identity_enabled' => true,
            'account_phone_dial_code' => '371',
        ]);
        PluginGuard::reset();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
    }

    protected function tearDown(): void
    {
        if ($this->sUserPluginName !== '') {
            $sAuthFacade = UserHelper::instance()->getAuthFacade();
            $sAuthFacade::logout();
        }
        Event::forget(UserDataResolveHook::HOOK_RESOLVE);
        PluginGuard::reset();
        UserHelper::forgetInstance();
        unset($_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    public function test_logged_in_account_is_hashed_into_the_request_identity(): void
    {
        $obUser = $this->createLoggedInUser([
            'email' => 'anna@example.com',
            'first_name' => 'Anna',
            'name' => 'Anna',
            'last_name' => 'Berzina',
            'phone' => '26 111-222',
        ]);
        Event::subscribe(AccountIdentityHandler::class);
        $obHook = App::make(UserDataResolveHook::class);
        $obHook->reset();

        $arHashed = $obHook->hashedIdentity('ViewContent', 'shopaholic.product');

        $this->assertSame([
            'em' => hash('sha256', 'anna@example.com'),
            'ph' => hash('sha256', '37126111222'),
            'fn' => hash('sha256', 'anna'),
            'ln' => hash('sha256', 'berzina'),
            'external_id' => hash('sha256', (string) $obUser->getKey()),
        ], $arHashed);
    }

    /**
     * @param  array<string, string>  $arProfile
     */
    private function createLoggedInUser(array $arProfile): Model
    {
        $sUserModel = UserHelper::instance()->getUserModel();
        $obUser = new $sUserModel;
        $arPassword = ['password' => 'Probe12345', 'password_confirmation' => 'Probe12345'];
        $arAllowed = array_merge($obUser->getFillable(), array_keys($arPassword));
        $obUser->fill(array_intersect_key(array_merge($arProfile, $arPassword), array_flip($arAllowed)));
        $obUser->save();
        $obUser->forceFill(['phone' => $arProfile['phone']])->save();
        if (method_exists($obUser, 'activate') && ! $obUser->getAttribute('is_activated')) {
            $obUser->activate();
        }

        $sAuthFacade = UserHelper::instance()->getAuthFacade();
        $sAuthFacade::login($obUser);

        return $obUser;
    }
}
