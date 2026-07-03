<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Theme;

use Cms\Classes\Controller;
use Illuminate\Cache\RateLimiter;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher;
use Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\FbqScriptBuilder;
use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * P-09 defence for Metapixel::onFireEvent Larajax calls — META_STANDARD ∪
 * Settings.theme_custom_event_names allowlist, per-IP+session rate limit,
 * JS-escape on the returned fbq script. October's AjaxFramework already
 * 419's invalid request tokens upstream — no redundant token check here.
 */
final class ThemeAjaxHandler
{
    public const HANDLER_NAME = 'Metapixel::onFireEvent';

    public const HANDLER_MARK_ADD_TO_CART = 'Metapixel::onMarkAddToCart';

    /** @var list<string> */
    public const META_STANDARD = [
        'PageView',
        'ViewContent',
        'AddToCart',
        'AddToWishlist',
        'InitiateCheckout',
        'Purchase',
        'Lead',
        'CompleteRegistration',
        'Search',
        'Subscribe',
        'Contact',
        'FindLocation',
        'Donate',
        'CustomizeProduct',
        'SubmitApplication',
        'AddPaymentInfo',
        'StartTrial',
        'Schedule',
    ];

    private const RATE_LIMIT_MAX = 30;

    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(private readonly ThemeAjaxRequestReader $obRequestReader = new ThemeAjaxRequestReader) {}

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('cms.ajax.beforeRunHandler', [$this, 'onBeforeRun']);
    }

    /** cms.ajax.beforeRunHandler listener; non-null return short-circuits AJAX. */
    public function onBeforeRun(Controller $obController, string $sHandler): mixed
    {
        if ($sHandler === self::HANDLER_MARK_ADD_TO_CART) {
            return $this->markAddToCartPixel();
        }
        if ($sHandler !== self::HANDLER_NAME) {
            return null;
        }

        return $this->handleFireEvent();
    }

    /** Validate + route a Metapixel::onFireEvent call to the adapter or theme-action path. */
    private function handleFireEvent(): JsonResponse
    {
        try {
            // PluginGuard pattern: with an empty pixel_id every dispatch would
            // dead-letter (MissingPixelConfigException → unbounded FailedEvent
            // growth) and the returned fbq script would throw a ReferenceError
            // on a page whose base pixel never rendered. Soft-empty response.
            if (PluginGuard::isDisabled()) {
                return new JsonResponse(['event_id' => null, 'script' => '']);
            }

            $arData = $this->obRequestReader->readEventData();
            if ($arData === null) {
                return new JsonResponse(['error' => 'invalid request shape'], 400);
            }
            if (! $this->isAllowedEventName($arData['name'] ?? '')) {
                return new JsonResponse(['error' => 'event_name not allowed'], 422);
            }
            if ($this->isRateLimited()) {
                return new JsonResponse(['error' => 'rate limit exceeded'], 429);
            }

            $mSubjectType = $arData['subject_type'] ?? null;
            if (is_string($mSubjectType) && $mSubjectType !== '') {
                return $this->dispatchViaAdapter($arData, $mSubjectType);
            }

            // Identity firewall: the client controls ONLY the event name and
            // action_key. Every Meta CAPI identity field (em, ph, external_id,
            // …), secret_key, and site_id is server-derived — otherwise any
            // visitor could inject arbitrary identities into server-signed
            // CAPI events or select another site's pixel credentials.
            $arSafe = array_intersect_key($arData, ['name' => true, 'action_key' => true]);
            $arSafe = array_merge($arSafe, $this->obRequestReader->collectServerUserData());

            try {
                $obEvent = ThemeActionEvent::fromArray($arSafe);
            } catch (InvalidArgumentException $obException) {
                return new JsonResponse(
                    ['error' => 'invalid event payload: '.$obException->getMessage()],
                    422,
                );
            }

            $sEventId = Uuid::uuid4()->toString();
            $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
                $obEvent->sEventName,
                App::make(ThemeActionAdapter::class),
                $obEvent,
                new ThemeActionValueResolver,
                $sEventId,
                time(),
                [],
            );
            SendCapiEvent::dispatch($obEvent->sEventName, $arPayload, $obEvent, ThemeActionAdapter::class);

            $sScript = FbqScriptBuilder::build($obEvent->sEventName, [], $sEventId, $this->resolveTestEventCode());

            return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: ThemeAjaxHandler failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return new JsonResponse(['error' => 'internal'], 500);
        }
    }

    /**
     * PIXEL-ONLY AddToCart wire (D-07). Reads the server-generated capi
     * event_id + custom_data for the current-session cart position via
     * CartPositionWatcher::resolveBrowserPixel and returns an executable fbq
     * AddToCart block carrying that eventID (true event_id dedup). Dispatches NO
     * CAPI — the server AddToCart already fired on CartPosition eloquent.created.
     * event_id is server-sourced only; the browser never supplies it (T-05G-03).
     */
    private function markAddToCartPixel(): JsonResponse
    {
        try {
            $arData = $this->obRequestReader->readEventData() ?? [];
            $iOfferId = $this->obRequestReader->readIntField($arData, 'offer_id');
            if ($iOfferId <= 0) {
                return new JsonResponse(['error' => 'invalid offer_id'], 422);
            }
            if ($this->isRateLimited()) {
                return new JsonResponse(['error' => 'rate limit exceeded'], 429);
            }

            $obResult = App::make(CartPositionWatcher::class)->resolveBrowserPixel($iOfferId);
            if ($obResult === null) {
                return new JsonResponse(['event_id' => null, 'script' => '']);
            }

            $sScript = FbqScriptBuilder::build(
                'AddToCart',
                $obResult->arCustomData,
                $obResult->sEventId,
                $this->resolveTestEventCode(),
            );

            return new JsonResponse(['event_id' => $obResult->sEventId, 'script' => $sScript]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: ThemeAjaxHandler onMarkAddToCart failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return new JsonResponse(['error' => 'internal'], 500);
        }
    }

    /**
     * Hybrid AJAX path — routes a subject_type alias through AdapterRegistry to
     * the registered adapter's loadSubject + getValueResolver. The alias is the
     * ONLY untrusted string accepted from JS; it is bounded to the register-time
     * alias index, so no FQN injection is possible (T-6-04 mitigation). Adapters
     * MUST implement SupportsHybridAjax to opt in. Adapters MUST re-enforce
     * subject's domain guards inside loadSubject (active/site/SoftDelete) per
     * T-6-05 mitigation.
     *
     * For the shopaholic.product alias the handler delegates to
     * ProductPageWatcher::dispatchForOfferSwitch which owns the canonical
     * viewcontent:{pid}:{oid}:{eid} action_key shape. For generic hybrid
     * aliases the handler appends the server-generated event_id to the
     * wire-format action_key BEFORE dispatch per CONTEXT.md Claude's
     * Discretion (JS sends viewcontent:{pid}:{oid}, server stores
     * viewcontent:{pid}:{oid}:{eid}).
     *
     * @param  array<string, mixed>  $arData
     */
    private function dispatchViaAdapter(array $arData, string $sSubjectType): JsonResponse
    {
        try {
            $sAdapterClass = App::make(AdapterRegistry::class)->resolveByAlias($sSubjectType);
        } catch (UnknownSubjectTypeException $obException) {
            return new JsonResponse(['error' => 'unknown subject_type'], 422);
        }

        $obAdapter = App::make($sAdapterClass);
        if (! $obAdapter instanceof SupportsHybridAjax) {
            return new JsonResponse(['error' => 'subject_type does not support hybrid AJAX'], 422);
        }

        $iSubjectId = $this->obRequestReader->readIntField($arData, 'subject_id');
        if ($iSubjectId <= 0) {
            return new JsonResponse(['error' => 'invalid subject_id'], 422);
        }

        $arContext = $this->obRequestReader->buildHybridContext($arData);

        $obSubject = $obAdapter->loadSubject($iSubjectId, $arContext);
        if ($obSubject === null) {
            return new JsonResponse(['error' => 'subject not found'], 404);
        }

        $sName = $this->resolveAllowedEventName($arData['name'] ?? '');
        if ($sName === null) {
            return new JsonResponse(['error' => 'event_name not allowed'], 422);
        }

        // The adapter's declared event-channel matrix is contract surface —
        // a name outside it must not produce a server-blessed dispatch even
        // when the global allowlist admits it.
        if (! array_key_exists($sName, $obAdapter->getSupportedEvents())) {
            return new JsonResponse(['error' => 'event_name not supported by subject_type'], 422);
        }

        $sTestCode = $this->resolveTestEventCode();
        if ($sAdapterClass === ShopaholicProductAdapter::class) {
            return $this->dispatchShopaholicOfferSwitch($sName, $iSubjectId, $arData, $sTestCode);
        }

        return $this->dispatchGenericAdapter($sName, $obAdapter, $obSubject, $sAdapterClass, $arData, $sTestCode);
    }

    /**
     * Terminal shopaholic.product dispatch — delegates to
     * ProductPageWatcher::dispatchForOfferSwitch which owns the canonical
     * viewcontent:{pid}:{oid}:{eid} action_key shape.
     *
     * @param  array<string, mixed>  $arData
     */
    private function dispatchShopaholicOfferSwitch(string $sName, int $iSubjectId, array $arData, ?string $sTestCode): JsonResponse
    {
        // The delegate always dispatches CAPI 'ViewContent'. Echoing any other
        // client-chosen name into the returned fbq script would mint an
        // unmatched, server-blessed browser event (Meta dedup pairs identical
        // names only) — reject instead.
        if ($sName !== 'ViewContent') {
            return new JsonResponse(['error' => 'offer-switch supports ViewContent only'], 422);
        }
        $iOfferId = $this->obRequestReader->readIntField($arData, 'offer_id');
        if ($iOfferId <= 0) {
            return new JsonResponse(['error' => 'invalid offer_id'], 422);
        }
        /** @var OfferSwitchResult $obResult */
        $obResult = App::make(ProductPageWatcher::class)->dispatchForOfferSwitch($iSubjectId, $iOfferId);
        $sEventId = $obResult->sEventId;
        $sScript = FbqScriptBuilder::build($sName, $obResult->arCustomData, $sEventId, $sTestCode);

        return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
    }

    /**
     * Terminal generic-alias dispatch. Appends the server-generated event_id
     * to the wire-format action_key and carries it inside custom_data via the
     * builder's extras (JS sends viewcontent:{pid}:{oid}, server stores
     * viewcontent:{pid}:{oid}:{eid}). It MUST NOT sit at the top level of the
     * Graph envelope — Graph rejects unknown top-level POST parameters with
     * (#100) and it would leak internal routing data. The append is
     * observability-only; payload-side dedup is anchored by event_id within
     * ±10s of event_time. Generic-alias theme actions are contentless — empty
     * {} browser custom_data (do NOT invent content); the builder still adds
     * eventID + test code. Server-captured ip/UA/fbp/fbc are merged into the
     * built payload's user_data before dispatch — an anonymous subject would
     * otherwise ship empty user_data, which Meta rejects (subcode 2804050)
     * into a permanent dead-letter. Adapter-supplied values win.
     *
     * @param  array<string, mixed>  $arData
     */
    private function dispatchGenericAdapter(string $sName, SupportsHybridAjax $obAdapter, object $obSubject, string $sAdapterClass, array $arData, ?string $sTestCode): JsonResponse
    {
        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();
        $mWireActionKey = $arData['action_key'] ?? null;
        $sWireActionKey = is_string($mWireActionKey) ? $mWireActionKey : '';
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            $sName,
            $obAdapter,
            $obSubject,
            $obAdapter->getValueResolver($obSubject),
            $sEventId,
            $iEventTime,
            $sWireActionKey !== '' ? ['action_key' => $sWireActionKey.':'.$sEventId] : [],
        );
        $arPayload = $this->obRequestReader->injectServerUserData($arPayload);
        SendCapiEvent::dispatch($sName, $arPayload, $obSubject, $sAdapterClass);

        $sScript = FbqScriptBuilder::build($sName, [], $sEventId, $sTestCode);

        return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
    }

    /** Resolve Settings.test_event_code as a non-empty string, or null. */
    private function resolveTestEventCode(): ?string
    {
        $mTestCode = Settings::get('test_event_code', '');

        return is_string($mTestCode) && $mTestCode !== '' ? $mTestCode : null;
    }

    /** Narrow an untrusted event name to an allowed non-empty string, or null. */
    private function resolveAllowedEventName(mixed $mName): ?string
    {
        if (! is_string($mName) || $mName === '' || ! $this->isAllowedEventName($mName)) {
            return null;
        }

        return $mName;
    }

    private function isAllowedEventName(mixed $mName): bool
    {
        if (! is_string($mName) || $mName === '') {
            return false;
        }
        if (in_array($mName, self::META_STANDARD, true)) {
            return true;
        }

        return in_array($mName, Settings::getThemeCustomEventNames(), true);
    }

    private function isRateLimited(): bool
    {
        /** @var RateLimiter $obLimiter */
        $obLimiter = App::make(RateLimiter::class);
        $sKey = sprintf('metapixel:fire:%s:%s', (string) Request::ip(), Session::getId());
        if ($obLimiter->tooManyAttempts($sKey, self::RATE_LIMIT_MAX)) {
            return true;
        }
        $obLimiter->hit($sKey, self::RATE_LIMIT_WINDOW_SECONDS);

        return false;
    }
}
