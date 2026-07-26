<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\wink\Plugin;

/**
 * Verifies the test harness boots Craft with the Wink plugin installed and
 * its schema migrated.
 */
final class SmokeTest extends TestCase
{
    public function testPluginIsInstalledAndServicesResolve(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertNotNull($plugin, 'The Wink plugin should be loaded');
        $this->assertInstanceOf(\justinholtweb\wink\services\ExperimentService::class, $plugin->experiments);
        $this->assertInstanceOf(\justinholtweb\wink\services\TrackingService::class, $plugin->tracking);
        $this->assertInstanceOf(\justinholtweb\wink\services\StatsService::class, $plugin->stats);
        $this->assertInstanceOf(\justinholtweb\wink\services\AssignmentService::class, $plugin->assignment);
    }

    public function testSchemaTablesExist(): void
    {
        $schema = Craft::$app->getDb()->getSchema();
        $schema->refresh();

        foreach (['wink_experiments', 'wink_variants', 'wink_goals', 'wink_events'] as $table) {
            $this->assertNotNull(
                $schema->getTableSchema('{{%' . $table . '}}'),
                "Table $table should have been created by the install migration",
            );
        }
    }
}
