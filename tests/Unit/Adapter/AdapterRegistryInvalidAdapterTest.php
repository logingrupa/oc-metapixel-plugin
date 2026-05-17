<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class AdapterRegistryInvalidAdapterTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_register_throws_when_adapter_class_does_not_implement_event_subject_adapter(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);

        $this->expectException(InvalidArgumentException::class);

        $obRegistry->register('SomeSubjectClass', stdClass::class);
    }
}
