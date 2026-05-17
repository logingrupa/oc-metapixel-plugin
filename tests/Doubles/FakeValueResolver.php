<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * Constructor-defaults ValueResolver double. Pair with FakeAdapter for
 * round-trip tests.
 */
final class FakeValueResolver implements ValueResolver
{
    /**
     * @param  list<string>  $arContentIds
     * @param  list<array{id: string, quantity: int, item_price: float}>  $arContents
     */
    public function __construct(
        private array $arContentIds = ['SKU-1'],
        private float $fValue = 10.0,
        private string $sCurrency = 'EUR',
        private array $arContents = [['id' => 'SKU-1', 'quantity' => 1, 'item_price' => 10.0]],
        private int $iNumItems = 1,
    ) {}

    public function resolveContentIds(object $obSubject): array
    {
        return $this->arContentIds;
    }

    public function resolveValue(object $obSubject): float
    {
        return $this->fValue;
    }

    public function resolveCurrency(object $obSubject): string
    {
        return $this->sCurrency;
    }

    public function resolveContents(object $obSubject): array
    {
        return $this->arContents;
    }

    public function resolveNumItems(object $obSubject): int
    {
        return $this->iNumItems;
    }
}
