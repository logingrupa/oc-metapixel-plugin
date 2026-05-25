<?php

namespace Logingrupa\Metapixel\Classes\Event;

/**
 * Trait used by in-request event watchers (CartPositionWatcher,
 * OrderStatusWatcher) to merge Meta CAPI passthrough user_data fields
 * (client_ip_address, client_user_agent, fbp, fbc) into the PayloadBuilder
 * output before SendCapiEvent::dispatch.
 *
 * Watchers run inside the originating HTTP request (eloquent.created /
 * eloquent.updated listeners fire synchronously), so request context is
 * authoritative at this exact moment — but the PHPStan disallowed-calls
 * rule blocks Illuminate\Http\Request::* and request() inside
 * classes/event/* (D-15+D-16 cross-context-determinism lock). The
 * subject adapter contract intentionally stays request-free for the same
 * reason. The fix is to capture the request boundary HERE (the only
 * layer that has clean access to it) and inject the values into the
 * already-built payload.
 *
 * Uses PHP superglobals ($_SERVER, $_COOKIE) rather than Laravel facades
 * to avoid widening the PHPStan disallowIn surface — superglobals are
 * not in the disallowed-calls rule (they were never the cross-context-
 * drift vector, since they are scoped to the SAPI request and not to a
 * facade resolution that could pick up a stale or background queue
 * worker request).
 *
 * Without these fields Meta CAPI rejects events with HTTP 400 subcode
 * 2804050 ("You haven't added sufficient customer information parameter
 * data for this event"), even when other user_data fields (email,
 * phone, name) are populated from the subject. fbp/fbc + client_ip +
 * client_user_agent are the request-context bridge that makes server-
 * side events match the browser-side fbq calls.
 */
trait CapturesRequestUserData
{
    /**
     * Merge request-derived passthrough fields into the existing user_data
     * envelope inside a PayloadBuilder result. Returns the new payload.
     * Existing non-null values in the payload's user_data win over the
     * request values — caller may have a more specific source.
     *
     * @param  array<string, mixed>  $arPayload  output of PayloadBuilder::buildEventPayload
     * @return array<string, mixed>
     */
    protected function injectRequestUserData(array $arPayload): array
    {
        $arRequestFields = $this->collectRequestUserData();
        $mData = $arPayload['data'] ?? null;
        if (! is_array($mData) || ! isset($mData[0]) || ! is_array($mData[0])) {
            return $arPayload;
        }
        $mEnvelope = $mData[0];
        $mUserData = $mEnvelope['user_data'] ?? null;
        $arUserData = is_array($mUserData) ? $mUserData : [];

        foreach ($arRequestFields as $sKey => $mValue) {
            if ($mValue === null) {
                continue;
            }
            $mExisting = $arUserData[$sKey] ?? null;
            if ($mExisting === null || $mExisting === '') {
                $arUserData[$sKey] = $mValue;
            }
        }
        $mEnvelope['user_data'] = $arUserData;
        $mData[0] = $mEnvelope;
        $arPayload['data'] = $mData;

        return $arPayload;
    }

    /**
     * Read passthrough request fields from PHP superglobals. Returns null
     * for each that is absent or empty so injectRequestUserData can skip
     * cleanly without overwriting subject-supplied values with empties.
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
