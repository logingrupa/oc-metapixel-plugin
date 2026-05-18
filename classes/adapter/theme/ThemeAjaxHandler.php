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

            $iJsonFlags = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;
            $sScript = sprintf(
                '<script>fbq("track", %s, {}, {eventID: %s});</script>',
                (string) json_encode($obEvent->sEventName, $iJsonFlags),
                (string) json_encode($sEventId, $iJsonFlags),
            );

            return new JsonResponse(['event_id' => $sEventId, 'script' => $sScript]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: ThemeAjaxHandler failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return new JsonResponse(['error' => 'internal'], 500);
        }
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
