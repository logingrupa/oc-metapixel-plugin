<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Logingrupa\Metapixelshopaholic\Classes\Helper\SiteResolver;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;

/**
 * Phase 3.1-07 REFAC-12 RED spec.
 *
 * Locks forOrder(Order $obOrder): ?int contract — Order-scoped resolver
 * reads $obOrder->getAttribute('site_id'). Deterministic across writer
 * (admin/queue) + reader (frontend) contexts; closes 2026-05-14 prod
 * bug (orders 29802 + 29803 on new.nailscosmetics.lv).
 *
 * Three invariants:
 *   1. site_id stamped int → returns int.
 *   2. site_id NULL → returns null (single-site / pre-Lovata-v1.33 Order).
 *   3. site_id non-numeric attr → returns null (defensive narrow).
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-12
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/SiteResolver.php
 */
final class SiteResolverTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        OrderFixtures::provisionHermeticOfferProductTables();
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        parent::tearDown();
    }

    public function test_for_order_returns_int_when_order_has_site_id(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->site_id = 2;
        $obOrder->save();

        $obOrder = $obOrder->fresh();

        $this->assertSame(
            2,
            SiteResolver::forOrder($obOrder),
            'forOrder MUST return Order.site_id verbatim when stamped (multi-site write path).',
        );
    }

    public function test_for_order_returns_null_when_order_site_id_null(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        // site_id never set — single-site install OR pre-Lovata-v1.33 Order.

        $this->assertNull(
            SiteResolver::forOrder($obOrder),
            'forOrder MUST return null when Order.site_id IS NULL (single-site path).',
        );
    }

    public function test_for_order_returns_null_when_attribute_non_numeric(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        // Force non-numeric attr — bypass save() since DB int column rejects.
        // Defensive narrow target: future Lovata cast change OR fixture mock.
        $obOrder->setAttribute('site_id', 'banana');

        $this->assertNull(
            SiteResolver::forOrder($obOrder),
            'forOrder MUST return null on non-numeric attribute (is_numeric narrow).',
        );
    }
}
