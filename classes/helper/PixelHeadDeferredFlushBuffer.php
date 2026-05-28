<?php

namespace Logingrupa\Metapixel\Classes\Helper;

/**
 * Request-scoped buffer for PixelHead deferred-flush script blocks. Decouples
 * the cms.page.beforeRenderPage listener (writes via setBlocks) from the
 * components/pixelhead/default.htm Twig render (reads via
 * PixelHead::renderDeferredBlocks markup helper). RESEARCH Pitfall 1 anchor.
 */
final class PixelHeadDeferredFlushBuffer
{
    /** @var list<string> */
    private array $arBlocks = [];

    /**
     * Replace the buffer contents with the given script blocks (string-only).
     *
     * @param  list<string>  $arBlocks
     */
    public function setBlocks(array $arBlocks): void
    {
        $this->arBlocks = array_values(array_filter($arBlocks, 'is_string'));
    }

    /**
     * Return the buffered script blocks in insertion order.
     *
     * @return list<string>
     */
    public function getBlocks(): array
    {
        return $this->arBlocks;
    }

    /**
     * Reset the buffer; test isolation hook.
     */
    public function clear(): void
    {
        $this->arBlocks = [];
    }
}
