<?php

namespace Logingrupa\Metapixel\Tests\Unit\Meta;

use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Meta\PixelRenderHook;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use RuntimeException;

/**
 * Unit coverage for PixelRenderHook — the request-time browser-pixel
 * mutation seam. Asserts by-reference listener mutation, the contentless
 * skip, and the throwing-listener abstain boundary.
 */
final class PixelRenderHookTest extends MetapixelTestCase
{
    protected function tearDown(): void
    {
        Event::forget(PixelRenderHook::HOOK_BEFORE_RENDER);
        parent::tearDown();
    }

    public function test_listener_mutation_reaches_the_returned_custom_data(): void
    {
        Event::listen(PixelRenderHook::HOOK_BEFORE_RENDER, function (string $sEventName, array &$arCustomData): void {
            $arCustomData['value'] = 1.79;
        });

        $arResult = PixelRenderHook::apply('ViewContent', ['value' => 6.98, 'currency' => 'EUR']);

        $this->assertSame(1.79, $arResult['value']);
        $this->assertSame('EUR', $arResult['currency']);
    }

    public function test_contentless_block_skips_the_hook(): void
    {
        $bFired = false;
        Event::listen(PixelRenderHook::HOOK_BEFORE_RENDER, function () use (&$bFired): void {
            $bFired = true;
        });

        $this->assertSame([], PixelRenderHook::apply('PageView', []));
        $this->assertFalse($bFired, 'hook MUST NOT fire for contentless blocks');
    }

    public function test_throwing_listener_abstains_and_keeps_custom_data(): void
    {
        Event::listen(PixelRenderHook::HOOK_BEFORE_RENDER, function (): void {
            throw new RuntimeException('boom');
        });

        $arResult = PixelRenderHook::apply('ViewContent', ['value' => 6.98]);

        $this->assertSame(['value' => 6.98], $arResult);
    }
}
