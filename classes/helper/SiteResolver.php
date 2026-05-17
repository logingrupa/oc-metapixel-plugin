<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;

/**
 * The only authoritative source of site_id for an event-fire path.
 *
 * Reads exclusively from the subject via the supplied adapter — never from
 * ambient request, site manager, or auth state. This is the cross-context
 * determinism contract: admin-side flips, queue-time worker dispatches, and
 * frontend EventPixel renders all return the same site_id for the same
 * subject. Hard-banned via phpstan disallowed-calls in adapter / queue /
 * event directories.
 */
final class SiteResolver
{
    public static function forSubject(object $obSubject, EventSubjectAdapter $obAdapter): ?int
    {
        return $obAdapter->getSiteId($obSubject);
    }
}
