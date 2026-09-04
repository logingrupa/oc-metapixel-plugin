<?php

namespace Logingrupa\Metapixel\Classes\Event;

use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Meta\UserDataResolveHook;

/**
 * Trait used by in-request event watchers (CartPositionWatcher,
 * OrderStatusWatcher, ProductPageWatcher) to merge the Meta CAPI passthrough
 * user_data fields (client_ip_address, client_user_agent, fbp, fbc), the
 * hashed identity from metapixel.user_data.resolve and the event_source_url
 * into the PayloadBuilder output before SendCapiEvent::dispatch.
 *
 * Watchers run inside the originating HTTP request (eloquent.created /
 * eloquent.updated listeners fire synchronously), so request context is
 * authoritative at this exact moment. The PHPStan disallowed-calls rule
 * blocks Illuminate\Http\Request::* and request() inside classes/event/*
 * (cross-context determinism), so the values are read from PHP superglobals
 * ($_SERVER, $_COOKIE), which are scoped to the SAPI request.
 *
 * Without these fields Meta CAPI rejects events with HTTP 400 subcode
 * 2804050 ("You haven't added sufficient customer information parameter
 * data for this event").
 */
trait CapturesRequestUserData
{
    /**
     * Merge request passthrough fields, hashed identity and event_source_url
     * into a PayloadBuilder result. Existing non-null user_data values win.
     *
     * @param  array<string, mixed>  $arPayload  output of PayloadBuilder::buildEventPayload
     * @return array<string, mixed>
     */
    protected function injectRequestUserData(string $sEventName, string $sSubjectType, array $arPayload): array
    {
        /** @var UserDataResolveHook $obResolveHook */
        $obResolveHook = App::make(UserDataResolveHook::class);

        return $obResolveHook->mergeIntoPayload($sEventName, $sSubjectType, $arPayload, $this->collectRequestUserData());
    }

    /**
     * Read passthrough request fields from PHP superglobals. Returns null
     * for each that is absent or empty so the merge can skip cleanly without
     * overwriting subject-supplied values with empties.
     *
     * @return array<string, ?string>
     */
    protected function collectRequestUserData(): array
    {
        return [
            'client_ip_address' => $this->resolveClientIp(),
            'client_user_agent' => $this->nonEmptyString($_SERVER['HTTP_USER_AGENT'] ?? null),
            'fbp' => $this->nonEmptyString($_COOKIE['_fbp'] ?? null),
            'fbc' => $this->nonEmptyString($_COOKIE['_fbc'] ?? null),
        ];
    }

    private function resolveClientIp(): ?string
    {
        $mForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($mForwarded) && $mForwarded !== '') {
            $sFirst = (string) strstr($mForwarded, ',', true);
            $sCandidate = $sFirst !== '' ? trim($sFirst) : trim($mForwarded);
            if ($sCandidate !== '') {
                return $sCandidate;
            }
        }

        return $this->nonEmptyString($_SERVER['REMOTE_ADDR'] ?? null);
    }

    private function nonEmptyString(mixed $mValue): ?string
    {
        return is_string($mValue) && $mValue !== '' ? $mValue : null;
    }
}
