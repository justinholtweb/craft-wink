<?php

namespace justinholtweb\wink\migrations;

use craft\db\Migration;

/**
 * Lets an experiment choose its own delivery (server-side or cache-safe) instead of following the
 * plugin's Delivery setting. Null — every existing experiment — means "follow the setting".
 */
class m261008_000000_add_delivery_mode extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%wink_experiments}}', 'deliveryMode')) {
            $this->addColumn('{{%wink_experiments}}', 'deliveryMode', $this->string(20));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%wink_experiments}}', 'deliveryMode')) {
            $this->dropColumn('{{%wink_experiments}}', 'deliveryMode');
        }

        return true;
    }
}
