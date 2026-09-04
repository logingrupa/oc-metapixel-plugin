<?php

use Logingrupa\Metapixel\Controllers\FailedEvents;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

/**
 * The FailedEvents list flags a synchronous queue so operators learn that
 * sends run in-request and are never retried.
 */
final class FailedEventsSyncQueueWarningTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelFailedEventsTable)->up();
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelFailedEventsTable)->down();
        parent::tearDown();
    }

    public function test_sync_queue_sets_the_warning_flag(): void
    {
        config(['queue.default' => 'sync']);
        $obController = new FailedEvents;
        $obController->index();

        $this->assertTrue($obController->vars['bQueueIsSync']);
    }

    public function test_queued_connection_clears_the_warning_flag(): void
    {
        config(['queue.default' => 'redis']);
        $obController = new FailedEvents;
        $obController->index();

        $this->assertFalse($obController->vars['bQueueIsSync']);
    }

    public function test_index_view_renders_the_callout_only_for_sync(): void
    {
        $sView = (string) file_get_contents(dirname(__DIR__, 3).'/controllers/failedevents/index.htm');

        $this->assertStringContainsString('bQueueIsSync', $sView);
        $this->assertStringContainsString('failed_events.sync_queue_title', $sView);
        $this->assertStringContainsString('failed_events.sync_queue_hint', $sView);
        $this->assertStringContainsString('callout-warning', $sView);
    }
}
