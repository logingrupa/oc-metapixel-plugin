<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * Immutable minimal EventSubjectAdapter for hook-isolation unit tests.
 * Same default shape as FakeAdapter; no fluent setters.
 */
final class FakeStubAdapter implements EventSubjectAdapter
{
    public function getSubjectType(object $obSubject): string
    {
        return 'fake.subject';
    }

    public function getSubjectId(object $obSubject): int
    {
        return 1;
    }

    public function getSiteId(object $obSubject): ?int
    {
        return null;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        return null;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return new FakeValueResolver;
    }

    public function getUserData(object $obSubject): array
    {
        return [
            'em' => null,
            'ph' => null,
            'fn' => null,
            'ln' => null,
            'ct' => null,
            'st' => null,
            'zp' => null,
            'country' => null,
            'external_id' => null,
            'fbp' => null,
            'fbc' => null,
            'client_ip_address' => null,
            'client_user_agent' => null,
        ];
    }

    public function getSupportedEvents(): array
    {
        return ['Purchase' => ['capi', 'pixel']];
    }
}
