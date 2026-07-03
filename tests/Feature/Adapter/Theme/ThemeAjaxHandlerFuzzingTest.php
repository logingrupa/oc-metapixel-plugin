<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Cms\Classes\Controller;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-05 P-09 fuzzing matrix — every malicious event_name shape must return
 * 422 with zero EventLog rows written. 14 named inputs cover XSS, SQLi, oversize,
 * encoding tricks, control chars, BOM, CRLF injection, RTL override.
 *
 * Class-based PHPUnit test + dataProvider (W4 lock — Pest closure-with form is
 * banned for adapter-group tests because group attribute lives on the class).
 */
#[Group('adapter')]
final class ThemeAjaxHandlerFuzzingTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::shouldReceive('input')->andReturnNull()->byDefault();
        $this->app->singleton(AdapterRegistry::class);
        App::make(AdapterRegistry::class)->register(
            ThemeActionEvent::class,
            ThemeActionAdapter::class,
        );
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
        ]);
        PluginGuard::reset();
        $this->app->forgetInstance(RateLimiter::class);
        Request::shouldReceive('ip')->andReturn('127.0.0.1');
        Session::shouldReceive('getId')->andReturn('fuzz-session');
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        $this->app->forgetInstance(RateLimiter::class);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousEventNamesProvider(): array
    {
        return [
            'xss_script_tag' => ['<script>alert(1)</script>'],
            'xss_event_handler' => ['onerror=alert(1)'],
            'xss_data_uri' => ['data:text/html,<script>alert(1)</script>'],
            'sqli_or_one' => ["Purchase' OR '1'='1"],
            'sqli_union' => ['Purchase UNION SELECT * FROM users'],
            'oversize_1kb' => [str_repeat('A', 1024)],
            'oversize_64kb' => [str_repeat('A', 65536)],
            'null_byte' => ["Purchase\0--"],
            'control_chars' => ["Purchase\x01\x02\x03"],
            'bom_prefix' => ["\xEF\xBB\xBFPurchase"],
            'mixed_encoding_utf16' => [(string) mb_convert_encoding('Purchase', 'UTF-16')],
            'unicode_normalisation' => ["Purcha\u{0301}se"],
            'cr_lf_injection' => ["Purchase\r\nX-Header: evil"],
            'rtl_override' => ["\u{202E}esahcruP"],
        ];
    }

    #[DataProvider('maliciousEventNamesProvider')]
    public function test_p09_fuzzing_malicious_event_name_returns_422_and_zero_eventlog_rows(string $sMaliciousName): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => $sMaliciousName,
            'action_key' => 'fuzz',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $this->assertSame(0, DB::table('logingrupa_metapixel_event_log')->count());
    }
}
