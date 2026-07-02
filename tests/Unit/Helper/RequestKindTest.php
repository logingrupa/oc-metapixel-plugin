<?php

namespace Logingrupa\Metapixel\Tests\Unit\Helper;

use Logingrupa\Metapixel\Classes\Helper\RequestKind;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * RequestKind::isPageRender — the single owner of the "is this request a
 * page render?" predicate that gates PageView/ViewContent CAPI dispatch.
 */
final class RequestKindTest extends MetapixelTestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_X_OCTOBER_REQUEST_HANDLER'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['REQUEST_METHOD'],
        );
        parent::tearDown();
    }

    public function test_plain_get_is_a_page_render(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue(RequestKind::isPageRender());
    }

    public function test_cli_context_without_request_method_counts_as_page_render(): void
    {
        unset($_SERVER['REQUEST_METHOD']);

        $this->assertTrue(RequestKind::isPageRender());
    }

    public function test_october_ajax_handler_header_is_not_a_page_render(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_OCTOBER_REQUEST_HANDLER'] = 'Cart::onAdd';

        $this->assertFalse(RequestKind::isPageRender());
    }

    public function test_xml_http_request_is_not_a_page_render(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $this->assertFalse(RequestKind::isPageRender());
    }

    public function test_xml_http_request_get_is_not_a_page_render(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $this->assertFalse(RequestKind::isPageRender());
    }

    public function test_non_get_method_is_not_a_page_render(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->assertFalse(RequestKind::isPageRender());
    }
}
