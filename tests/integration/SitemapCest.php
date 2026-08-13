<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\services\SitemapService;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use IntegrationTester;
use yii\web\NotFoundHttpException;

/**
 * Sitemap generation against a real Craft app: index composition, inclusion
 * rules (live + URL + not noindexed), the never-silently-empty comment,
 * pagination, settings exclusion, and cache invalidation on
 * entry save.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SitemapCest
{
    // Public Methods
    // =========================================================================

    /**
     * The index lists a URL-having section; excluding it in settings removes
     * it; sections without URLs never appear.
     */
    public function indexRespectsSectionsAndSettings(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $sitemap = Plugin::getInstance()->getSitemap();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'One', []);

        $xml = $sitemap->getIndexXml($site);
        $I->assertStringContainsString('sitemaps/section-sitemapPages.xml', $xml);

        $settings = Plugin::getInstance()->getSettings();
        try {
            $settings->sitemapExcludedSections = [$site->uid => [$fixture['section']->uid]];
            $sitemap->invalidate();
            $excludedXml = $sitemap->getIndexXml($site);
            $I->assertStringNotContainsString('section-sitemapPages.xml', $excludedXml);
        } finally {
            $settings->sitemapExcludedSections = [];
            $sitemap->invalidate();
        }
    }

    /**
     * The per-site sitemap save stores the checked sections inverted (as
     * exclusions, so new sections default to included), normalises
     * priorities, and leaves other sites' entries AND the per-site defaults
     * group untouched. Checking everything clears the site's entries.
     */
    public function perSiteSaveInvertsAndPreserves(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $fixture = $this->_sitemapPages($I);
        $otherUid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $seeded = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [$site->uid => ['titleFormat' => '{title} | {siteName}']],
            'sitemapPriorities' => [$otherUid => ['some-section-uid' => '0.3']],
            'robotsTxt' => [$otherUid => "User-agent: *\nDisallow: /private"],
            'availableSubfields' => ['preview', 'title'],
        ]);
        $I->assertTrue($seeded, json_encode($plugin->getSettings()->getErrors()) ?: '');

        // Nothing checked: the URL-having section lands in the exclusions.
        $saved = $plugin->getSitemap()->saveSiteSettings($site, [], [
            (string)$fixture['section']->uid => ' 0.8 ',
            'ignored-not-numeric' => 'high',
            'ignored-empty' => '',
        ]);
        $I->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');

        $settings = $plugin->getSettings();
        $I->assertContains((string)$fixture['section']->uid, $settings->sitemapExcludedSections[$site->uid] ?? []);
        // Trimmed, formatted to one decimal, and non-numeric values dropped.
        $I->assertSame(
            [(string)$fixture['section']->uid => '0.8'],
            $settings->sitemapPriorities[$site->uid] ?? null,
        );
        $I->assertSame(['some-section-uid' => '0.3'], $settings->sitemapPriorities[$otherUid] ?? null);

        // Ride-along groups, read from project config — the in-memory model
        // retains keys this save may have dropped from project.yaml.
        $persisted = $I->persistedPluginSettings();
        $I->assertSame(
            [$site->uid => ['titleFormat' => '{title} | {siteName}']],
            $persisted['siteSettings'] ?? null,
        );
        $I->assertSame([$otherUid => "User-agent: *\nDisallow: /private"], $persisted['robotsTxt'] ?? null);
        $I->assertSame(['preview', 'title'], $persisted['availableSubfields'] ?? null);

        // Everything checked and no priorities: both of the site's entries drop.
        // Every section, not just the URL-having ones — exclusions are
        // inverted across all of them so a section can be switched off before
        // it has URLs.
        $included = array_map(
            static fn(Section $section): string => (string)$section->uid,
            Craft::$app->getEntries()->getAllSections(),
        );
        $I->assertTrue($plugin->getSitemap()->saveSiteSettings($site, $included, []));
        $settings = $plugin->getSettings();
        $I->assertArrayNotHasKey($site->uid, $settings->sitemapExcludedSections);
        $I->assertArrayNotHasKey($site->uid, $settings->sitemapPriorities);
    }

    /**
     * Switching the sitemap off stops the plugin serving it, and keeps the
     * section choices so turning it back on restores the configuration.
     */
    public function sitemapCanBeSwitchedOffPerSite(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'One', []);

        $sitemap = $plugin->getSitemap();
        $I->assertTrue($sitemap->isEnabledForSite($site), 'enabled with zero configuration');

        // Keep the section included, so only the switch explains the 404.
        $included = array_map(
            static fn(Section $section): string => (string)$section->uid,
            Craft::$app->getEntries()->getAllSections(),
        );
        $I->assertTrue($sitemap->saveSiteSettings($site, $included, [], false));
        $I->assertFalse($sitemap->isEnabledForSite($site));

        // The action path bypasses URL rules, so both actions 404 on their own.
        $I->expectThrowable(
            NotFoundHttpException::class,
            fn() => Craft::$app->runAction('simple-seo/sitemap/index'),
        );

        $I->assertTrue($sitemap->saveSiteSettings($site, $included, [], true));
        $I->assertTrue($sitemap->isEnabledForSite($site));
        $I->assertStringContainsString(
            'sitemaps/section-sitemapPages.xml',
            $sitemap->getIndexXml($site),
        );
    }

    /**
     * The Sitemap screen's enable lightswitch round-trips through the save
     * action — the toggle is only useful if the posted value reaches the
     * service, which calling the service directly never proves.
     */
    public function sitemapSwitchRoundTripsThroughTheSaveAction(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $I->beAdmin();

        // An unchecked lightswitch posts '' — the same shape the CP sends.
        $I->postToAction('simple-seo/settings/save-sitemap', [
            'siteUid' => $site->uid,
            'sitemapEnabled' => '',
        ]);
        $I->assertFalse($plugin->getSitemap()->isEnabledForSite($site));

        $I->postToAction('simple-seo/settings/save-sitemap', [
            'siteUid' => $site->uid,
            'sitemapEnabled' => '1',
        ]);
        $I->assertTrue($plugin->getSitemap()->isEnabledForSite($site));
    }

    /**
     * A section sitemap contains live entries with URLs, excludes noindexed
     * and disabled ones, and contains each URL exactly once
     * (ethercreative/seo#219, #145).
     */
    public function sectionSitemapAppliesInclusionRules(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $fixture = $this->_sitemapPages($I);

        $included = $I->createEntryWithSeo($fixture, 'Included', []);
        $I->createEntryWithSeo($fixture, 'Noindexed', ['noindex' => true]);
        $disabled = $I->createEntryWithSeo($fixture, 'Disabled', []);
        $disabled->enabled = false;
        $I->assertTrue(Craft::$app->getElements()->saveElement($disabled));

        $xml = (string)Plugin::getInstance()->getSitemap()->getSectionXml($site, 'sitemapPages');

        $I->assertStringContainsString((string)$included->getUrl(), $xml);
        $I->assertStringNotContainsString('noindexed', $xml);
        $I->assertStringNotContainsString('disabled', $xml);
        $I->assertSame(1, substr_count($xml, '<url>'));
    }

    /**
     * An all-noindexed section renders a well-formed empty urlset WITH the
     * reason comment — never silently empty (ethercreative/seo#422 class).
     */
    public function emptySectionExplainsItself(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'Hidden', ['noindex' => true]);

        $xml = (string)Plugin::getInstance()->getSitemap()->getSectionXml($site, 'sitemapPages');

        $I->assertStringContainsString('<urlset', $xml);
        $I->assertStringNotContainsString('<url>', $xml);
        $I->assertStringContainsString('<!-- 0 URLs: all 1 live entries on this page are noindexed -->', $xml);

        $rows = Plugin::getInstance()->getSitemap()->explain($site);
        $row = array_values(array_filter($rows, static fn(array $r): bool => $r['section'] === 'sitemapPages'))[0] ?? null;
        $I->assertNotNull($row);
        $I->assertTrue($row['included']);
        $I->assertSame(0, $row['urls']);
        $I->assertStringContainsString('noindexed', $row['reason']);
        // The CP table translates from the code + params, not the text.
        $I->assertSame(SitemapService::REASON_ALL_NOINDEXED, $row['reasonCode']);
        $I->assertSame(['total' => 1], $row['reasonParams']);
    }

    /**
     * Pagination splits section files and the index lists every page.
     */
    public function paginationSplitsFiles(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $sitemap = Plugin::getInstance()->getSitemap();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'P One', []);
        $I->createEntryWithSeo($fixture, 'P Two', []);
        $I->createEntryWithSeo($fixture, 'P Three', []);

        $sitemap->urlsPerPage = 2;
        try {
            $sitemap->invalidate();

            $index = $sitemap->getIndexXml($site);
            $I->assertStringContainsString('section-sitemapPages.xml', $index);
            $I->assertStringContainsString('section-sitemapPages-p2.xml', $index);

            $page1 = (string)$sitemap->getSectionXml($site, 'sitemapPages', 1);
            $page2 = (string)$sitemap->getSectionXml($site, 'sitemapPages', 2);
            $I->assertSame(2, substr_count($page1, '<url>'));
            $I->assertSame(1, substr_count($page2, '<url>'));
        } finally {
            $sitemap->urlsPerPage = 1000;
            $sitemap->invalidate();
        }
    }

    /**
     * Priority is omitted unless set — the default claims nothing, because
     * both Google and Bing document that they ignore the tag. When set, it
     * ships on every URL in that section's file.
     */
    public function priorityIsOptedInto(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'Priority Page', [], 'priority-page');

        // Unset: no element at all.
        $xml = $plugin->getSitemap()->getSectionXml($site, (string)$fixture['section']->handle, 1);
        $I->assertStringNotContainsString('<priority>', $xml);

        $included = array_map(
            static fn(Section $s): string => (string)$s->uid,
            Craft::$app->getEntries()->getAllSections(),
        );
        $I->assertTrue($plugin->getSitemap()->saveSiteSettings(
            $site,
            $included,
            [(string)$fixture['section']->uid => '0.8'],
        ));

        $I->assertSame('0.8', $plugin->getSitemap()->priorityFor($site, $fixture['section']));

        $plugin->getSitemap()->invalidate();
        $xml = $plugin->getSitemap()->getSectionXml($site, (string)$fixture['section']->handle, 1);
        $I->assertStringContainsString('<priority>0.8</priority>', $xml);

        // A section without an entry still emits none.
        $other = $I->createSeoSection('otherPriorityPages', [
            'name' => 'Other Priority Pages',
            'typeName' => 'Other Priority Page',
            'typeHandle' => 'otherPriorityPage',
            'uriFormat' => 'other-priority/{slug}',
        ]);
        $I->assertNull($plugin->getSitemap()->priorityFor($site, $other['section']));
    }

    /**
     * Saving an entry invalidates the cache: the next render includes it.
     */
    public function cacheInvalidatesOnEntrySave(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $sitemap = Plugin::getInstance()->getSitemap();
        $fixture = $this->_sitemapPages($I);
        $I->createEntryWithSeo($fixture, 'First', []);

        $before = (string)$sitemap->getSectionXml($site, 'sitemapPages');
        $I->assertSame(1, substr_count($before, '<url>'));

        // This save fires EVENT_AFTER_SAVE_ELEMENT → invalidation.
        $I->createEntryWithSeo($fixture, 'Second', []);

        $after = (string)$sitemap->getSectionXml($site, 'sitemapPages');
        $I->assertSame(2, substr_count($after, '<url>'));
    }

    /**
     * A double-encoded elements_sites content row — a JSON string scalar
     * containing the JSON document, exactly what imports and older
     * migrations produce — is still detected as noindexed. Pins the
     * double-decode tolerance in SeoFieldReader::noindexFromContent().
     */
    public function doubleEncodedContentRowStillDetected(IntegrationTester $I): void
    {
        $fixture = $this->_sitemapPages($I);
        $entry = $I->createEntryWithSeo($fixture, 'Double Encoded', ['noindex' => true]);

        $rawContent = (new Query())
            ->select(['content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $entry->id])
            ->scalar();

        $normalized = is_array($rawContent) ? Json::encode($rawContent) : (string)$rawContent;
        $doubleEncoded = Json::encode($normalized);

        SeoFieldReader::clearMemos();
        $I->assertTrue(SeoFieldReader::noindexFromContent($normalized), 'positive control: normal content detects noindex');

        SeoFieldReader::clearMemos();
        $I->assertTrue(SeoFieldReader::noindexFromContent($doubleEncoded), 'double-encoded content still detects noindex');
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the shared `sitemapPages` URL-having channel section fixture.
     *
     * @return array{section: Section, entryType: EntryType, field: SeoField}
     */
    private function _sitemapPages(IntegrationTester $I): array
    {
        return $I->createSeoSection('sitemapPages', [
            'name' => 'Sitemap Pages',
            'typeName' => 'Sitemap Page',
            'typeHandle' => 'sitemapPage',
            'uriFormat' => 'sitemap-pages/{slug}',
            'template' => '_page',
        ]);
    }

    /**
     * A section with no URLs on the site can still be switched off, and the
     * choice persists — exclusions are inverted across every section, not
     * only the ones that happen to have URLs today.
     */
    public function urlLessSectionsCanBeExcluded(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        // A section deliberately without URLs.
        $urlLess = $I->createSeoSection('noUrlPages', [
            'name' => 'No URL Pages',
            'typeName' => 'No URL Page',
            'typeHandle' => 'noUrlPage',
        ]);
        $I->assertNotContains(
            $urlLess['section']->uid,
            array_map(static fn(Section $s): string => (string)$s->uid, $plugin->getSitemap()->urlSections($site)),
            'fixture must have no URLs, otherwise this proves nothing',
        );

        // Include everything except it.
        $included = array_values(array_filter(
            array_map(static fn(Section $s): string => (string)$s->uid, Craft::$app->getEntries()->getAllSections()),
            static fn(string $uid): bool => $uid !== (string)$urlLess['section']->uid,
        ));
        $I->assertTrue($plugin->getSitemap()->saveSiteSettings($site, $included, []));

        $I->assertContains(
            (string)$urlLess['section']->uid,
            $plugin->getSettings()->sitemapExcludedSections[$site->uid] ?? [],
        );
    }
}
