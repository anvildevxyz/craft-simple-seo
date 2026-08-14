<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\models\SiteDefaults;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\records\SiteSettings as SiteSettingsRecord;
use Craft;
use craft\models\Site;
use yii\base\Component;

/**
 * Resolves and persists per-site SEO defaults.
 *
 * Storage is deliberately split: title format and default description are
 * plugin settings (project config — portable structure), while the default
 * social image is a DB row (environment-specific asset reference). The split
 * is what keeps `allowAdminChanges: false` environments fully functional —
 * ether/seo put asset references into project config and broke exactly that
 * (ethercreative/seo#243).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SiteDefaultsService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<int, SiteDefaults> Per-request memo keyed by site ID.
     */
    private array $_memo = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the resolved defaults for a site. Zero-config installs get the
     * built-in defaults.
     */
    public function getForSite(int $siteId): SiteDefaults
    {
        if (isset($this->_memo[$siteId])) {
            return $this->_memo[$siteId];
        }

        $settings = Plugin::getInstance()->getSettings();
        $siteUid = Craft::$app->getSites()->getSiteById($siteId)?->uid;
        $config = $siteUid !== null ? ($settings->siteSettings[$siteUid] ?? []) : [];

        $titleFormat = trim((string)($config['titleFormat'] ?? ''));
        $defaultDescription = trim((string)($config['defaultDescription'] ?? ''));

        $record = SiteSettingsRecord::findOne(['siteId' => $siteId]);

        return $this->_memo[$siteId] = new SiteDefaults([
            'titleFormat' => $titleFormat !== '' ? $titleFormat : TitleFormatter::DEFAULT_FORMAT,
            'defaultDescription' => $defaultDescription !== '' ? $defaultDescription : null,
            'defaultSocialImageId' => $record?->defaultSocialImageId,
        ]);
    }

    /**
     * Persists the default social image for a site DB-side. Passing null
     * clears the reference.
     */
    public function saveDefaultSocialImageId(int $siteId, ?int $assetId): void
    {
        $record = SiteSettingsRecord::findOne(['siteId' => $siteId]);

        if ($record === null) {
            if ($assetId === null) {
                return;
            }
            $record = new SiteSettingsRecord();
            $record->siteId = $siteId;
        }

        $record->defaultSocialImageId = $assetId;
        $record->save(false);
        unset($this->_memo[$siteId]);
    }

    /**
     * Persists the project-config defaults (title format + default
     * description) for one site, leaving every other site's configuration
     * untouched — the settings screens save one site at a time. Empty values
     * remove the site's entry entirely, so resolution falls back to the
     * built-in defaults.
     *
     * On validation failure the invalid model stays attached to the plugin
     * instance, so a re-rendered form surfaces the errors and posted values.
     */
    public function saveSiteSettings(Site $site, string $titleFormat, string $defaultDescription): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $config = array_filter([
            'titleFormat' => trim($titleFormat),
            'defaultDescription' => trim($defaultDescription),
        ], static fn(string $value): bool => $value !== '');

        $siteSettings = Settings::withSiteSlice($settings->siteSettings, (string)$site->uid, $config);

        $saved = Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            $settings->projectConfigPayload(['siteSettings' => $siteSettings]),
        );

        if ($saved) {
            unset($this->_memo[(int)$site->id]);
        }

        return $saved;
    }
}
