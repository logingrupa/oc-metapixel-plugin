<?php

use Logingrupa\Metapixel\Classes\Helper\CrawlerUserAgent;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CrawlerUserAgent flags crawler and tooling user agents; real browsers and
 * absent user agents pass through.
 */
final class CrawlerUserAgentTest extends MetapixelTestCase
{
    /** @return array<string, array{string}> */
    public static function crawlerAgents(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'mj12bot' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)'],
            'dotbot' => ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot; help@moz.com)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'headless' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'],
            'curl' => ['curl/8.5.0'],
            'python' => ['python-requests/2.31'],
            'lighthouse' => ['Mozilla/5.0 (Macintosh) Chrome/120 Chrome-Lighthouse'],
            'geedo' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; GeedoShopProductFinder) Chrome/142.0.0.0 Safari/537.36'],
            'google read aloud' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)'],
            'wordpress' => ['WordPress/6.4.3; https://example.com'],
        ];
    }

    /** @return array<string, array{?string, bool}> */
    public static function browserVerdicts(): array
    {
        return [
            'chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', true],
            'bare mozilla' => ['Mozilla/5.0', true],
            'null' => [null, false],
            'empty' => ['', false],
            'erp client' => ['1C+Enterprise/8.3', false],
            'geedo' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; GeedoShopProductFinder) Chrome/142.0.0.0 Safari/537.36', false],
            'wordpress' => ['WordPress/6.4.3; https://example.com', false],
        ];
    }

    #[DataProvider('browserVerdicts')]
    public function test_is_browser_requires_mozilla_prefix_and_no_crawler_marker(?string $sUserAgent, bool $bExpected): void
    {
        $this->assertSame($bExpected, CrawlerUserAgent::isBrowser($sUserAgent));
    }

    /** @return array<string, array{?string}> */
    public static function humanAgents(): array
    {
        return [
            'chrome linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36'],
            'android chrome' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36'],
            'iphone safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'],
            'bare mozilla' => ['Mozilla/5.0'],
            'null' => [null],
            'empty' => [''],
        ];
    }

    #[DataProvider('crawlerAgents')]
    public function test_crawlers_are_detected(string $sUserAgent): void
    {
        $this->assertTrue(CrawlerUserAgent::isCrawler($sUserAgent));
    }

    #[DataProvider('humanAgents')]
    public function test_browsers_and_missing_agents_pass(?string $sUserAgent): void
    {
        $this->assertFalse(CrawlerUserAgent::isCrawler($sUserAgent));
    }
}
