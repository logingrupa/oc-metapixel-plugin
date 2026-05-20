<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Controllers\FailedEvents;
use Logingrupa\Metapixel\Models\FailedEvent;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddDedupColumnsToFailedEvents;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Symfony\Component\Yaml\Yaml;

/**
 * Wave 0 RED — fails until plan 04-04 production code ships.
 *
 * FAIL-01 — declarative ListController shape on Controllers\FailedEvents.
 * Asserts $implement contains only Backend.Behaviors.ListController (D-08
 * lock — no FormController), config_list.yaml declares the FailedEvent model
 * + filter scopes, and columns.yaml declares all 11 columns including the
 * dedup additions from this plan.
 */
final class FailedEventsListTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_failed_events';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelFailedEventsTable)->up();
        (new AddDedupColumnsToFailedEvents)->up();

        FailedEvent::insert([
            ['event_id' => 'uuid-a', 'event_name' => 'Purchase', 'adapter_type' => 'A', 'payload' => '{"data":[]}', 'attempts' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
            ['event_id' => 'uuid-b', 'event_name' => 'Lead', 'adapter_type' => 'B', 'payload' => '{"data":[]}', 'attempts' => 2, 'created_at' => '2026-02-01 10:00:00', 'updated_at' => '2026-02-01 10:00:00'],
            ['event_id' => 'uuid-c', 'event_name' => 'Purchase', 'adapter_type' => 'A', 'payload' => '{"data":[]}', 'attempts' => 3, 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        (new AddDedupColumnsToFailedEvents)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(FailedEvents::class));
    }

    public function test_controller_implements_listcontroller_behavior(): void
    {
        $obReflect = new \ReflectionClass(FailedEvents::class);
        $arImplement = $obReflect->getDefaultProperties()['implement'] ?? null;

        $this->assertIsArray($arImplement);
        $this->assertContains('Backend.Behaviors.ListController', $arImplement);
    }

    public function test_controller_does_not_implement_formcontroller(): void
    {
        $obReflect = new \ReflectionClass(FailedEvents::class);
        $arImplement = $obReflect->getDefaultProperties()['implement'] ?? [];

        foreach ((array) $arImplement as $sBehavior) {
            $this->assertStringNotContainsString(
                'FormController',
                (string) $sBehavior,
                'D-08 lock: FormController MUST NOT be declared — FailedEvents is read-only audit UI.'
            );
        }
    }

    public function test_controller_declares_list_config_yaml(): void
    {
        $obReflect = new \ReflectionClass(FailedEvents::class);
        $arDefaults = $obReflect->getDefaultProperties();

        $this->assertSame('config_list.yaml', $arDefaults['listConfig'] ?? null);
    }

    public function test_config_list_yaml_declares_failedevent_model_class(): void
    {
        $sYamlPath = base_path('plugins/logingrupa/metapixel/controllers/failedevents/config_list.yaml');
        $this->assertFileExists($sYamlPath);

        $arConfig = Yaml::parseFile($sYamlPath);
        $this->assertSame(FailedEvent::class, $arConfig['modelClass'] ?? null);
    }

    public function test_columns_yaml_declares_all_eleven_columns_including_dedup(): void
    {
        $sYamlPath = base_path('plugins/logingrupa/metapixel/models/failedevent/columns.yaml');
        $this->assertFileExists($sYamlPath);

        $arConfig = Yaml::parseFile($sYamlPath);
        $arColumns = $arConfig['columns'] ?? [];
        $arExpected = [
            'id',
            'event_id',
            'event_name',
            'adapter_type',
            'http_status',
            'attempts',
            'graph_error',
            'dedup_pct',
            'emq',
            'dedup_checked_at',
            'created_at',
        ];
        foreach ($arExpected as $sColumn) {
            $this->assertArrayHasKey(
                $sColumn,
                $arColumns,
                sprintf('columns.yaml must declare "%s"', $sColumn)
            );
        }
    }

    public function test_config_list_yaml_declares_three_filters(): void
    {
        $sYamlPath = base_path('plugins/logingrupa/metapixel/controllers/failedevents/config_list.yaml');
        $arConfig = Yaml::parseFile($sYamlPath);

        $arScopes = $arConfig['filterConfig']['scopes'] ?? [];
        $this->assertArrayHasKey('event_name', $arScopes);
        $this->assertArrayHasKey('adapter_type', $arScopes);
        $this->assertArrayHasKey('created_at', $arScopes);
    }
}
