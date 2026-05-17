<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown at event-fire time when Settings::lookupForSite returns an empty
 * pixel_id. Boot-time empty pixel_id is handled by PluginGuard (log + disable
 * + no throw) — this exception only fires when an event has slipped past the
 * guard for the current site row.
 */
final class MissingPixelConfigException extends MetaPixelException {}
