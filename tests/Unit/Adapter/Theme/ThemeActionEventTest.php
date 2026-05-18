<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Theme;

use InvalidArgumentException;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-01 — ThemeActionEvent value object happy path + 3 validation throws
 * + synthetic-id determinism + payload round-trip.
 */
#[Group('adapter')]
final class ThemeActionEventTest extends MetapixelTestCase
{
    public function test_from_array_builds_value_object_with_synthetic_id_positive_int(): void
    {
        $obEvent = ThemeActionEvent::fromArray([
            'name' => 'ViewContent',
            'action_key' => 'product-view:42',
        ]);

        $this->assertSame('ViewContent', $obEvent->sEventName);
        $this->assertSame('product-view:42', $obEvent->sActionKey);
        $this->assertIsInt($obEvent->iSyntheticId);
        $this->assertGreaterThan(0, $obEvent->iSyntheticId);
    }

    public function test_from_array_throws_on_missing_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name is required');

        ThemeActionEvent::fromArray(['action_key' => 'x']);
    }

    public function test_from_array_throws_on_missing_action_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('action_key is required');

        ThemeActionEvent::fromArray(['name' => 'ViewContent']);
    }

    public function test_from_array_throws_on_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThemeActionEvent::fromArray(['name' => '', 'action_key' => 'x']);
    }

    public function test_synthetic_id_deterministic_for_same_action_key(): void
    {
        $obA = ThemeActionEvent::fromArray(['name' => 'AddToCart', 'action_key' => 'cart-add:7']);
        $obB = ThemeActionEvent::fromArray(['name' => 'AddToCart', 'action_key' => 'cart-add:7']);

        $this->assertSame($obA->iSyntheticId, $obB->iSyntheticId);
    }

    public function test_synthetic_id_different_for_different_action_keys(): void
    {
        $obA = ThemeActionEvent::fromArray(['name' => 'AddToCart', 'action_key' => 'cart-add:7']);
        $obB = ThemeActionEvent::fromArray(['name' => 'AddToCart', 'action_key' => 'cart-add:8']);

        $this->assertNotSame($obA->iSyntheticId, $obB->iSyntheticId);
    }

    public function test_payload_round_trip_includes_all_input_keys(): void
    {
        $obEvent = ThemeActionEvent::fromArray([
            'name' => 'X',
            'action_key' => 'y',
            'value' => 12.5,
            'currency' => 'EUR',
        ]);

        $this->assertSame(12.5, $obEvent->arPayload['value']);
        $this->assertSame('EUR', $obEvent->arPayload['currency']);
    }
}
