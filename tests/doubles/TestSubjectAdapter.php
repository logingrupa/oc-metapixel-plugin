<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * EventSubjectAdapter double whose getSubjectId reads $obSubject->iId.
 * getSiteId returns the constructor-supplied $iSiteId (null default).
 * Used by EventLogWriter + queue feature tests in downstream plans.
 */
class TestSubjectAdapter implements EventSubjectAdapter
{
    public function __construct(
        private ?int $iSiteId = null,
    ) {}

    public function getSubjectType(object $obSubject): string
    {
        return 'fake.subject';
    }

    public function getSubjectId(object $obSubject): int
    {
        if (! property_exists($obSubject, 'iId')) {
            return 0;
        }

        /** @var int $iId */
        $iId = $obSubject->iId;

        return $iId;
    }

    public function getSiteId(object $obSubject): ?int
    {
        return $this->iSiteId;
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
