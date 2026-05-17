<?php

namespace Logingrupa\Metapixel\Classes\Adapter;

/**
 * Per-event value computation surface. Each EventSubjectAdapter returns one
 * of these from getValueResolver(). PayloadBuilder calls these five methods
 * to fill the Graph API custom_data envelope. Subject-agnostic at the
 * interface level — the concrete resolver decides what 'value' means for
 * its subject + event combo.
 */
interface ValueResolver
{
    /**
     * Content ids for the event (typically SKU strings).
     *
     * @return list<string>
     */
    public function resolveContentIds(object $obSubject): array;

    /**
     * Monetary value of the event in the resolver's currency.
     */
    public function resolveValue(object $obSubject): float;

    /**
     * ISO-4217 currency code (EUR, USD, NOK, …).
     */
    public function resolveCurrency(object $obSubject): string;

    /**
     * Line-item details per Meta CAPI spec.
     *
     * @return list<array{id: string, quantity: int, item_price: float}>
     */
    public function resolveContents(object $obSubject): array;

    /**
     * Total number of items in the event.
     */
    public function resolveNumItems(object $obSubject): int;
}
