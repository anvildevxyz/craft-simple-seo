<?php

namespace anvildev\simpleseo\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration.
 *
 * One table only: per-site asset references. Everything else the plugin
 * stores lives in project config (structure) or the field's JSON value
 * (content). Asset IDs are environment-specific and must NOT go into project
 * config (ethercreative/seo#243).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%simpleseo_sitesettings}}')) {
            $this->createTable('{{%simpleseo_sitesettings}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'defaultSocialImageId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%simpleseo_sitesettings}}', ['siteId'], true);
            $this->addForeignKey(null, '{{%simpleseo_sitesettings}}', ['siteId'], Table::SITES, ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%simpleseo_sitesettings}}', ['defaultSocialImageId'], Table::ASSETS, ['id'], 'SET NULL');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%simpleseo_sitesettings}}');

        return true;
    }
}
