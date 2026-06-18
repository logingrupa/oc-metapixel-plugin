<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use ArrayAccess;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Components\ProductPixel;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * VIEW-05 + VIEW-06 — ProductPixel renders window.__metapixelProduct global +
 * offer-switch JS (idempotency flag + soft-gate via window.__metapixelProduct
 * per Pitfall 8 / T-6-06). PluginGuard::isDisabled() yields null Twig vars;
 * empty collector yields null product global but still attaches JS.
 */
#[Group('adapter')]
final class ProductPixelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::singleton(ThemeEventCollector::class);
        PluginGuard::reset();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PX', 'capi_access_token' => 'TOK']);
        Settings::clearInternalCache();
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        PluginGuard::reset();
        parent::tearDown();
    }

    public function test_offer_switch_js_rendered_with_idempotency_flag_and_soft_gate(): void
    {
        $arPage = $this->runComponent(new ProductPixel);

        $mJs = $arPage['productPixelOfferSwitchJs'] ?? null;
        $this->assertIsString($mJs);
        $this->assertNotSame('', $mJs);
        $this->assertStringContainsString('window.__metapixelProductPixelInit', $mJs, 'idempotency flag missing');
        $this->assertStringContainsString('!window.__metapixelProduct', $mJs, 'soft-gate missing (Pitfall 8 / T-6-06)');
        $this->assertStringContainsString("subject_type: 'shopaholic.product'", $mJs);
        $this->assertStringContainsString("document.addEventListener('change'", $mJs);
        $this->assertStringContainsString("el.name !== 'offer_id'", $mJs, 'offer_id filter missing');
        $this->assertStringContainsString("\$.request('Metapixel::onFireEvent'", $mJs, 'must use October native $.request, not Larajax');
        $this->assertStringNotContainsString('jax.ajax', $mJs, 'Larajax jax.ajax is undefined in this theme — must not be emitted');
        $this->assertStringContainsString('createContextualFragment', $mJs, 'injected <script> must be executable (innerHTML scripts do not run)');
        $this->assertStringContainsString(
            "action_key: 'viewcontent:' + iProductId + ':' + iOfferId",
            $mJs,
            'two-segment wire-format action_key — server appends event_id',
        );
    }

    public function test_window_metapixel_product_global_emitted_when_collector_carries_product_id(): void
    {
        App::make(ThemeEventCollector::class)->push([
            'name' => 'ViewContent',
            'product_id' => 42,
        ]);

        $arPage = $this->runComponent(new ProductPixel);

        $mGlobal = $arPage['productPixelProductGlobalJs'] ?? null;
        $this->assertIsString($mGlobal);
        $this->assertStringContainsString(
            '<script>window.__metapixelProduct={id:42};</script>',
            $mGlobal,
        );
    }

    public function test_plugin_guard_disabled_yields_null_twig_vars(): void
    {
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '', 'capi_access_token' => '']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $arPage = $this->runComponent(new ProductPixel);

        $this->assertNull($arPage['productPixelOfferSwitchJs']);
        $this->assertNull($arPage['productPixelProductGlobalJs']);
    }

    public function test_collector_empty_yields_null_product_global(): void
    {
        // Collector empty (no push). Plugin enabled by setUp.
        $arPage = $this->runComponent(new ProductPixel);

        $this->assertNull(
            $arPage['productPixelProductGlobalJs'],
            'product-global Twig var MUST be null when collector has no product_id push',
        );
        $this->assertNotNull(
            $arPage['productPixelOfferSwitchJs'],
            'offer-switch JS attaches unconditionally; self-no-ops at runtime via soft-gate',
        );
    }

    /** @return array{productPixelProductGlobalJs: ?string, productPixelOfferSwitchJs: ?string} */
    private function runComponent(ProductPixel $obComponent): array
    {
        $obFakePage = new class implements ArrayAccess
        {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function offsetExists($offset): bool
            {
                return isset($this->vars[$offset]);
            }

            public function offsetGet($offset): mixed
            {
                return $this->vars[$offset] ?? null;
            }

            public function offsetSet($offset, $value): void
            {
                if ($offset === null) {
                    $this->vars[] = $value;

                    return;
                }
                $this->vars[$offset] = $value;
            }

            public function offsetUnset($offset): void
            {
                unset($this->vars[$offset]);
            }
        };

        $obReflection = new ReflectionProperty(ProductPixel::class, 'page');
        $obReflection->setAccessible(true);
        $obReflection->setValue($obComponent, $obFakePage);
        $obComponent->onRun();

        return [
            'productPixelProductGlobalJs' => is_string($obFakePage->vars['productPixelProductGlobalJs'] ?? null)
                ? $obFakePage->vars['productPixelProductGlobalJs']
                : null,
            'productPixelOfferSwitchJs' => is_string($obFakePage->vars['productPixelOfferSwitchJs'] ?? null)
                ? $obFakePage->vars['productPixelOfferSwitchJs']
                : null,
        ];
    }
}
