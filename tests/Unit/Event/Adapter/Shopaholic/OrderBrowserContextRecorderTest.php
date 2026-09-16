<?php

namespace Logingrupa\Metapixel\Tests\Unit\Event\Adapter\Shopaholic;

use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderBrowserContextRecorder;
use Logingrupa\Metapixel\Models\OrderBrowserContext;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelOrderBrowserContextsTable;
use Lovata\OrdersShopaholic\Models\Order;
use PHPUnit\Framework\Attributes\Group;

/**
 * The buyer's browser identity is stored once per order at creation, only
 * when the creating request is the buyer's browser.
 */
#[Group('adapter')]
final class OrderBrowserContextRecorderTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelOrderBrowserContextsTable)->up();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_SERVER['HTTP_HOST'] = 'shop.test';
        $_SERVER['REQUEST_URI'] = '/checkout';
        $_COOKIE['_fbp'] = 'fb.1.123.456';
        $_COOKIE['_fbc'] = 'fb.1.123.IwAR1validfbclid_123';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_COOKIE['_fbp'], $_COOKIE['_fbc']);
        $this->app->forgetInstance('execution.context');
        (new CreateMetapixelOrderBrowserContextsTable)->down();
        parent::tearDown();
    }

    public function test_browser_checkout_request_is_stored_for_the_order(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/128';
        $this->app->instance('execution.context', 'frontend');

        (new OrderBrowserContextRecorder)->handle($this->makeOrder(7));

        $obContext = OrderBrowserContext::findForOrder(7);
        $this->assertNotNull($obContext);
        $this->assertSame('203.0.113.1', $obContext->client_ip_address);
        $this->assertSame('Mozilla/5.0 (Windows NT 10.0) Chrome/128', $obContext->client_user_agent);
        $this->assertSame('fb.1.123.456', $obContext->fbp);
        $this->assertSame('fb.1.123.IwAR1validfbclid_123', $obContext->fbc);
        $this->assertSame('http://shop.test/checkout', $obContext->event_source_url);
    }

    public function test_non_browser_request_stores_nothing(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = '1C+Enterprise/8.3';
        $this->app->instance('execution.context', 'frontend');

        (new OrderBrowserContextRecorder)->handle($this->makeOrder(7));

        $this->assertNull(OrderBrowserContext::findForOrder(7));
    }

    public function test_backend_request_stores_nothing(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/128';
        $this->app->instance('execution.context', 'backend');

        (new OrderBrowserContextRecorder)->handle($this->makeOrder(7));

        $this->assertNull(OrderBrowserContext::findForOrder(7));
    }

    public function test_write_failure_logs_a_warning_and_does_not_throw(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/128';
        $this->app->instance('execution.context', 'frontend');
        Log::spy();

        (new OrderBrowserContextRecorder)->handle($this->makeOrder(7));
        (new OrderBrowserContextRecorder)->handle($this->makeOrder(7));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $sMessage): bool => str_contains($sMessage, 'OrderBrowserContextRecorder write failed'))
            ->once();
        $this->assertSame(1, OrderBrowserContext::query()->count());
    }

    private function makeOrder(int $iOrderId): Order
    {
        $obOrder = new Order;
        $obOrder->setAttribute('id', $iOrderId);

        return $obOrder;
    }
}
