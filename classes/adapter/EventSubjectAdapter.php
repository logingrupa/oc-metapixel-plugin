<?php

namespace Logingrupa\Metapixel\Classes\Adapter;

/**
 * Adapter contract — every event-subject vendor (Shopaholic Order, theme
 * action, third-party cart) implements one of these. Routes a subject to its
 * opaque alias, id, site, secret-key, value resolver, raw user-data fields,
 * and supported event channels. The dispatch pipeline never types a subject
 * directly — it always goes through this interface.
 */
interface EventSubjectAdapter
{
    /**
     * Opaque alias identifying the subject vendor + entity kind. MUST be an
     * alias such as 'shopaholic.order' — MUST NOT contain backslashes; MUST
     * NOT be a class FQN. Aliases let the EventLog stay stable across class
     * renames + multi-vendor installs.
     */
    public function getSubjectType(object $obSubject): string;

    /**
     * Numeric subject identifier (e.g. order id, synthetic theme-action id).
     * MUST be a positive int — EventLogWriter rejects values <= 0.
     */
    public function getSubjectId(object $obSubject): int;

    /**
     * Site id MUST be read from the subject itself (Order column, theme-action
     * pushed payload). MUST NOT be derived from request context, the active
     * SiteManager site, or Auth state — cross-context determinism is the
     * invariant. PHPStan disallowed-calls bans SiteManager / Request inside
     * adapter directories to enforce.
     */
    public function getSiteId(object $obSubject): ?int;

    /**
     * Per-subject secret token (Order.secret_key, session token, etc.) used
     * to derive anonymous external_id when the subject has no logged-in user.
     * Return null when no token is available.
     */
    public function getSecretKey(object $obSubject): ?string;

    /**
     * Per-subject ValueResolver. Each adapter chooses how content_ids / value
     * / currency / contents / num_items are computed for its subject.
     */
    public function getValueResolver(object $obSubject): ValueResolver;

    /**
     * Raw (unhashed) user_data fields per Meta CAPI spec. Allowed keys:
     * em, ph, fn, ln, ct, st, zp, country, external_id, fbp, fbc,
     * client_ip_address, client_user_agent. Missing keys MUST be null
     * (do not omit).
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array;

    /**
     * Declarative event-channel matrix. Shape: array<string, list<string>>
     * where the outer key is the Meta event name (Purchase, ViewContent, …)
     * and the inner list values are channel names — subset of {'capi','pixel'}.
     *
     * @return array<string, list<string>>
     */
    public function getSupportedEvents(): array;
}
