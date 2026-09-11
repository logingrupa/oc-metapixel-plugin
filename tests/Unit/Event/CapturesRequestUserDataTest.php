<?php

namespace Logingrupa\Metapixel\Tests\Unit\Event;

use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Request identity (IP, user agent, _fbp, _fbc) is attached only when the
 * running request is the customer's browser. Backend admins, the 1C
 * exchange and gateway webhooks describe the caller, not the buyer.
 */
final class CapturesRequestUserDataTest extends MetapixelTestCase
{
    private object $obCapture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->obCapture = new class
        {
            use CapturesRequestUserData;

            /** @return array<string, ?string> */
            public function capture(): array
            {
                return $this->collectRequestUserData();
            }
        };
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_COOKIE['_fbp'] = 'fb.1.123.456';
        $_COOKIE['_fbc'] = 'fb.1.123.IwAR1validfbclid_123';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'], $_COOKIE['_fbp'], $_COOKIE['_fbc']);
        $this->app->forgetInstance('execution.context');
        parent::tearDown();
    }

    public function test_browser_request_on_the_frontend_is_captured(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/128';
        $this->app->instance('execution.context', 'frontend');

        $arUserData = $this->obCapture->capture();

        $this->assertSame('203.0.113.1', $arUserData['client_ip_address']);
        $this->assertSame('Mozilla/5.0 (Windows NT 10.0) Chrome/128', $arUserData['client_user_agent']);
        $this->assertSame('fb.1.123.456', $arUserData['fbp']);
        $this->assertSame('fb.1.123.IwAR1validfbclid_123', $arUserData['fbc']);
    }

    public function test_non_browser_user_agent_is_not_captured(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = '1C+Enterprise/8.3';
        $this->app->instance('execution.context', 'frontend');

        $this->assertSame($this->allNull(), $this->obCapture->capture());
    }

    public function test_missing_user_agent_is_not_captured(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $this->app->instance('execution.context', 'frontend');

        $this->assertSame($this->allNull(), $this->obCapture->capture());
    }

    public function test_backend_request_is_not_captured_even_from_a_browser(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/128';
        $this->app->instance('execution.context', 'backend');

        $this->assertSame($this->allNull(), $this->obCapture->capture());
    }

    /** @return array<string, null> */
    private function allNull(): array
    {
        return ['client_ip_address' => null, 'client_user_agent' => null, 'fbp' => null, 'fbc' => null];
    }
}
