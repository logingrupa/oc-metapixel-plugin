<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use October\Rain\Support\Facades\Site;

/**
 * EventSubjectAdapter for ThemeActionEvent.
 *
 * D-15 / D-16 — this adapter is the ONE documented P-01 exception. getSiteId
 * reads arPayload['site_id'] first; if missing or non-int, falls back to
 * Site::getSiteIdFromContext() (the active SiteManager context id). Theme
 * events fire in-request by definition; forcing operator boilerplate
 * `site_id: this.site.id` on every Twig push yields no real safety gain.
 * phpstan.neon excludes classes/adapter/theme/* from the SiteManager/Site
 * /Request disallow list (D-16).
 *
 * Alias 'theme.action'. Supports every Meta-standard event name through Twig +
 * Larajax push paths; channel set declared at getSupportedEvents call.
 */
final class ThemeActionAdapter implements EventSubjectAdapter
{
    private const SUBJECT_TYPE = 'theme.action';

    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = [
        'PageView' => ['capi', 'pixel'],
        'ViewContent' => ['capi', 'pixel'],
        'AddToCart' => ['capi', 'pixel'],
        'AddToWishlist' => ['capi', 'pixel'],
        'InitiateCheckout' => ['capi', 'pixel'],
        'Purchase' => ['capi', 'pixel'],
        'Lead' => ['capi', 'pixel'],
        'CompleteRegistration' => ['capi', 'pixel'],
        'Search' => ['capi', 'pixel'],
        'Subscribe' => ['capi', 'pixel'],
        'Contact' => ['capi', 'pixel'],
        'FindLocation' => ['capi', 'pixel'],
        'Donate' => ['capi', 'pixel'],
        'CustomizeProduct' => ['capi', 'pixel'],
        'SubmitApplication' => ['capi', 'pixel'],
        'AddPaymentInfo' => ['capi', 'pixel'],
        'StartTrial' => ['capi', 'pixel'],
        'Schedule' => ['capi', 'pixel'],
    ];

    public function getSubjectType(object $obSubject): string
    {
        return self::SUBJECT_TYPE;
    }

    public function getSubjectId(object $obSubject): int
    {
        if ($obSubject instanceof ThemeActionEvent) {
            return $obSubject->iSyntheticId;
        }

        return 0;
    }

    public function getSiteId(object $obSubject): ?int
    {
        if ($obSubject instanceof ThemeActionEvent) {
            $mPayloadSiteId = $obSubject->arPayload['site_id'] ?? null;
            if (is_int($mPayloadSiteId) && $mPayloadSiteId > 0) {
                return $mPayloadSiteId;
            }
            if (is_numeric($mPayloadSiteId)) {
                $iSiteId = (int) $mPayloadSiteId;

                return $iSiteId > 0 ? $iSiteId : null;
            }
        }
        $mContextSiteId = Site::getSiteIdFromContext();

        return is_int($mContextSiteId) && $mContextSiteId > 0 ? $mContextSiteId : null;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        if (! $obSubject instanceof ThemeActionEvent) {
            return null;
        }
        $mKey = $obSubject->arPayload['secret_key'] ?? null;

        return is_string($mKey) ? $mKey : null;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return new ThemeActionValueResolver;
    }

    /**
     * Raw Meta CAPI user_data — 13 keys, runtime-string-guarded from arPayload.
     * Missing keys explicitly null per contract invariant 07.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array
    {
        $arPayload = $obSubject instanceof ThemeActionEvent ? $obSubject->arPayload : [];
        $arResult = [];
        foreach (self::USER_DATA_KEYS as $sKey) {
            $mValue = $arPayload[$sKey] ?? null;
            $arResult[$sKey] = is_string($mValue) ? $mValue : null;
        }

        return $arResult;
    }

    /** @return array<string, list<string>> */
    public function getSupportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    /** @var list<string> */
    private const USER_DATA_KEYS = [
        'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id',
        'fbp', 'fbc', 'client_ip_address', 'client_user_agent',
    ];
}
