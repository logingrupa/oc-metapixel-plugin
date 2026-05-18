<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Lovata\OrdersShopaholic\Models\CartPosition;

/**
 * EventSubjectAdapter for Lovata\OrdersShopaholic\Models\CartPosition. Alias
 * 'shopaholic.cart_position'. Supports AddToCart on capi+pixel channels.
 * site_id is a 1-hop relation traversal through the parent Cart row.
 */
final class ShopaholicCartPositionAdapter implements EventSubjectAdapter
{
    private const SUBJECT_TYPE = 'shopaholic.cart_position';

    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = ['AddToCart' => ['capi', 'pixel']];

    public function getSubjectType(object $obSubject): string
    {
        return self::SUBJECT_TYPE;
    }

    public function getSubjectId(object $obSubject): int
    {
        $mPositionId = $this->positionOf($obSubject)?->getAttribute('id');

        return is_numeric($mPositionId) ? (int) $mPositionId : 0;
    }

    public function getSiteId(object $obSubject): ?int
    {
        $mCart = $this->positionOf($obSubject)?->getRelationValue('cart');
        $mSiteId = is_object($mCart) ? ($mCart->site_id ?? null) : null;

        return is_numeric($mSiteId) ? (int) $mSiteId : null;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        return null;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return new ShopaholicCartPositionValueResolver;
    }

    /**
     * Anonymous cart subjects carry no PII columns. All 13 Meta CAPI keys stay
     * null — theme-side cookies (fbp/fbc/client_ip/user_agent) populate via the
     * EventPixel render path; Phase 4 cookie middleware sets them at the
     * request boundary. UserDataHasher honors null + omits the field hash.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array
    {
        return [
            'em' => null, 'ph' => null, 'fn' => null, 'ln' => null,
            'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
            'external_id' => null, 'fbp' => null, 'fbc' => null,
            'client_ip_address' => null, 'client_user_agent' => null,
        ];
    }

    /** @return array<string, list<string>> */
    public function getSupportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    private function positionOf(object $obSubject): ?CartPosition
    {
        return $obSubject instanceof CartPosition ? $obSubject : null;
    }
}
