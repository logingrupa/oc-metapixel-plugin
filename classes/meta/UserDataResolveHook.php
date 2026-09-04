<?php

namespace Logingrupa\Metapixel\Classes\Meta;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Logingrupa\Metapixel\Classes\Helper\CrawlerUserAgent;
use Logingrupa\Metapixel\Classes\Helper\EventSourceUrl;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Throwable;

/**
 * Request-boundary identity hook. Fires metapixel.user_data.resolve once per
 * request so the host can supply the raw customer identity (em, ph, fn, ln,
 * ct, st, zp, country, external_id) of the current visitor. The result is
 * hashed and memoised; every in-request CAPI dispatch and the browser
 * Advanced Matching init read the same copy. Adapter-supplied values win at
 * merge time because PayloadRequestContext::merge fills empty keys only.
 *
 * Listeners receive [array &$arUserData, array $arContext]; $arContext
 * carries event_name and subject_type of the first event that asked. A
 * throwing listener abstains: the request proceeds without identity.
 * The event does not fire while the plugin is disabled or for a crawler user
 * agent: no CAPI event ships for those requests, so no listener should spend
 * a user lookup on them.
 *
 * Container singleton (Plugin::register), so the memo lives exactly one
 * request. Never called from the queue worker: the job payload already
 * carries the hashed identity.
 */
final class UserDataResolveHook
{
    public const HOOK_RESOLVE = 'metapixel.user_data.resolve';

    /** @var array<string, string>|null */
    private ?array $arHashedIdentity = null;

    public function __construct(private readonly UserDataHasher $obHasher) {}

    /**
     * sha256 hashes keyed by identity field, empty fields absent.
     *
     * @return array<string, string>
     */
    public function hashedIdentity(string $sEventName, string $sSubjectType): array
    {
        if ($sEventName === '' || $sSubjectType === '') {
            throw new InvalidArgumentException('UserDataResolveHook::hashedIdentity requires a non-empty event name and subject type');
        }
        if ($this->arHashedIdentity === null) {
            $this->arHashedIdentity = $this->isSuppressed()
                ? []
                : $this->obHasher->hashIdentity($this->fireResolve($sEventName, $sSubjectType));
        }

        return $this->arHashedIdentity;
    }

    /**
     * Fill the envelope's empty user_data with the request passthrough fields
     * and the hashed identity, and set event_source_url. The single merge
     * every in-request dispatch path goes through.
     *
     * @param  array<string, mixed>  $arPayload  output of PayloadBuilder::buildEventPayload
     * @param  array<string, mixed>  $arRequestUserData  client_ip_address, client_user_agent, fbp, fbc; null values are skipped
     * @return array<string, mixed>
     */
    public function mergeIntoPayload(string $sEventName, string $sSubjectType, array $arPayload, array $arRequestUserData): array
    {
        $arUserData = array_merge($arRequestUserData, $this->hashedIdentity($sEventName, $sSubjectType));

        return PayloadRequestContext::merge($arPayload, $arUserData, EventSourceUrl::current());
    }

    /** Forget the memo (request boundary in long-running runtimes, tests). */
    public function reset(): void
    {
        $this->arHashedIdentity = null;
    }

    /** Disabled plugin or crawler request: nothing ships, so nothing is resolved. */
    private function isSuppressed(): bool
    {
        if (PluginGuard::isDisabled()) {
            return true;
        }
        $mUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return CrawlerUserAgent::isCrawler(is_string($mUserAgent) ? $mUserAgent : null);
    }

    /**
     * @return array<string, mixed> raw identity keys as the listeners left them
     */
    private function fireResolve(string $sEventName, string $sSubjectType): array
    {
        $arUserData = array_fill_keys(UserDataHasher::IDENTITY_FIELDS, null);
        $arContext = ['event_name' => $sEventName, 'subject_type' => $sSubjectType];

        try {
            Event::fire(self::HOOK_RESOLVE, [&$arUserData, $arContext]);

            return array_intersect_key($arUserData, array_flip(UserDataHasher::IDENTITY_FIELDS));
        } catch (Throwable $obException) {
            // Tiger-Style boundary: a throwing listener abstains, the event
            // still ships with the request passthrough fields.
            Log::warning('metapixel: user_data.resolve listener threw, continuing without identity', [
                'meta_pixel.event_name' => $sEventName,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return [];
        }
    }
}
