<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Lovata\OrdersShopaholic\Models\CartPosition;
use October\Rain\Support\Facades\Site;

/**
 * EventSubjectAdapter for Lovata\OrdersShopaholic\Models\CartPosition. Alias
 * 'shopaholic.cart_position'. Supports AddToCart on capi+pixel channels.
 *
 * site_id source: prefers $obPosition->cart->site_id (1-hop relation) when
 * non-null; falls back to October's Site::getSiteIdFromContext() as the SECOND
 * documented P-01 exception (alongside ThemeActionAdapter). Lovata Cart has no
 * site_id column natively (verified via grep on lovata_orders_shopaholic_carts
 * migrations); without this fallback, MySQL UNIQUE index dedup is broken
 * (NULL != NULL semantics).
 *
 * CAVEAT — the fallback is NOT request-only: getSiteId also executes inside
 * the queue worker (SendCapiEvent::handle → SiteResolver::forSubject), where
 * Site::getSiteIdFromContext() resolves the CLI-context default site. On
 * single-site installs (the supported deployment shape: one site per server)
 * request and worker contexts resolve identically, so behavior is correct.
 * On a multisite single-DB install whose carts carry no site_id, worker-side
 * sends resolve the primary site's pixel credentials; correcting that
 * requires a subject that carries a request-time-baked site_id (mirror
 * ProductPageWatcher::makeDispatchEvent). The in-request browser-pixel
 * reservation written by CartPositionWatcher::dispatchAddToCart uses
 * request-context resolution, so the qty-bump dedup query compares
 * like-for-like. phpstan disallowIn excludes this file from the
 * Site/SiteManager/Request ban (second documented exception).
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
        if (is_numeric($mSiteId)) {
            return (int) $mSiteId;
        }

        $mContextSiteId = Site::getSiteIdFromContext();

        return is_int($mContextSiteId) && $mContextSiteId > 0 ? $mContextSiteId : null;
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
     * EventPixel render path; the cookie middleware sets them at the
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
