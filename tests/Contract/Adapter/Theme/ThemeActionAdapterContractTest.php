<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter\Theme;

use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-02 contract proof — ThemeActionAdapter satisfies all 10 invariants
 * of EventSubjectAdapterContractTestCase. No hermetic schema needed; the
 * subject is a value object, not an Eloquent model.
 */
#[Group('adapter')]
final class ThemeActionAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function makeAdapter(): EventSubjectAdapter
    {
        return new ThemeActionAdapter;
    }

    protected function makeSubject(): object
    {
        return ThemeActionEvent::fromArray([
            'name' => 'ViewContent',
            'action_key' => 'product-view:42',
            'site_id' => 1,
            'value' => 12.50,
            'currency' => 'EUR',
            'content_ids' => ['SKU-42'],
        ]);
    }
}
