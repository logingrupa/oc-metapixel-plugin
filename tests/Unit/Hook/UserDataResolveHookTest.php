<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * metapixel.user_data.resolve: listeners supply raw identity once per
 * request, the hook hashes it, memoises it, merges it under adapter-supplied
 * values, a throwing listener abstains, and the event never fires for a
 * disabled plugin or a crawler request.
 */
final class UserDataResolveHookTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '1234567890']);
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        Event::forget(UserDataResolveHook::HOOK_RESOLVE);
        PluginGuard::reset();
        unset($_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    public function test_disabled_plugin_never_fires_the_event(): void
    {
        Settings::set(['pixel_id' => '']);
        PluginGuard::reset();
        Log::shouldReceive('warning')->once();
        $iCalls = 0;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData) use (&$iCalls): void {
            $iCalls++;
            $arUserData['em'] = 'anna@example.com';
        });

        $arHashed = $this->makeHook()->hashedIdentity('PageView', 'theme.action');

        $this->assertSame(0, $iCalls, 'no listener runs while the plugin is disabled');
        $this->assertSame([], $arHashed);
    }

    public function test_crawler_user_agent_never_fires_the_event(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        $iCalls = 0;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData) use (&$iCalls): void {
            $iCalls++;
            $arUserData['em'] = 'anna@example.com';
        });

        $arHashed = $this->makeHook()->hashedIdentity('PageView', 'theme.action');

        $this->assertSame(0, $iCalls, 'no listener runs for a crawler request');
        $this->assertSame([], $arHashed);
    }

    public function test_browser_user_agent_on_enabled_plugin_fires_the_event_once(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
        $iCalls = 0;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData) use (&$iCalls): void {
            $iCalls++;
            $arUserData['em'] = 'anna@example.com';
        });

        $arHashed = $this->makeHook()->hashedIdentity('PageView', 'theme.action');

        $this->assertSame(1, $iCalls);
        $this->assertSame(['em' => hash('sha256', 'anna@example.com')], $arHashed);
    }

    public function test_listener_supplied_identity_is_hashed_and_empty_keys_dropped(): void
    {
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData): void {
            $arUserData['em'] = 'Anna@Example.com';
            $arUserData['external_id'] = '42';
        });

        $arHashed = $this->makeHook()->hashedIdentity('ViewContent', 'shopaholic.product');

        $this->assertSame([
            'em' => hash('sha256', 'anna@example.com'),
            'external_id' => hash('sha256', '42'),
        ], $arHashed);
    }

    public function test_listener_receives_event_context(): void
    {
        $arSeenContext = null;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData, array $arContext) use (&$arSeenContext): void {
            $arSeenContext = $arContext;
        });

        $this->makeHook()->hashedIdentity('AddToCart', 'shopaholic.cart_position');

        $this->assertSame(['event_name' => 'AddToCart', 'subject_type' => 'shopaholic.cart_position'], $arSeenContext);
    }

    public function test_listeners_run_once_per_request_across_events(): void
    {
        $iCalls = 0;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData) use (&$iCalls): void {
            $iCalls++;
            $arUserData['em'] = 'anna@example.com';
        });
        $obHook = $this->makeHook();

        $arFirst = $obHook->hashedIdentity('PageView', 'theme.action');
        $arSecond = $obHook->hashedIdentity('ViewContent', 'shopaholic.product');

        $this->assertSame(1, $iCalls, 'identity resolves once per request, not once per event');
        $this->assertSame($arFirst, $arSecond);
    }

    public function test_reset_forgets_the_memo(): void
    {
        $iCalls = 0;
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData) use (&$iCalls): void {
            $iCalls++;
        });
        $obHook = $this->makeHook();

        $obHook->hashedIdentity('PageView', 'theme.action');
        $obHook->reset();
        $obHook->hashedIdentity('PageView', 'theme.action');

        $this->assertSame(2, $iCalls);
    }

    public function test_no_listener_yields_empty_identity(): void
    {
        $this->assertSame([], $this->makeHook()->hashedIdentity('PageView', 'theme.action'));
    }

    public function test_unknown_keys_and_non_string_values_are_ignored(): void
    {
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData): void {
            $arUserData['em'] = 'anna@example.com';
            $arUserData['ph'] = 37120000000;
            $arUserData['fbp'] = 'fb.1.forged';
            $arUserData['client_ip_address'] = '6.6.6.6';
        });

        $arHashed = $this->makeHook()->hashedIdentity('PageView', 'theme.action');

        $this->assertSame(['em' => hash('sha256', 'anna@example.com')], $arHashed);
    }

    public function test_throwing_listener_abstains_and_logs_warning(): void
    {
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData): void {
            throw new RuntimeException('identity boom');
        });
        Log::shouldReceive('warning')->once();

        $this->assertSame([], $this->makeHook()->hashedIdentity('PageView', 'theme.action'));
    }

    public function test_merge_into_payload_fills_empty_keys_and_keeps_adapter_values(): void
    {
        Event::listen(UserDataResolveHook::HOOK_RESOLVE, function (array &$arUserData): void {
            $arUserData['em'] = 'visitor@example.com';
            $arUserData['ph'] = '+371 20000000';
        });
        $arPayload = ['data' => [[
            'event_id' => 'e1',
            'event_time' => 1,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => ['em' => 'order-hash', 'ph' => null, 'fbp' => null],
            'custom_data' => [],
        ]]];

        $arMerged = $this->makeHook()->mergeIntoPayload('Purchase', 'shopaholic.order', $arPayload, ['fbp' => 'fb.1.1.a']);

        $arUserData = $arMerged['data'][0]['user_data'];
        $this->assertSame('order-hash', $arUserData['em'], 'adapter-supplied identity wins');
        $this->assertSame(hash('sha256', '37120000000'), $arUserData['ph'], 'empty adapter key filled from the hook');
        $this->assertSame('fb.1.1.a', $arUserData['fbp']);
    }

    public function test_empty_event_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeHook()->hashedIdentity('', 'theme.action');
    }

    private function makeHook(): UserDataResolveHook
    {
        return new UserDataResolveHook(new UserDataHasher);
    }
}
