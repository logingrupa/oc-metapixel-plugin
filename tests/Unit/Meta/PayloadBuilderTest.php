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

    public function test_empty_content_ids_omits_content_type_and_content_ids(): void
    {
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver(arContentIds: [], arContents: [], iNumItems: 0);
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            'PageView',
            $obAdapter,
            new stdClass,
            $obResolver,
            'uuid-pv',
            1700000002,
            [],
        );

        $arCustom = $arPayload['data'][0]['custom_data'];
        $this->assertFalse(array_key_exists('content_type', $arCustom), 'contentless PageView omits content_type');
        $this->assertFalse(array_key_exists('content_ids', $arCustom), 'contentless PageView omits content_ids');
        $this->assertArrayHasKey('currency', $arCustom);
        $this->assertArrayHasKey('value', $arCustom);
    }

    public function test_zero_value_contentless_event_drops_value_currency_num_items_contents(): void
    {
        // PageView (and any zero-value subject): resolver returns no content_ids,
        // value 0.0, num_items 0, contents [] — the CAPI custom_data MUST carry
        // no junk value:0 / num_items:0 / empty contents that Meta flags in the
        // Test Events panel. currency is meaningless without value, so it drops
        // with it.
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver(
            arContentIds: [],
            fValue: 0.0,
            arContents: [],
            iNumItems: 0,
        );
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            'PageView',
            $obAdapter,
            new stdClass,
            $obResolver,
            'uuid-zero',
            1700000004,
            [],
        );

        $arCustom = $arPayload['data'][0]['custom_data'];
        $this->assertFalse(array_key_exists('value', $arCustom), 'zero value dropped');
        $this->assertFalse(array_key_exists('currency', $arCustom), 'currency dropped when value dropped');
        $this->assertFalse(array_key_exists('num_items', $arCustom), 'zero num_items dropped');
        $this->assertFalse(array_key_exists('contents', $arCustom), 'empty contents dropped');
        $this->assertFalse(array_key_exists('content_ids', $arCustom), 'empty content_ids omitted');
        $this->assertFalse(array_key_exists('content_type', $arCustom), 'content_type omitted');
        $this->assertSame([], $arCustom, 'contentless zero-value custom_data is empty');
    }

    public function test_non_empty_content_ids_retains_product_shape(): void
    {
        $obAdapter = new FakeAdapter;
        $obResolver = new FakeValueResolver;
        $arPayload = (new PayloadBuilder(new UserDataHasher))->buildEventPayload(
            'ViewContent',
            $obAdapter,
            new stdClass,
            $obResolver,
            'uuid-vc',
            1700000003,
            [],
        );

        $arCustom = $arPayload['data'][0]['custom_data'];
        $this->assertSame('product', $arCustom['content_type']);
        $this->assertSame(['SKU-1'], $arCustom['content_ids']);
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
