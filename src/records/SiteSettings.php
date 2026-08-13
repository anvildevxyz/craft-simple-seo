<?php

namespace anvildev\simpleseo\records;

use craft\db\ActiveRecord;

/**
 * Per-site settings row. Holds only environment-specific references (the
 * default social image asset ID) — everything portable lives in project
 * config via the plugin settings model.
 *
 * @property int $id
 * @property int $siteId
 * @property int|null $defaultSocialImageId
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SiteSettings extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%simpleseo_sitesettings}}';
    }
}
