<?php

namespace Logingrupa\Metapixel\Classes\Adapter;

use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException;

/**
 * Adapter registry — service-container singleton mapping subject classes to
 * their EventSubjectAdapter implementations.
 *
 * Binding (Plugin::register()):
 *     $this->app->singleton(AdapterRegistry::class);
 *
 * Test-swap idiom (fresh instance per test):
 *     $this->app->instance(AdapterRegistry::class, new AdapterRegistry);
 *
 * Resolution semantics:
 *   1. Direct class hit — array key lookup, O(1).
 *   2. Hierarchy walk — foreach map in insertion order, return on first
 *      is_a() match against the registered subject class.
 *   3. Miss — return null.
 *
 * When two adapters are registered for sibling classes that share an
 * ancestor, the foreach insertion order determines which wins. There is no
 * explicit priority API in v2.0; explicit priority is deferred until a real
 * sibling-collision use case surfaces.
 *
 * Adapters MUST have parameterless constructors so the register-time
 * alias-index population (App::make($sAdapterClass)) cannot fail.
 */
final class AdapterRegistry
{
    /** @var array<string, class-string<EventSubjectAdapter>> */
    private array $arAdapterMap = [];

    /** @var array<string, class-string<EventSubjectAdapter>> */
    private array $arAliasMap = [];

    /**
     * Register $sAdapterClass for $sSubjectClass. Idempotent — re-registering
     * the same pair is a no-op. Re-registering with a different adapter
     * silently overwrites (registration order wins).
     *
     * @throws InvalidArgumentException when $sAdapterClass does not implement EventSubjectAdapter
     */
    public function register(string $sSubjectClass, string $sAdapterClass): void
    {
        if (! is_subclass_of($sAdapterClass, EventSubjectAdapter::class)) {
            throw new InvalidArgumentException(
                "Adapter {$sAdapterClass} must implement ".EventSubjectAdapter::class,
            );
        }
        $this->arAdapterMap[$sSubjectClass] = $sAdapterClass;

        // Build the alias index at register-time. Per A3 (RESEARCH §5), every
        // shipping adapter's getSubjectType() returns a constant string and
        // ignores its argument — passing new \stdClass is safe. Third-party
        // adapters that conditional-dispatch on $obSubject inside
        // getSubjectType violate the alias-opacity contract documented on
        // EventSubjectAdapter::getSubjectType.
        /** @var EventSubjectAdapter $obAdapter */
        $obAdapter = App::make($sAdapterClass);
        $sAlias = $obAdapter->getSubjectType(new \stdClass);
        $this->arAliasMap[$sAlias] = $sAdapterClass;
    }

    /**
     * List of registered adapter class FQNs, in insertion order.
     *
     * @return list<class-string<EventSubjectAdapter>>
     */
    public function all(): array
    {
        return array_values($this->arAdapterMap);
    }

    /**
     * Resolve an EventSubjectAdapter for $obSubject. Returns null on miss.
     */
    public function resolveFor(object $obSubject): ?EventSubjectAdapter
    {
        $sClass = get_class($obSubject);

        if (isset($this->arAdapterMap[$sClass])) {
            /** @var EventSubjectAdapter $obAdapter */
            $obAdapter = App::make($this->arAdapterMap[$sClass]);

            return $obAdapter;
        }

        foreach ($this->arAdapterMap as $sRegisteredClass => $sAdapterClass) {
            if (is_a($obSubject, $sRegisteredClass)) {
                /** @var EventSubjectAdapter $obAdapter */
                $obAdapter = App::make($sAdapterClass);

                return $obAdapter;
            }
        }

        return null;
    }

    /**
     * Resolve an adapter by its own class FQN. Used by SendCapiEvent::handle
     * to rehydrate the adapter instance after queue serialization.
     */
    public function resolveByClass(string $sAdapterClass): EventSubjectAdapter
    {
        /** @var EventSubjectAdapter $obAdapter */
        $obAdapter = App::make($sAdapterClass);

        return $obAdapter;
    }

    /**
     * Resolve adapter class FQN by opaque subject_type alias. Used by the
     * hybrid AJAX path to translate an untrusted JS-supplied alias string
     * into a registered adapter class FQN — guards against FQN-injection
     * because the alias map is byte-for-byte the operator-registered set.
     *
     * @return class-string<EventSubjectAdapter>
     *
     * @throws UnknownSubjectTypeException
     */
    public function resolveByAlias(string $sAlias): string
    {
        if (! isset($this->arAliasMap[$sAlias])) {
            throw new UnknownSubjectTypeException(
                "No adapter registered for subject_type alias '{$sAlias}'",
            );
        }

        return $this->arAliasMap[$sAlias];
    }
}
