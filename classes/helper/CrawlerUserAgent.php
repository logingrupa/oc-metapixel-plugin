<?php

namespace Logingrupa\Metapixel\Classes\Helper;

/**
 * Detects crawler and tooling user agents. The browser pixel never runs for
 * them, so a server-side twin would be an unmatched junk event; the CAPI
 * pipeline skips such requests entirely.
 */
final class CrawlerUserAgent
{
    /** @var list<string> case-insensitive substrings; "bot" alone covers Googlebot, bingbot, DotBot, MJ12bot, AhrefsBot, GPTBot ... */
    private const MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'scrap',
        'facebookexternalhit', 'facebookcatalog',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptimerobot', 'site24x7',
        'python-requests', 'python-urllib', 'go-http-client', 'okhttp', 'libwww', 'httpclient', 'java/',
        'curl/', 'wget/',
    ];

    public static function isCrawler(?string $sUserAgent): bool
    {
        if ($sUserAgent === null || $sUserAgent === '') {
            return false;
        }

        $sLower = strtolower($sUserAgent);
        foreach (self::MARKERS as $sMarker) {
            if (str_contains($sLower, $sMarker)) {
                return true;
            }
        }

        return false;
    }
}
