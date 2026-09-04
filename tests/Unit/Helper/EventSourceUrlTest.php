<?php

use Logingrupa\Metapixel\Classes\Helper\EventSourceUrl;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * EventSourceUrl builds the Meta event_source_url from $_SERVER-shaped input
 * and returns null for anything that is not a sane http(s) URL.
 */
final class EventSourceUrlTest extends MetapixelTestCase
{
    public function test_https_flag_builds_https_url_with_query(): void
    {
        $sUrl = EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop.example.com',
            'REQUEST_URI' => '/lv/product/gel?fbclid=IwAR1click',
            'HTTPS' => 'on',
        ]);

        $this->assertSame('https://shop.example.com/lv/product/gel?fbclid=IwAR1click', $sUrl);
    }

    public function test_forwarded_proto_wins_over_missing_https_flag(): void
    {
        $sUrl = EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop.example.com',
            'REQUEST_URI' => '/',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertSame('https://shop.example.com/', $sUrl);
    }

    public function test_plain_http_when_https_is_off(): void
    {
        $sUrl = EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop.test',
            'REQUEST_URI' => '/cart',
            'HTTPS' => 'off',
        ]);

        $this->assertSame('http://shop.test/cart', $sUrl);
    }

    public function test_missing_host_or_uri_yields_null(): void
    {
        $this->assertNull(EventSourceUrl::fromServer(['REQUEST_URI' => '/']));
        $this->assertNull(EventSourceUrl::fromServer(['HTTP_HOST' => 'shop.test']));
        $this->assertNull(EventSourceUrl::fromServer([]));
    }

    public function test_uri_not_starting_with_slash_yields_null(): void
    {
        $this->assertNull(EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop.test',
            'REQUEST_URI' => 'http://evil.test/',
        ]));
    }

    public function test_invalid_host_characters_yield_null(): void
    {
        $this->assertNull(EventSourceUrl::fromServer([
            'HTTP_HOST' => "shop.test\r\nX-Injected: 1",
            'REQUEST_URI' => '/',
        ]));
        $this->assertNull(EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop test',
            'REQUEST_URI' => '/',
        ]));
    }

    public function test_oversize_url_yields_null(): void
    {
        $this->assertNull(EventSourceUrl::fromServer([
            'HTTP_HOST' => 'shop.test',
            'REQUEST_URI' => '/?q='.str_repeat('a', 2100),
        ]));
    }

    public function test_non_string_server_values_are_ignored(): void
    {
        $this->assertNull(EventSourceUrl::fromServer([
            'HTTP_HOST' => ['shop.test'],
            'REQUEST_URI' => '/',
        ]));
    }
}
