<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * Assembles the Meta Conversions API event envelope. Subject-agnostic and
 * event-name-agnostic — the adapter, the resolver, and the per-call
 * \$arEventExtras carry everything that varies between events. The builder
 * itself has zero comparisons against the event name; future events
 * (AddToCart, Lead, ViewContent) ship by authoring a new adapter, not by
 * editing this class.
 */
final class PayloadBuilder
{
    private const ACTION_SOURCE = 'website';

    public function __construct(private readonly UserDataHasher $obHasher) {}

    /**
     * @param  array<string, mixed>  $arEventExtras
     * @return array<string, mixed>
     */
    public function buildEventPayload(
        string $sEventName,
        EventSubjectAdapter $obAdapter,
        object $obSubject,
        ValueResolver $obResolver,
        string $sEventId,
        int $iEventTime,
        array $arEventExtras,
    ): array {
        $arUserData = $this->obHasher->forSubject($obAdapter, $obSubject);
        $arContentIds = $obResolver->resolveContentIds($obSubject);

        $arCustomData = [
            'currency' => $obResolver->resolveCurrency($obSubject),
            'value' => $obResolver->resolveValue($obSubject),
            'num_items' => $obResolver->resolveNumItems($obSubject),
            'contents' => $obResolver->resolveContents($obSubject),
        ];

        if ($arContentIds !== []) {
            $arCustomData['content_ids'] = $arContentIds;
            $arCustomData['content_type'] = 'product';
        } else {
            // Contentless subject (PageView / any zero-value event): drop the
            // junk zero-value keys Meta flags in the Test Events panel. currency
            // is meaningless to Meta without a value, so it drops with value.
            if ($arCustomData['contents'] === []) {
                unset($arCustomData['contents']);
            }
            if ($arCustomData['num_items'] === 0) {
                unset($arCustomData['num_items']);
            }
            if ($arCustomData['value'] === 0.0) {
                unset($arCustomData['value']);
                unset($arCustomData['currency']);
            }
        }

        if ($arEventExtras !== []) {
            $arCustomData = array_merge($arCustomData, $arEventExtras);
        }

        return ['data' => [[
            'event_id' => $sEventId,
            'event_time' => $iEventTime,
            'event_name' => $sEventName,
            'action_source' => self::ACTION_SOURCE,
            'user_data' => $arUserData,
            'custom_data' => $arCustomData,
        ]]];
    }
}
