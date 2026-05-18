<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

/**
 * Value object carrying a theme-fired event — actionKey + crc32-derived
 * synthetic id + event name + raw payload. Subject of ThemeActionAdapter.
 *
 * Note: no length cap on action_key — operators may use long descriptive
 * keys like `pdp:product-{slug}-{id}-{site}`. Validation kept minimal at
 * the fromArray boundary (non-empty string only).
 */
final class ThemeActionEvent
{
    /**
     * @param  array<string, mixed>  $arPayload
     */
    public function __construct(
        public readonly string $sActionKey,
        public readonly int $iSyntheticId,
        public readonly string $sEventName,
        public readonly array $arPayload,
    ) {}

    /**
     * Build a ThemeActionEvent from an operator-supplied push payload.
     *
     * @param  array<string, mixed>  $arData
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $arData): self
    {
        $mName = $arData['name'] ?? null;
        if (! is_string($mName) || $mName === '') {
            throw new \InvalidArgumentException('ThemeActionEvent.name is required (non-empty string)');
        }
        $mActionKey = $arData['action_key'] ?? null;
        if (! is_string($mActionKey) || $mActionKey === '') {
            throw new \InvalidArgumentException('ThemeActionEvent.action_key is required (non-empty string)');
        }
        $iSyntheticId = (int) sprintf('%u', crc32($mActionKey));

        return new self($mActionKey, $iSyntheticId, $mName, $arData);
    }
}
