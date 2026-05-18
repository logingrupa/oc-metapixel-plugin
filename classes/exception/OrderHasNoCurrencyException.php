<?php

namespace Logingrupa\Metapixel\Classes\Exception;

/**
 * Thrown when a Shopaholic Order has neither a currency relation nor a
 * currency_code field and no Settings default exists.
 */
final class OrderHasNoCurrencyException extends MetaPixelException {}
