<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown by AdapterRegistry::resolveByAlias when the supplied subject_type
 * string does not match any registered adapter's alias. Caught at
 * ThemeAjaxHandler::onBeforeRun → returns JsonResponse 422.
 */
final class UnknownSubjectTypeException extends MetaPixelException {}
