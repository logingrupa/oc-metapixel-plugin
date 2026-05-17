<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use Logingrupa\Metapixel\Classes\Exception\MissingCapiTokenException;
use Logingrupa\Metapixel\Classes\Exception\MissingPixelConfigException;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class ExceptionHierarchyTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_meta_pixel_exception_is_abstract_runtime_exception(): void
    {
        $obReflect = new ReflectionClass(MetaPixelException::class);

        $this->assertTrue($obReflect->isAbstract(), 'MetaPixelException must be abstract');
        $this->assertTrue($obReflect->isSubclassOf(RuntimeException::class));
    }

    public function test_missing_pixel_config_extends_base_and_carries_context(): void
    {
        $arContext = ['pixel_id' => '', 'site_id' => 1];
        $obException = new MissingPixelConfigException('empty pixel id', 0, null, $arContext);

        $this->assertInstanceOf(MetaPixelException::class, $obException);
        $this->assertSame($arContext, $obException->getContext());
    }

    public function test_missing_capi_token_extends_base(): void
    {
        $obException = new MissingCapiTokenException('empty capi token');

        $this->assertInstanceOf(MetaPixelException::class, $obException);
        $this->assertSame([], $obException->getContext());
    }

    public function test_meta_api_transient_carries_http_status_and_context(): void
    {
        $arContext = ['url' => 'https://graph.facebook.com/v23.0/123/events'];
        $obException = new MetaApiTransientException('502 bad gateway', 502, null, $arContext);

        $this->assertInstanceOf(MetaPixelException::class, $obException);
        $this->assertSame(502, $obException->getHttpStatus());
        $this->assertSame($arContext, $obException->getContext());
    }

    public function test_meta_api_permanent_carries_http_status_and_context(): void
    {
        $arContext = ['response' => ['error' => ['code' => 100]]];
        $obException = new MetaApiPermanentException('400 bad request', 400, null, $arContext);

        $this->assertInstanceOf(MetaPixelException::class, $obException);
        $this->assertSame(400, $obException->getHttpStatus());
        $this->assertSame($arContext, $obException->getContext());
    }
}
