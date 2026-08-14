<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\records\SiteSettings as SiteSettingsRecord;
use Craft;
use craft\elements\User;
use IntegrationTester;

/**
 * Per-site defaults: zero-config behavior, project-config storage for the
 * portable settings, DB-side storage for asset references (the ether #243
 * fix), and the preview picking the configured format up.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SiteDefaultsCest
{
    // Public Methods
    // =========================================================================

    /**
     * A fresh install resolves sensible defaults with zero configuration.
     */
    public function zeroConfigResolvesDefaults(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $defaults = Plugin::getInstance()->getSiteDefaults()->getForSite((int)$site->id);

        $I->assertSame(TitleFormatter::DEFAULT_FORMAT, $defaults->titleFormat);
        $I->assertNull($defaults->defaultDescription);
        $I->assertNull($defaults->defaultSocialImageId);
    }

    /**
     * Saved plugin settings (title format + default description) resolve per
     * site, and a bare {title} format is honored as "no site name".
     */
    public function configuredFormatAndDescriptionResolve(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $I->seedPluginSettings([
            'siteSettings' => [
                $site->uid => [
                    'titleFormat' => '{title} | {siteName}',
                    'defaultDescription' => 'Site-wide default description.',
                ],
            ],
        ]);

        $defaults = $plugin->getSiteDefaults()->getForSite((int)$site->id);
        $I->assertSame('{title} | {siteName}', $defaults->titleFormat);
        $I->assertSame('Site-wide default description.', $defaults->defaultDescription);

        $I->assertSame(
            'Page | ' . $site->name,
            TitleFormatter::format(null, 'Page', (string)$site->name, $defaults->titleFormat),
        );
    }

    /**
     * The default social image is stored as a DB row — and never appears in
     * the project-config representation of the plugin settings
     * (ethercreative/seo#243 regression).
     */
    public function socialImageIsStoredDbSideNotInProjectConfig(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        // save(false) stores the null reference directly, bypassing validation.
        $record = new SiteSettingsRecord();
        $record->siteId = (int)$site->id;
        $record->defaultSocialImageId = null;
        $record->save(false);

        $plugin->getSiteDefaults()->saveDefaultSocialImageId((int)$site->id, null);
        $I->assertNull($plugin->getSiteDefaults()->getForSite((int)$site->id)->defaultSocialImageId);

        // The settings model (what goes to project config) has no image key at all.
        $serialized = json_encode($plugin->getSettings()->toArray());
        $I->assertStringNotContainsString('SocialImage', (string)$serialized);
        $I->assertStringNotContainsString('socialImage', (string)$serialized);
    }

    /**
     * Settings validation rejects a title format missing {title} through the
     * real savePluginSettings pipeline.
     */
    public function invalidFormatIsRejectedOnSave(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [
                $site->uid => ['titleFormat' => 'no tokens here'],
            ],
        ]);

        $I->assertFalse($saved);
        $I->assertNotEmpty($plugin->getSettings()->getErrors());
    }

    /**
     * A rejected title format leaves the DB-side social image untouched.
     *
     * The two halves of this screen commit to different stores, and the
     * image write used to run first and unconditionally — so a save that
     * failed validation still changed the image while telling the user
     * nothing had been saved. Driven through the real controller action,
     * because the ordering inside it IS the fix.
     */
    public function rejectedTitleFormatLeavesTheSocialImageUntouched(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $admin = User::find()->admin()->one();
        $I->assertInstanceOf(User::class, $admin, 'test install must have an admin');
        Craft::$app->getUser()->setIdentity($admin);

        // A known starting image, so "untouched" is observable. Clearing it is
        // what the broken ordering would have done, since the post below
        // carries no image.
        $asset = $I->ensureAsset();
        $plugin->getSiteDefaults()->saveDefaultSocialImageId((int)$site->id, (int)$asset->id);

        $I->postToAction('simple-seo/settings/save', [
            'siteUid' => $site->uid,
            'titleFormat' => 'missing the token',
            'defaultDescription' => '',
            'defaultSocialImage' => [],
        ]);

        $I->assertSame(
            (int)$asset->id,
            $plugin->getSiteDefaults()->getForSite((int)$site->id)->defaultSocialImageId,
            'the image must not change when the title format is rejected',
        );
        // Assert against project config, not the in-memory model: a rejected
        // save deliberately leaves the invalid value attached to the model so
        // the re-rendered form can show it back with its error.
        $persisted = $I->persistedPluginSettings();
        $I->assertArrayNotHasKey($site->uid, $persisted['siteSettings'] ?? []);
    }

    /**
     * The per-site save writes one site's slice and leaves every other
     * site's entry AND the sitemap setting groups untouched —
     * savePluginSettings() only keeps submitted top-level keys in project
     * config, so a partial payload would silently drop them. Clearing both
     * values removes the site's entry entirely.
     */
    public function perSiteSaveMergesAndClears(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $otherUid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $seeded = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [$otherUid => ['titleFormat' => '{title} @ Other']],
            'sitemapExcludedSections' => [$otherUid => ['some-section-uid']],
            'sitemapPriorities' => [$site->uid => ['some-section-uid' => '0.7']],
            'robotsTxt' => [$otherUid => "User-agent: *\nDisallow: /private"],
            'availableSubfields' => ['preview', 'title'],
        ]);
        $I->assertTrue($seeded, json_encode($plugin->getSettings()->getErrors()) ?: '');

        // Prime the resolution memo so the save's invalidation is exercised.
        $I->assertSame(TitleFormatter::DEFAULT_FORMAT, $plugin->getSiteDefaults()->getForSite((int)$site->id)->titleFormat);

        $saved = $plugin->getSiteDefaults()->saveSiteSettings($site, ' {title} | {siteName} ', ' Site description. ');
        $I->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');

        $settings = $plugin->getSettings();
        $I->assertSame(
            ['titleFormat' => '{title} | {siteName}', 'defaultDescription' => 'Site description.'],
            $settings->siteSettings[$site->uid] ?? null,
        );
        $I->assertSame(['titleFormat' => '{title} @ Other'], $settings->siteSettings[$otherUid] ?? null);
        $I->assertSame('{title} | {siteName}', $plugin->getSiteDefaults()->getForSite((int)$site->id)->titleFormat);

        // The ride-along has to be checked in project config: savePluginSettings()
        // never clears the in-memory model, so a model assertion here passes even
        // when the group has just been deleted from project.yaml.
        $persisted = $I->persistedPluginSettings();
        $I->assertSame([$otherUid => ['some-section-uid']], $persisted['sitemapExcludedSections'] ?? null);
        $I->assertSame([$site->uid => ['some-section-uid' => '0.7']], $persisted['sitemapPriorities'] ?? null);
        $I->assertSame([$otherUid => "User-agent: *\nDisallow: /private"], $persisted['robotsTxt'] ?? null);
        $I->assertSame(['preview', 'title'], $persisted['availableSubfields'] ?? null);

        $I->assertTrue($plugin->getSiteDefaults()->saveSiteSettings($site, '', ''));
        $settings = $plugin->getSettings();
        $I->assertArrayNotHasKey($site->uid, $settings->siteSettings);
        $I->assertArrayHasKey($otherUid, $settings->siteSettings);
    }

    /**
     * The per-site save rejects an invalid title format through the same
     * validation pipeline as a full save, and reports the site-scoped error.
     */
    public function perSiteSaveRejectsInvalidFormat(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $saved = $plugin->getSiteDefaults()->saveSiteSettings($site, 'no tokens here', '');

        $I->assertFalse($saved);
        $I->assertNotEmpty($plugin->getSettings()->getErrors("siteSettings.$site->uid.titleFormat"));
    }

    /**
     * The field preview renders with the configured per-site format.
     */
    public function previewUsesConfiguredFormat(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $I->seedPluginSettings([
            'siteSettings' => [
                $site->uid => ['titleFormat' => '{title} • {siteName}'],
            ],
        ]);

        $fixture = $I->createSeoSection('defaultsPages', [
            'name' => 'Defaults Pages',
            'typeName' => 'Defaults Page',
            'typeHandle' => 'defaultsPage',
        ]);
        $entry = $I->createEntryWithSeo($fixture, 'Defaults Page');

        $html = $I->renderSeoFieldInput($fixture['field'], $entry);

        $expected = 'Defaults Page • ' . $site->name;
        $I->assertStringContainsString(
            htmlspecialchars($expected, ENT_QUOTES | ENT_SUBSTITUTE),
            $html,
        );
        $I->assertStringContainsString('data-title-format="{title} • {siteName}"', $html);
    }
}
