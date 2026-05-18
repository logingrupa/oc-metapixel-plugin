<?php

namespace Logingrupa\Metapixel\Tests\Fixtures\Components;

use Logingrupa\Metapixel\Components\PixelHead;
use RuntimeException;

/**
 * PixelHead subclass whose dispatchCapiMirror always throws — used by
 * PixelHeadTest::test_onRun_swallows_mirror_exception_does_not_break_page_render
 * to prove the Tiger-Style catch in onRun keeps page render alive even when
 * the mirror path fails (W-NEW-3 lock iteration 2 path (c); I-NEW-1
 * iteration 3 signature lock — parent method protected, LSP-correct).
 */
final class PixelHeadExceptionFixture extends PixelHead
{
    /**
     * @param  array<string, mixed>  $arEvent
     */
    protected function dispatchCapiMirror(string $sName, array $arEvent): void
    {
        throw new RuntimeException('forced for test');
    }
}
