<?php

use Logingrupa\Metapixel\Classes\Meta\PayloadRequestContext;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * PayloadRequestContext::merge fills empty passthrough user_data and sets the
 * top-level event_source_url without touching anything the subject supplied.
 */
final class PayloadRequestContextTest extends MetapixelTestCase
{
    /** @return array<string, mixed> */
    private function envelope(array $arUserData, array $arExtra = []): array
    {
        return ['data' => [array_merge([
            'event_id' => 'e1',
            'event_time' => 1,
            'event_name' => 'ViewContent',
            'action_source' => 'website',
            'user_data' => $arUserData,
            'custom_data' => [],
        ], $arExtra)]];
    }

    public function test_fills_empty_user_data_and_sets_event_source_url(): void
    {
        $arPayload = PayloadRequestContext::merge(
            $this->envelope(['em' => 'hash', 'fbp' => null, 'fbc' => '']),
            ['fbp' => 'fb.1.1.a', 'fbc' => 'fb.1.2.b', 'client_ip_address' => '203.0.113.1', 'client_user_agent' => null],
            'https://shop.test/p/gel',
        );

        $arEnvelope = $arPayload['data'][0];
        $this->assertSame('hash', $arEnvelope['user_data']['em']);
        $this->assertSame('fb.1.1.a', $arEnvelope['user_data']['fbp']);
        $this->assertSame('fb.1.2.b', $arEnvelope['user_data']['fbc']);
        $this->assertSame('203.0.113.1', $arEnvelope['user_data']['client_ip_address']);
        $this->assertArrayNotHasKey('client_user_agent', $arEnvelope['user_data']);
        $this->assertSame('https://shop.test/p/gel', $arEnvelope['event_source_url']);
        $this->assertSame('e1', $arEnvelope['event_id']);
    }

    public function test_subject_supplied_values_win(): void
    {
        $arPayload = PayloadRequestContext::merge(
            $this->envelope(['fbc' => 'fb.1.9.subject'], ['event_source_url' => 'https://shop.test/original']),
            ['fbc' => 'fb.1.1.request'],
            'https://shop.test/request',
        );

        $this->assertSame('fb.1.9.subject', $arPayload['data'][0]['user_data']['fbc']);
        $this->assertSame('https://shop.test/original', $arPayload['data'][0]['event_source_url']);
    }

    public function test_null_url_leaves_envelope_without_event_source_url(): void
    {
        $arPayload = PayloadRequestContext::merge($this->envelope([]), [], null);

        $this->assertArrayNotHasKey('event_source_url', $arPayload['data'][0]);
        $this->assertSame([], $arPayload['data'][0]['user_data']);
    }

    public function test_missing_user_data_key_is_created(): void
    {
        $arPayload = PayloadRequestContext::merge(['data' => [['event_id' => 'e1']]], ['fbp' => 'fb.1.1.a'], null);

        $this->assertSame(['fbp' => 'fb.1.1.a'], $arPayload['data'][0]['user_data']);
    }

    public function test_malformed_envelope_is_returned_untouched(): void
    {
        $this->assertSame(['data' => 'junk'], PayloadRequestContext::merge(['data' => 'junk'], ['fbp' => 'x'], 'https://shop.test/'));
        $this->assertSame([], PayloadRequestContext::merge([], ['fbp' => 'x'], 'https://shop.test/'));
    }
}
