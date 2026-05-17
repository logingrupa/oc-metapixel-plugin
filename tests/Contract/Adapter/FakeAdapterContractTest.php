<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;

/**
 * Proves the EventSubjectAdapterContractTestCase base passes against FakeAdapter
 * (ADAP-11 smoke). Subclasses Phase 3 ShopaholicOrderAdapterContractTest +
 * ThemeActionAdapterContractTest will follow the same pattern.
 */
final class FakeAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new FakeAdapter;
    }

    protected function makeSubject(): object
    {
        return new \stdClass;
    }
}
