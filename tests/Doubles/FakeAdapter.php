<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;

/**
 * Fluent EventSubjectAdapter double. Instantiate fresh per test (no shared
 * mutable state). Autoload-dev only — never autoloads in production.
 */
final class FakeAdapter implements EventSubjectAdapter
{
    private string $sSubjectType = 'fake.subject';

    private int $iSubjectId = 1;

    private ?int $iSiteId = null;

    private ?string $sSecretKey = null;

    /** @var array<string, ?string> */
    private array $arUserData = [
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

    /** @var array<string, list<string>> */
    private array $arSupportedEvents = ['Purchase' => ['capi', 'pixel']];

    private ?ValueResolver $obValueResolver = null;

    public function withSubjectType(string $sType): self
    {
        $this->sSubjectType = $sType;

        return $this;
    }

    public function withSubjectId(int $iId): self
    {
        $this->iSubjectId = $iId;

        return $this;
    }

    public function withSiteId(?int $iSiteId): self
    {
        $this->iSiteId = $iSiteId;

        return $this;
    }

    public function withSecretKey(?string $sKey): self
    {
        $this->sSecretKey = $sKey;

        return $this;
    }

    /**
     * @param  array<string, ?string>  $arUserData
     */
    public function withUserData(array $arUserData): self
    {
        $this->arUserData = array_merge($this->arUserData, $arUserData);

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $arSupported
     */
    public function withSupportedEvents(array $arSupported): self
    {
        $this->arSupportedEvents = $arSupported;

        return $this;
    }

    public function withValueResolver(ValueResolver $obResolver): self
    {
        $this->obValueResolver = $obResolver;

        return $this;
    }

    public function getSubjectType(object $obSubject): string
    {
        return $this->sSubjectType;
    }

    public function getSubjectId(object $obSubject): int
    {
        return $this->iSubjectId;
    }

    public function getSiteId(object $obSubject): ?int
    {
        return $this->iSiteId;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        return $this->sSecretKey;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return $this->obValueResolver ?? new FakeValueResolver;
    }

    public function getUserData(object $obSubject): array
    {
        return $this->arUserData;
    }

    public function getSupportedEvents(): array
    {
        return $this->arSupportedEvents;
    }
}
