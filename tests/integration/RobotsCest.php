<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Entry;
use IntegrationTester;
use yii\web\NotFoundHttpException;

/**
 * THE robots invariant (ethercreative/seo#244 — ether silently de-indexed
 * entire live sites): with default settings, no code path in this plugin
 * emits a site-wide noindex, in meta or headers. Plus: per-entry robots
 * render exactly what's set, the config lockdown wins over everything, and
 * robots.txt reflects the environment.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class RobotsCest
{
    // Public Methods
    // =========================================================================

    /**
     * The invariant: default settings, clean entry → the word "noindex"
     * appears nowhere in the rendered meta, and no X-Robots-Tag header
     * exists on the response. `siteWideNoindex` defaults to false.
     */
    public function invariantNoNoindexByDefault(IntegrationTester $I): void
    {
        $I->assertFalse(Plugin::getInstance()->getSettings()->siteWideNoindex);

        $entry = $this->_entry($I, ['title' => 'Clean entry']);
        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);

        $I->assertStringNotContainsString('noindex', $html);
        $I->assertStringNotContainsString('name="robots"', $html);
        $I->assertNull(Craft::$app->getResponse()->getHeaders()->get('X-Robots-Tag'));

        $siteOnly = (string)Plugin::getInstance()->getMeta()->renderTags(null);
        $I->assertStringNotContainsString('noindex', $siteOnly);
    }

    /**
     * Per-entry robots render exactly what's set — no surprise values
     * (ethercreative/seo#498, #494, #435).
     */
    public function perEntryRobotsRenderExactly(IntegrationTester $I): void
    {
        $meta = Plugin::getInstance()->getMeta();

        $both = $this->_entry($I, ['noindex' => true, 'nofollow' => true]);
        $I->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            (string)$meta->renderTags($both),
        );

        $noindexOnly = $this->_entry($I, ['noindex' => true]);
        $I->assertStringContainsString(
            '<meta name="robots" content="noindex">',
            (string)$meta->renderTags($noindexOnly),
        );
        $I->assertStringNotContainsString('nofollow', (string)$meta->renderTags($noindexOnly));
    }

    /**
     * The config lockdown forces noindex, nofollow on every page — including
     * over template overrides. A lockdown that can be overridden isn't one.
     */
    public function siteWideLockdownWinsOverEverything(IntegrationTester $I): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $meta = Plugin::getInstance()->getMeta();
        $entry = $this->_entry($I, ['title' => 'Clean entry']);

        try {
            $settings->siteWideNoindex = true;

            $html = (string)$meta->renderTags($entry);
            $I->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);

            $overridden = (string)$meta->renderTags($entry, ['robots' => null]);
            $I->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $overridden);
        } finally {
            $settings->siteWideNoindex = false;
        }
    }

    /**
     * robots.txt reflects the environment: open + sitemap reference by
     * default, full disallow under lockdown.
     */
    public function robotsTxtReflectsEnvironment(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $response = Craft::$app->runAction('simple-seo/robots/index');
        $I->assertStringContainsString('User-agent: *', (string)$response->data);
        $I->assertStringContainsString("Disallow:\n", (string)$response->data);
        $I->assertStringNotContainsString('Disallow: /', (string)$response->data);
        $I->assertStringContainsString('Sitemap: ', (string)$response->data);

        try {
            $settings->siteWideNoindex = true;
            $locked = Craft::$app->runAction('simple-seo/robots/index');
            $I->assertStringContainsString('Disallow: /', (string)$locked->data);
            $I->assertStringNotContainsString('Sitemap:', (string)$locked->data);
        } finally {
            $settings->siteWideNoindex = false;
        }
    }

    /**
     * A saved per-site robots.txt is served verbatim (with the sitemap token
     * expanded), replaces the default, and clearing it restores the default.
     * Other sites' entries and the other setting groups survive the save.
     */
    public function customRobotsTxtIsServedPerSite(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $site = Craft::$app->getSites()->getPrimarySite();
        $robots = $plugin->getRobots();
        $otherUid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $seeded = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [$site->uid => ['titleFormat' => '{title} | {siteName}']],
            'robotsTxt' => [$otherUid => "User-agent: *\nDisallow: /private"],
            'availableSubfields' => ['preview', 'title'],
        ]);
        $I->assertTrue($seeded, json_encode($plugin->getSettings()->getErrors()) ?: '');

        $I->assertTrue($robots->saveSiteSettings(
            $site,
            "User-agent: *\nDisallow: /admin\n\nSitemap: " . $robots::SITEMAP_TOKEN,
        ));

        $body = (string)Craft::$app->runAction('simple-seo/robots/index')->data;
        $I->assertStringContainsString('Disallow: /admin', $body);
        $I->assertStringContainsString('Sitemap: ' . $robots->sitemapUrl($site), $body);
        $I->assertStringNotContainsString($robots::SITEMAP_TOKEN, $body);

        // Untouched groups survived the per-site save. Read project config, not
        // the in-memory model — the model keeps groups that the save just
        // deleted from project.yaml, so it cannot fail this assertion.
        $persisted = $I->persistedPluginSettings();
        $I->assertSame(
            [$site->uid => ['titleFormat' => '{title} | {siteName}']],
            $persisted['siteSettings'] ?? null,
        );
        $I->assertSame("User-agent: *\nDisallow: /private", $persisted['robotsTxt'][$otherUid] ?? null);
        $I->assertSame(['preview', 'title'], $persisted['availableSubfields'] ?? null);

        // Clearing restores the shipped default.
        $I->assertTrue($robots->saveSiteSettings($site, '   '));
        $I->assertArrayNotHasKey($site->uid, $plugin->getSettings()->robotsTxt);
        $I->assertSame($robots->defaultForSite($site), (string)Craft::$app->runAction('simple-seo/robots/index')->data);
    }

    /**
     * Switching robots.txt off stops the plugin serving it, and keeps the
     * author's content so turning it back on restores what they wrote.
     */
    public function robotsTxtCanBeSwitchedOffPerSite(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $site = Craft::$app->getSites()->getPrimarySite();
        $robots = $plugin->getRobots();

        $I->assertTrue($robots->isEnabledForSite($site), 'enabled with zero configuration');

        $I->assertTrue($robots->saveSiteSettings($site, "User-agent: *\nDisallow: /admin", false));
        $I->assertFalse($robots->isEnabledForSite($site));

        // The action path bypasses URL rules, so it has to 404 on its own.
        $I->expectThrowable(
            NotFoundHttpException::class,
            fn() => Craft::$app->runAction('simple-seo/robots/index'),
        );

        // Content survived, so re-enabling restores it rather than the default.
        $I->assertTrue($robots->saveSiteSettings($site, "User-agent: *\nDisallow: /admin", true));
        $I->assertTrue($robots->isEnabledForSite($site));
        $I->assertStringContainsString(
            'Disallow: /admin',
            (string)Craft::$app->runAction('simple-seo/robots/index')->data,
        );
    }

    /**
     * The lockdown forces robots.txt back on: it is one of the three arms of
     * siteWideNoindex, and a settings toggle must not be able to remove one.
     */
    public function lockdownForcesRobotsTxtBackOn(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertTrue($plugin->getRobots()->saveSiteSettings($site, '', false));
        $I->assertFalse($plugin->getRobots()->isEnabledForSite($site));

        try {
            $settings->siteWideNoindex = true;

            $I->assertTrue($plugin->getRobots()->isEnabledForSite($site));
            $I->assertStringContainsString(
                'Disallow: /',
                (string)Craft::$app->runAction('simple-seo/robots/index')->data,
            );
        } finally {
            $settings->siteWideNoindex = false;
        }
    }

    /**
     * With the sitemap switched off the shipped default drops its Sitemap
     * line rather than advertising a URL that now 404s.
     */
    public function defaultRobotsTxtDropsTheSitemapReferenceWhenDisabled(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertStringContainsString('Sitemap: ', $plugin->getRobots()->defaultForSite($site));

        $I->assertTrue($plugin->getSitemap()->saveSiteSettings($site, [], [], false));
        $I->assertStringNotContainsString('Sitemap: ', $plugin->getRobots()->defaultForSite($site));
    }

    /**
     * The Robots screen's enable lightswitch round-trips through the save
     * action, and switching off does not discard the author's content.
     */
    public function robotsSwitchRoundTripsThroughTheSaveAction(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $I->beAdmin();

        $I->postToAction('simple-seo/settings/save-robots', [
            'siteUid' => $site->uid,
            'robotsTxt' => "User-agent: *\nDisallow: /admin",
            'robotsTxtEnabled' => '',
        ]);
        $I->assertFalse($plugin->getRobots()->isEnabledForSite($site));
        $I->assertSame("User-agent: *\nDisallow: /admin", $plugin->getRobots()->customForSite($site));

        $I->postToAction('simple-seo/settings/save-robots', [
            'siteUid' => $site->uid,
            'robotsTxt' => "User-agent: *\nDisallow: /admin",
            'robotsTxtEnabled' => '1',
        ]);
        $I->assertTrue($plugin->getRobots()->isEnabledForSite($site));
    }

    /**
     * The lockdown flag still beats author content — a custom robots.txt can
     * never un-hide an environment that config has hidden.
     */
    public function lockdownOverridesCustomRobotsTxt(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertTrue($plugin->getRobots()->saveSiteSettings($site, "User-agent: *\nDisallow:"));

        try {
            $settings->siteWideNoindex = true;
            $I->assertSame("User-agent: *\nDisallow: /\n", $plugin->getRobots()->contentForSite($site));
        } finally {
            $settings->siteWideNoindex = false;
            $plugin->getRobots()->saveSiteSettings($site, '');
        }
    }

    /**
     * The blanket-disallow detector powers the CP warning, so it has to read
     * robots.txt the way a crawler does — comments, casing, and per-agent
     * groups included.
     */
    public function blanketDisallowIsDetected(IntegrationTester $I): void
    {
        $robots = Plugin::getInstance()->getRobots();

        $I->assertTrue($robots->blocksEverything("User-agent: *\nDisallow: /"));
        $I->assertTrue($robots->blocksEverything("# staging\nUSER-AGENT: *\n  disallow:  /  "));

        $I->assertFalse($robots->blocksEverything("User-agent: *\nDisallow:"));
        $I->assertFalse($robots->blocksEverything("User-agent: *\nDisallow: /admin"));
        // Only one named bot is blocked — the site at large is still open.
        $I->assertFalse($robots->blocksEverything("User-agent: BadBot\nDisallow: /"));
        $I->assertFalse($robots->blocksEverything("# Disallow: /\nUser-agent: *\nDisallow:"));
    }

    /**
     * Extra per-entry directives ride along with the noindex/nofollow
     * toggles, always in the documented order regardless of posted order,
     * and unknown directives are dropped rather than emitted.
     */
    public function extraRobotsDirectivesRender(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'nofollow' => true,
            'robotsDirectives' => ['max-image-preview:large', 'noarchive', 'not-a-real-directive'],
        ]);

        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);
        $I->assertStringContainsString(
            '<meta name="robots" content="nofollow, noarchive, max-image-preview:large">',
            $html,
        );
        $I->assertStringNotContainsString('not-a-real-directive', $html);
    }

    /**
     * Directives alone (no noindex/nofollow) still emit a tag, and an element
     * asking for nothing unusual still emits none.
     */
    public function directivesOnlyStillEmitATag(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, ['robotsDirectives' => ['notranslate']]);
        $meta = Plugin::getInstance()->getMeta();

        $I->assertStringContainsString(
            '<meta name="robots" content="notranslate">',
            (string)$meta->renderTags($entry),
        );

        $entry->setFieldValue('seo', ['robotsDirectives' => []]);
        $I->assertStringNotContainsString('name="robots"', (string)$meta->renderTags($entry));
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the field + section fixture and one saved entry.
     *
     * @param array<string,mixed> $seoValue
     */
    private function _entry(IntegrationTester $I, array $seoValue): Entry
    {
        $fixture = $I->createSeoSection('robotsPages', [
            'name' => 'Robots Pages',
            'typeName' => 'Robots Page',
            'typeHandle' => 'robotsPage',
        ]);

        return $I->createEntryWithSeo($fixture, 'Robots Page', $seoValue);
    }
}
