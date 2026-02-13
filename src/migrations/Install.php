<?php

namespace jholt\wink\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%wink_experiments}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text(),
            'experimentStatus' => $this->string(20)->notNull()->defaultValue('draft'),
            'trafficPercent' => $this->tinyInteger()->unsigned()->notNull()->defaultValue(100),
            'startDate' => $this->dateTime(),
            'endDate' => $this->dateTime(),
            'winnerVariantId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%wink_variants}}', [
            'id' => $this->primaryKey(),
            'experimentId' => $this->integer()->notNull(),
            'handle' => $this->string(255)->notNull(),
            'title' => $this->string(255)->notNull(),
            'content' => $this->text(),
            'weight' => $this->tinyInteger()->unsigned()->notNull()->defaultValue(50),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'isControl' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%wink_goals}}', [
            'id' => $this->primaryKey(),
            'experimentId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'goalType' => $this->string(50)->notNull()->defaultValue('pageview'),
            'goalTarget' => $this->string(500),
            'isPrimary' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%wink_events}}', [
            'id' => $this->bigPrimaryKey(),
            'experimentId' => $this->integer()->notNull(),
            'variantId' => $this->integer()->notNull(),
            'goalId' => $this->integer(),
            'visitorId' => $this->string(64)->notNull(),
            'eventType' => $this->string(20)->notNull(),
            'url' => $this->string(500),
            'referrer' => $this->string(500),
            'userAgent' => $this->string(500),
            'ipAddress' => $this->string(45),
            'metadata' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        // Foreign keys
        $this->addForeignKey(null, '{{%wink_experiments}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%wink_variants}}', ['experimentId'], '{{%wink_experiments}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%wink_goals}}', ['experimentId'], '{{%wink_experiments}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%wink_events}}', ['experimentId'], '{{%wink_experiments}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%wink_events}}', ['variantId'], '{{%wink_variants}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%wink_events}}', ['goalId'], '{{%wink_goals}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%wink_experiments}}', ['winnerVariantId'], '{{%wink_variants}}', ['id'], 'SET NULL');

        // Indexes
        $this->createIndex(null, '{{%wink_experiments}}', ['handle'], true);
        $this->createIndex(null, '{{%wink_experiments}}', ['experimentStatus']);
        $this->createIndex(null, '{{%wink_variants}}', ['experimentId', 'handle'], true);
        $this->createIndex(null, '{{%wink_goals}}', ['experimentId', 'handle'], true);
        $this->createIndex(null, '{{%wink_events}}', ['experimentId', 'variantId']);
        $this->createIndex(null, '{{%wink_events}}', ['experimentId', 'eventType']);
        $this->createIndex(null, '{{%wink_events}}', ['visitorId', 'experimentId']);
        $this->createIndex(null, '{{%wink_events}}', ['dateCreated']);
        $this->createIndex(null, '{{%wink_events}}', ['goalId']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%wink_events}}');
        $this->dropTableIfExists('{{%wink_goals}}');
        $this->dropTableIfExists('{{%wink_variants}}');
        $this->dropTableIfExists('{{%wink_experiments}}');

        return true;
    }
}
