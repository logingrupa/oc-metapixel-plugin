<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

/**
 * Request-scoped accumulator for theme-side pushEvent calls. Plugin::register
 * binds as a singleton. PixelHead::onRun calls flush() to consume + reset.
 */
final class ThemeEventCollector
{
    /** @var list<array<string, mixed>> */
    private array $arEvents = [];

    /**
     * Append a theme-fired event when its name is a non-empty string.
     *
     * @param  array<string, mixed>  $arEvent
     */
    public function push(array $arEvent): void
    {
        $mNameRaw = $arEvent['name'] ?? null;
        if (! is_string($mNameRaw) || $mNameRaw === '') {
            return;
        }
        $this->arEvents[] = $arEvent;
    }

    /**
     * Twig-facing alias. Twig attribute resolver maps `{% do this.metapixel.pushEvent({...}) %}` to this method.
     *
     * @param  array<string, mixed>  $arEvent
     */
    public function pushEvent(array $arEvent): void
    {
        $this->push($arEvent);
    }

    /**
     * Return the accumulator + reset state. Idempotent on empty.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $arResult = $this->arEvents;
        $this->arEvents = [];

        return $arResult;
    }

    public function count(): int
    {
        return count($this->arEvents);
    }
}
