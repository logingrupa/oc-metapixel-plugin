<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * EventSubjectAdapter for Lovata\OrdersShopaholic\Models\Order. Alias
 * 'shopaholic.order'. Supports Purchase on capi+pixel channels.
 */
final class ShopaholicOrderAdapter implements EventSubjectAdapter
{
    private const SUBJECT_TYPE = 'shopaholic.order';

    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = ['Purchase' => ['capi', 'pixel']];

    public function getSubjectType(object $obSubject): string
    {
        return self::SUBJECT_TYPE;
    }

    public function getSubjectId(object $obSubject): int
    {
        $mOrderId = $this->orderOf($obSubject)?->getAttribute('id');

        return is_numeric($mOrderId) ? (int) $mOrderId : 0;
    }

    public function getSiteId(object $obSubject): ?int
    {
        $mSiteId = $this->orderOf($obSubject)?->getAttribute('site_id');

        return is_numeric($mSiteId) ? (int) $mSiteId : null;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        $mKey = $this->orderOf($obSubject)?->getAttribute('secret_key');

        return is_string($mKey) ? $mKey : null;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return new ShopaholicOrderValueResolver;
    }

    /**
     * Raw Meta CAPI user_data — Order columns only. fbp/fbc/client_ip/UA stay
     * null (theme-side per D-15+D-16). external_id derives from Order.secret_key.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array
    {
        $obOrder = $this->orderOf($obSubject);

        return [
            'em' => $this->stringAttr($obOrder, 'email'),
            'ph' => $this->stringAttr($obOrder, 'phone'),
            'fn' => $this->stringAttr($obOrder, 'name'),
            'ln' => $this->stringAttr($obOrder, 'last_name'),
            'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
            'external_id' => $this->stringAttr($obOrder, 'secret_key'),
            'fbp' => null, 'fbc' => null,
            'client_ip_address' => null, 'client_user_agent' => null,
        ];
    }

    /** @return array<string, list<string>> */
    public function getSupportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    private function orderOf(object $obSubject): ?Order
    {
        return $obSubject instanceof Order ? $obSubject : null;
    }

    private function stringAttr(?Order $obOrder, string $sAttr): ?string
    {
        $mValue = $obOrder?->getAttribute($sAttr);

        return (is_string($mValue) && $mValue !== '') ? $mValue : null;
    }
}
