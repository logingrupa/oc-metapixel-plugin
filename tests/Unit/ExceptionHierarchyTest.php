<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Logingrupa\Metapixelshopaholic\Classes\Exception\InvalidEventIdException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoItemsException;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Unit test locking the Plan 03-02 PAY-09 exception hierarchy contract:
 *
 *   1. MetaPixelException is abstract — cannot be instantiated directly.
 *   2. MetaPixelException extends \RuntimeException.
 *   3. Every concrete exception extends MetaPixelException.
 *   4. Every concrete exception is `final` (Tiger-Style + PHP 8.4 idiom).
 *   5. Constructor signature: ($sMessage, $arContext, ?$obPrevious).
 *   6. $arContext is PHP 8.4 `readonly` — assignment raises \Error.
 *   7. MetaApiTransientException::isRetryable() === true (the ONLY one).
 *   8. MetaApiPermanentException::isRetryable() === false.
 *   9. Every non-API concrete exception's isRetryable() === false.
 *  10. jsonContext() returns valid JSON (round-trip equality).
 *  11. Every lang key resolves to a non-empty string under the en locale.
 *
 * The arContext readonly test catches the engine-level \Error to confirm
 * the PHP 8.4 readonly modifier is in force (T-03-06 immutability guard).
 *
 * The jsonContext test uses an anonymous-class accessor to expose the
 * protected static helper since direct external invocation is forbidden by
 * the visibility contract.
 */
final class ExceptionHierarchyTest extends MetapixelTestCase
{
    /**
     * Lock the seven concrete class names in one place so every iteration
     * test reads from the same source.
     *
     * @var list<class-string<MetaPixelException>>
     */
    private const CONCRETE_CLASSES = [
        MissingPixelConfigException::class,
        MissingCapiTokenException::class,
        OrderHasNoCurrencyException::class,
        OrderHasNoItemsException::class,
        InvalidEventIdException::class,
        MetaApiTransientException::class,
        MetaApiPermanentException::class,
    ];

    /**
     * Non-API concrete classes — all `isRetryable()` MUST return false.
     *
     * @var list<class-string<MetaPixelException>>
     */
    private const NON_API_CONCRETE_CLASSES = [
        MissingPixelConfigException::class,
        MissingCapiTokenException::class,
        OrderHasNoCurrencyException::class,
        OrderHasNoItemsException::class,
        InvalidEventIdException::class,
    ];

    /**
     * The seven lang keys appended in Task 4. Test 11 asserts each
     * resolves to a non-empty string under en locale.
     *
     * @var list<string>
     */
    private const LANG_KEYS = [
        'missing_pixel_config',
        'missing_capi_token',
        'order_has_no_currency',
        'order_has_no_items',
        'invalid_event_id',
        'meta_api_transient',
        'meta_api_permanent',
    ];

    public function test_meta_pixel_exception_is_abstract(): void
    {
        $obReflection = new \ReflectionClass(MetaPixelException::class);

        $this->assertTrue(
            $obReflection->isAbstract(),
            'MetaPixelException MUST be abstract — only concrete subclasses may be instantiated.'
        );
    }

    public function test_meta_pixel_exception_extends_runtime_exception(): void
    {
        $this->assertTrue(
            is_subclass_of(MetaPixelException::class, \RuntimeException::class),
            'MetaPixelException MUST extend \RuntimeException for Tiger-Style fail-fast.'
        );
    }

    public function test_every_concrete_exception_extends_meta_pixel_exception(): void
    {
        foreach (self::CONCRETE_CLASSES as $sClass) {
            $this->assertTrue(
                is_subclass_of($sClass, MetaPixelException::class),
                "{$sClass} MUST extend MetaPixelException."
            );
        }
    }

    public function test_every_concrete_exception_is_final(): void
    {
        foreach (self::CONCRETE_CLASSES as $sClass) {
            $obReflection = new \ReflectionClass($sClass);
            $this->assertTrue(
                $obReflection->isFinal(),
                "{$sClass} MUST be declared `final` (T-03-07 — subclass cannot flip retryability)."
            );
        }
    }

    public function test_concrete_exception_constructor_signature(): void
    {
        $obPrevious = new \Exception('prev');
        $obException = new MissingPixelConfigException(
            'msg',
            ['order_id' => 42],
            $obPrevious,
        );

        $this->assertSame('msg', $obException->getMessage());
        $this->assertSame(['order_id' => 42], $obException->arContext);
        $this->assertSame($obPrevious, $obException->getPrevious());
        $this->assertSame('prev', $obException->getPrevious()->getMessage());
    }

    public function test_arContext_is_readonly(): void
    {
        $obException = new MissingPixelConfigException('msg', ['k' => 'v']);

        $bCaught = false;
        try {
            // @phpstan-ignore-next-line readonly property write attempt is intentional
            $obException->arContext = ['x' => 'y'];
        } catch (\Error $obError) {
            $bCaught = str_contains($obError->getMessage(), 'readonly');
        }

        $this->assertTrue(
            $bCaught,
            'arContext MUST be readonly — PHP 8.4 must raise \Error containing "readonly" on assignment.'
        );
    }

    public function test_meta_api_transient_exception_is_retryable(): void
    {
        $obException = new MetaApiTransientException('transient');

        $this->assertTrue(
            $obException->isRetryable(),
            'MetaApiTransientException::isRetryable() MUST return true — the ONLY Phase-3 exception that does.'
        );
    }

    public function test_meta_api_permanent_exception_is_not_retryable(): void
    {
        $obException = new MetaApiPermanentException('permanent');

        $this->assertFalse(
            $obException->isRetryable(),
            'MetaApiPermanentException::isRetryable() MUST return false — dead-letter signal.'
        );
    }

    public function test_non_api_exceptions_are_not_retryable(): void
    {
        foreach (self::NON_API_CONCRETE_CLASSES as $sClass) {
            /** @var MetaPixelException $obException */
            $obException = new $sClass('msg');

            $this->assertFalse(
                $obException->isRetryable(),
                "{$sClass}::isRetryable() MUST return false (only MetaApiTransientException returns true)."
            );
        }
    }

    public function test_jsonContext_returns_compact_json(): void
    {
        $obExposer = new class('') extends MetaPixelException
        {
            public function isRetryable(): bool
            {
                return false;
            }

            /**
             * @param  array<string, mixed>  $arContext
             */
            public static function publicJsonContext(array $arContext): string
            {
                return self::jsonContext($arContext);
            }
        };

        $sJson = $obExposer::publicJsonContext(['order_id' => 42, 'event_id' => 'abc']);
        $arDecoded = json_decode($sJson, true);

        $this->assertSame(
            ['order_id' => 42, 'event_id' => 'abc'],
            $arDecoded,
            'jsonContext() output MUST round-trip via json_decode.'
        );

        // Empty PHP array serializes to JSON array `[]` (this matches the
        // GoodsReceivedException analog — jsonContext does not special-case
        // empty arrays). The `'{}'` literal fallback only fires when
        // json_encode itself fails (resources, recursive refs).
        $sEmpty = $obExposer::publicJsonContext([]);
        $this->assertSame('[]', $sEmpty, 'jsonContext([]) round-trips via json_encode (empty array → "[]").');

        // Confirm the `'{}'` fallback covers the encode-failure path by
        // feeding a value json_encode cannot encode (a stream resource).
        $rResource = fopen('php://memory', 'r');
        try {
            $sFallback = $obExposer::publicJsonContext(['stream' => $rResource]);
            $this->assertSame(
                '{}',
                $sFallback,
                'jsonContext() MUST return "{}" when json_encode fails (e.g. on resource).'
            );
        } finally {
            fclose($rResource);
        }
    }

    public function test_every_lang_key_resolves_to_a_string(): void
    {
        App::setLocale('en');

        foreach (self::LANG_KEYS as $sKey) {
            $sFullKey = 'logingrupa.metapixelshopaholic::lang.exception.'.$sKey;
            $sValue = Lang::get($sFullKey);

            $this->assertIsString(
                $sValue,
                "Lang key {$sFullKey} MUST resolve to a string."
            );
            $this->assertNotSame(
                '',
                $sValue,
                "Lang key {$sFullKey} MUST resolve to a non-empty string."
            );
            $this->assertStringNotContainsString(
                '::lang.',
                $sValue,
                "Lang key {$sFullKey} MUST resolve to a translated value, not the raw key path."
            );
        }
    }
}
