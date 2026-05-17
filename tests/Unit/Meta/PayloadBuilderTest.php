<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class PayloadBuilderTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_envelope_has_six_top_level_event_keys(): void
    {
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver;
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            'Purchase',
            $obAdapter,
            new stdClass,
            $obResolver,
            'uuid-1',
            1700000000,
            [],
        );

        $this->assertArrayHasKey('data', $arPayload);
        $this->assertCount(1, $arPayload['data']);

        $arEvent = $arPayload['data'][0];
        $this->assertArrayHasKey('event_id', $arEvent);
        $this->assertArrayHasKey('event_time', $arEvent);
        $this->assertArrayHasKey('event_name', $arEvent);
        $this->assertArrayHasKey('action_source', $arEvent);
        $this->assertArrayHasKey('user_data', $arEvent);
        $this->assertArrayHasKey('custom_data', $arEvent);

        $this->assertSame('uuid-1', $arEvent['event_id']);
        $this->assertSame(1700000000, $arEvent['event_time']);
        $this->assertSame('Purchase', $arEvent['event_name']);
        $this->assertSame('website', $arEvent['action_source']);
        $this->assertSame('product', $arEvent['custom_data']['content_type']);
        $this->assertSame('EUR', $arEvent['custom_data']['currency']);
        $this->assertSame(10.0, $arEvent['custom_data']['value']);
        $this->assertSame(1, $arEvent['custom_data']['num_items']);
    }

    public function test_event_extras_merge_into_custom_data(): void
    {
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver;
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            'ViewContent',
            $obAdapter,
            new stdClass,
            $obResolver,
            'uuid-2',
            1700000001,
            [
                'action_key' => 'product-view:42',
                'content_type' => 'article',
            ],
        );

        $arCustom = $arPayload['data'][0]['custom_data'];
        $this->assertSame('product-view:42', $arCustom['action_key']);
        $this->assertSame('article', $arCustom['content_type'], 'extras override default product content_type');
        $this->assertSame('EUR', $arCustom['currency'], 'currency from resolver still present');
    }

    public function test_envelope_subject_agnostic_same_adapter_different_events(): void
    {
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver;
        $obBuilder = new PayloadBuilder(new UserDataHasher);

        $arPurchase = $obBuilder->buildEventPayload('Purchase', $obAdapter, new stdClass, $obResolver, 'uuid-p', 1700000000, []);
        $arViewContent = $obBuilder->buildEventPayload('ViewContent', $obAdapter, new stdClass, $obResolver, 'uuid-v', 1700000001, []);

        $arEventP = $arPurchase['data'][0];
        $arEventV = $arViewContent['data'][0];

        // user_data + action_source + custom_data identical because same adapter+resolver
        $this->assertSame($arEventP['user_data'], $arEventV['user_data']);
        $this->assertSame($arEventP['action_source'], $arEventV['action_source']);
        $this->assertSame($arEventP['custom_data'], $arEventV['custom_data']);

        // only event_name + event_id + event_time differ
        $this->assertNotSame($arEventP['event_name'], $arEventV['event_name']);
        $this->assertNotSame($arEventP['event_id'], $arEventV['event_id']);
        $this->assertNotSame($arEventP['event_time'], $arEventV['event_time']);
    }
}
