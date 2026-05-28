<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-04 Task 4. Will inherit 10 invariants from EventSubjectAdapterContractTestCase.
 */
#[Group('adapter')]
final class ShopaholicProductAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        $this->fail('GREEN in plan 06-04 — Task 4 — Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter not yet shipped');
    }

    protected function makeSubject(): object
    {
        $this->fail('GREEN in plan 06-04 — Task 4 — Product fixture + product-site-relation pivot not yet built');
    }
}
