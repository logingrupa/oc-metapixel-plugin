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

        try {
            $arData = $this->normalizeStringKeys(Request::input('data', []));
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

            try {
                $obEvent = ThemeActionEvent::fromArray($arData);
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
            $arData = $this->normalizeStringKeys(Request::input('data', [])) ?? [];
            $mOfferId = $arData['offer_id'] ?? 0;
            $iOfferId = is_numeric($mOfferId) ? (int) $mOfferId : 0;
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

        $mSubjectId = $arData['subject_id'] ?? 0;
        $iSubjectId = is_numeric($mSubjectId) ? (int) $mSubjectId : 0;
        if ($iSubjectId <= 0) {
            return new JsonResponse(['error' => 'invalid subject_id'], 422);
        }

        $arContext = $this->buildHybridContext($arData);

        $obSubject = $obAdapter->loadSubject($iSubjectId, $arContext);
        if ($obSubject === null) {
            return new JsonResponse(['error' => 'subject not found'], 404);
        }

        $mName = $arData['name'] ?? '';
        if (! is_string($mName) || $mName === '' || ! $this->isAllowedEventName($mName)) {
            return new JsonResponse(['error' => 'event_name not allowed'], 422);
        }

        $sTestCode = $this->resolveTestEventCode();
        if ($sAdapterClass === ShopaholicProductAdapter::class) {
            $mOfferId = $arData['offer_id'] ?? 0;
            $iOfferId = is_numeric($mOfferId) ? (int) $mOfferId : 0;
            if ($iOfferId <= 0) {
                return new JsonResponse(['error' => 'invalid offer_id'], 422);
            }
            /** @var OfferSwitchResult $obResult */
            $obResult = App::make(ProductPageWatcher::class)->dispatchForOfferSwitch($iSubjectId, $iOfferId);
            $sEventId = $obResult->sEventId;
            $sScript = FbqScriptBuilder::build($mName, $obResult->arCustomData, $sEventId, $sTestCode);

            return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
        } else {
            $sEventId = Uuid::uuid4()->toString();
            $iEventTime = time();
            $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
                $mName,
                $obAdapter,
                $obSubject,
                $obAdapter->getValueResolver($obSubject),
                $sEventId,
                $iEventTime,
                [],
            );
            // Server-side append: convert wire-format action_key to canonical
            // four-segment shape (CONTEXT.md Claude's Discretion). The append
            // is observability-only here; payload-side dedup is anchored by
            // event_id within ±10s of event_time. Generic-alias path retained
            // for future adapters (e.g. mall.product).
            $mWireActionKey = $arData['action_key'] ?? null;
            $sWireActionKey = is_string($mWireActionKey) ? $mWireActionKey : '';
            if ($sWireActionKey !== '') {
                $arPayload['action_key'] = $sWireActionKey.':'.$sEventId;
            }
            SendCapiEvent::dispatch($mName, $arPayload, $obSubject, $sAdapterClass);
        }

        // Generic-alias theme actions are contentless — empty {} custom_data
        // (do NOT invent content); the builder still adds eventID + test code.
        $sScript = FbqScriptBuilder::build($mName, [], $sEventId, $sTestCode);

        return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
    }

    /** Resolve Settings.test_event_code as a non-empty string, or null. */
    private function resolveTestEventCode(): ?string
    {
        $mTestCode = Settings::get('test_event_code', '');

        return is_string($mTestCode) && $mTestCode !== '' ? $mTestCode : null;
    }

    /**
     * Narrow $arData['context'] to a string-keyed array (phpstan level 10
     * requires explicit string-key narrowing on the SupportsHybridAjax
     * loadSubject contract) + overlay top-level offer_id when present.
     *
     * @param  array<string, mixed>  $arData
     * @return array<string, mixed>
     */
    private function buildHybridContext(array $arData): array
    {
        $arContext = [];
        $mContext = $arData['context'] ?? null;
        if (is_array($mContext)) {
            foreach ($mContext as $mKey => $mValue) {
                if (is_string($mKey)) {
                    $arContext[$mKey] = $mValue;
                }
            }
        }
        foreach (['offer_id'] as $sExtra) {
            if (isset($arData[$sExtra])) {
                $arContext[$sExtra] = $arData[$sExtra];
            }
        }

        return $arContext;
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

    /**
     * Narrow Request::input to a string-keyed array, or null when unusable.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeStringKeys(mixed $mInput): ?array
    {
        if (! is_array($mInput)) {
            return null;
        }
        $arResult = [];
        foreach ($mInput as $mKey => $mValue) {
            if (! is_string($mKey)) {
                return null;
            }
            $arResult[$mKey] = $mValue;
        }

        return $arResult;
    }
}
