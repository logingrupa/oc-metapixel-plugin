<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown at event-fire time when Settings::lookupForSite returns an empty
 * capi_access_token. The CAPI dispatch step cannot proceed without it; pixel
 * dispatch (browser channel) is unaffected.
 */
final class MissingCapiTokenException extends MetaPixelException
{
}
