<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-06. Asserts VIEW-05 + VIEW-06:
 * ProductPixel component renders window.__metapixelProduct global +
 * offer-switch JS (idempotency flag + soft-gate via window.__metapixelProduct),
 * PluginGuard::isDisabled() yields null Twig vars, empty collector yields
 * null product global.
 */
#[Group('adapter')]
final class ProductPixelTest extends MetapixelTestCase
{
    public function test_offer_switch_js_rendered_with_idempotency_flag_and_soft_gate(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 1 — Logingrupa\Metapixel\Components\ProductPixel default.htm partial MUST emit window.__metapixelProductPixelInit idempotency flag + window.__metapixelProduct soft-gate (Pitfall 8 cart-modal bonus-box guard)');
    }

    public function test_window_metapixel_product_global_emitted_when_collector_carries_product_id(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 1 — ProductPixel component MUST emit window.__metapixelProduct={id:N} when ThemeEventCollector::peek() returns a viewcontent entry');
    }

    public function test_plugin_guard_disabled_yields_null_twig_vars(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 1 — ProductPixel::onRun MUST set $this->page[productPixelGlobal] + $this->page[productPixelJs] to null when PluginGuard::isDisabled() returns true');
    }

    public function test_collector_empty_yields_null_product_global(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 1 — ProductPixel::onRun MUST set window.__metapixelProduct Twig var to null when ThemeEventCollector has no viewcontent entry');
    }
}
