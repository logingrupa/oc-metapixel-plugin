<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Event\AccountIdentityHandler;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Concerns\RegistersActiveUserPlugin;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use System\Classes\UpdateManager;

/**
 * Real Toolbox path: whichever user plugin the host runs is migrated into the
 * SQLite memory DB, a customer logs in through its auth facade and the hook
 * returns that account hashed. Skipped when no user plugin is installed.
 */
final class AccountIdentityHandlerUserPluginTest extends MetapixelTestCase
{
    use RegistersActiveUserPlugin;

    private string $sUserPluginName = '';

    protected function setUp(): void
    {
        parent::setUp();
        $sPluginName = $this->registerActiveUserPlugin();
        if ($sPluginName === null) {
            $this->markTestSkipped('No user plugin Toolbox supports is installed; the built-in account identity listener has nothing to read.');
        }
        $this->sUserPluginName = $sPluginName;

        $this->loadPlugins([$this->sUserPluginName]);
        // The base case pre-creates these two stubs; the module migrations own them here.
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('migrations');
        $this->migrateModules();
        UpdateManager::instance()->migratePlugin('Lovata.Toolbox');
        UpdateManager::instance()->migratePlugin($this->sUserPluginName);
        $sUserModelClass = UserHelper::instance()->getUserModel();
        $sUserTable = (new $sUserModelClass)->getTable();
        if (! Schema::hasColumn($sUserTable, 'phone')) {
            Schema::table($sUserTable, function ($obTable): void {
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
     * The first resolve of a request comes before anything touched the guard:
     * the user must be read from the session, not from the guard's memory.
     */
    public function test_session_user_is_loaded_when_the_guard_has_none_in_memory(): void
    {
        $obUser = $this->createLoggedInUser([
            'email' => 'anna@example.com',
            'first_name' => 'Anna',
            'name' => 'Anna',
            'last_name' => 'Berzina',
            'phone' => '26 111-222',
        ]);
        $this->forgetLoadedUser();
        Event::subscribe(AccountIdentityHandler::class);
        $obHook = App::make(UserDataResolveHook::class);
        $obHook->reset();

        $arHashed = $obHook->hashedIdentity('ViewContent', 'shopaholic.product');

        $this->assertSame(hash('sha256', 'anna@example.com'), $arHashed['em'] ?? null);
        $this->assertSame(hash('sha256', (string) $obUser->getKey()), $arHashed['external_id'] ?? null);
    }

    /**
     * Drop every in-memory copy of the logged-in user: the Laravel guards when
     * the auth manager keeps any, and a facade root that keeps itself as a
     * singleton plus its container binding.
     */
    private function forgetLoadedUser(): void
    {
        $obAuthManager = App::make('auth');
        if (method_exists($obAuthManager, 'forgetGuards')) {
            $obAuthManager->forgetGuards();
        }
        $sAuthFacade = UserHelper::instance()->getAuthFacade();
        $obRoot = $sAuthFacade::getFacadeRoot();
        if (! is_object($obRoot) || ! method_exists($obRoot, 'forgetInstance')) {
            return;
        }
        $obRoot::forgetInstance();
        $sAccessor = (new ReflectionMethod($sAuthFacade, 'getFacadeAccessor'))->invoke(null);
        App::forgetInstance($sAccessor);
        $sAuthFacade::clearResolvedInstance();
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
        // One save: a second one re-validates the purged password confirmation.
        $obUser->forceFill(['phone' => $arProfile['phone']])->save();
        if (method_exists($obUser, 'activate') && ! $obUser->getAttribute('is_activated')) {
            $obUser->activate();
        }

        $sAuthFacade = UserHelper::instance()->getAuthFacade();
        $sAuthFacade::login($obUser);

        return $obUser;
    }
}
