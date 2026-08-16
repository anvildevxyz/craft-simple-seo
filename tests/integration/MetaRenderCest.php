<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\models\ResolvedMeta;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Entry;
use IntegrationTester;
use yii\base\InvalidArgumentException;

/**
 * Front-end meta rendering: the one-line integration, the fallback chain,
 * per-template overrides, encoding, robots, and headless parity.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class MetaRenderCest
{
    // Public Methods
    // =========================================================================

    /**
     * A full field value renders every tag family with correct values.
     */
    public function rendersAllTagFamilies(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'title' => 'Meta title',
            'description' => 'Meta description.',
            'canonical' => 'https://example.com/canonical',
            'nofollow' => true,
        ]);

        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertStringContainsString('<title>Meta title - ' . $site->name . '</title>', $html);
        $I->assertStringContainsString('<meta name="description" content="Meta description.">', $html);
        $I->assertMatchesRegularExpression(
            '/<link (?=[^>]*rel="canonical")(?=[^>]*href="https:\/\/example\.com\/canonical")[^>]*>/',
            $html,
        );
        $I->assertStringContainsString('<meta name="robots" content="nofollow">', $html);
        $I->assertStringContainsString('<meta property="og:site_name" content="' . $site->name . '">', $html);
        $I->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $I->assertStringContainsString('<meta property="og:title" content="Meta title">', $html);
        $I->assertStringContainsString('<meta property="og:url" content="https://example.com/canonical">', $html);
        $I->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        $I->assertStringContainsString('<meta name="twitter:title" content="Meta title">', $html);
    }

    /**
     * Special characters are entity-encoded in every tag — markup in a title
     * can never break the document (ether #254-class, front-end edition).
     */
    public function specialCharactersAreEncoded(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'title' => '100% "Zürich" & <script>alert(1)</script>',
        ]);

        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);

        $I->assertStringNotContainsString('<script>', $html);
        $I->assertStringContainsString('&lt;script&gt;', $html);
        $I->assertStringContainsString('&quot;Zürich&quot; &amp;', $html);
    }

    /**
     * og:type and og:site_name are overridable per call (ether #517, #495),
     * and unknown override keys throw instead of silently doing nothing.
     */
    public function overridesWork(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, ['title' => 'Article page']);
        $meta = Plugin::getInstance()->getMeta();

        $html = (string)$meta->renderTags($entry, [
            'ogType' => 'article',
            'ogSiteName' => 'Custom Brand',
        ]);
        $I->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $I->assertStringContainsString('<meta property="og:site_name" content="Custom Brand">', $html);

        $thrown = false;
        try {
            $meta->resolve($entry, ['ogTpye' => 'article']);
        } catch (InvalidArgumentException $e) {
            $thrown = true;
            $I->assertStringContainsString('ogTpye', $e->getMessage());
        }
        $I->assertTrue($thrown, 'unknown override key must throw');
    }

    /**
     * An explicit null override CLEARS its value — description, canonical,
     * and robots render no tag even when the field carries one — while a
     * null title is treated as absent and the chain still runs: a page
     * always has a title (#6).
     */
    public function explicitNullOverridesClear(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'title' => 'Null override page',
            'description' => 'Field description.',
            'canonical' => 'https://example.com/elsewhere',
            'noindex' => true,
        ]);
        $meta = Plugin::getInstance()->getMeta();

        $html = (string)$meta->renderTags($entry, [
            'description' => null,
            'canonical' => null,
            'robots' => null,
        ]);
        $I->assertStringNotContainsString('name="description"', $html);
        $I->assertStringNotContainsString('rel="canonical"', $html);
        $I->assertStringNotContainsString('og:url', $html);
        $I->assertStringNotContainsString('name="robots"', $html);

        $resolved = $meta->resolve($entry, ['description' => null, 'canonical' => null]);
        $I->assertNull($resolved->description);
        $I->assertNull($resolved->canonical);
        $I->assertSame(ResolvedMeta::SOURCE_NONE, $resolved->sources['description']);
        $I->assertSame(ResolvedMeta::SOURCE_NONE, $resolved->sources['canonical']);

        // A null title falls through: the field's meta title still wins.
        $titled = $meta->resolve($entry, ['title' => null]);
        $I->assertStringContainsString('Null override page', $titled->title);
    }

    /**
     * The fallback chain: no field value → entry title + site default
     * description; no robots tag by default (absent = index,follow — this
     * plugin never emits noindex unless asked).
     */
    public function fallbackChainApplies(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $I->seedPluginSettings([
            'siteSettings' => [
                $site->uid => ['defaultDescription' => 'Site default description.'],
            ],
        ]);

        $entry = $this->_entry($I, null);
        $html = (string)$plugin->getMeta()->renderTags($entry);

        $I->assertStringContainsString('<title>Meta Page - ' . $site->name . '</title>', $html);
        $I->assertStringContainsString('<meta name="description" content="Site default description.">', $html);
        $I->assertStringNotContainsString('name="robots"', $html);
        $I->assertStringNotContainsString('noindex', $html);
    }

    /**
     * An element whose layout has no SEO field renders sane meta without
     * erroring (ether #262); a null element renders site-level meta.
     */
    public function degradedContextsRenderSafely(IntegrationTester $I): void
    {
        $entry = $this->_entryWithoutField($I);
        $site = Craft::$app->getSites()->getPrimarySite();
        $meta = Plugin::getInstance()->getMeta();

        $html = (string)$meta->renderTags($entry);
        $I->assertStringContainsString('<title>Bare Page - ' . $site->name . '</title>', $html);

        $siteOnly = (string)$meta->renderTags(null);
        $I->assertStringContainsString('<title>' . $site->name . '</title>', $siteOnly);
        $I->assertStringContainsString('og:site_name', $siteOnly);
    }

    /**
     * resolveMeta() returns the identical data the tags are rendered from —
     * headless consumers can never drift from the Twig output.
     */
    public function headlessArrayMatchesRenderedTags(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'title' => 'Headless title',
            'description' => 'Headless description.',
            'noindex' => true,
        ]);

        $array = Plugin::getInstance()->getMeta()->resolve($entry)->toArray();
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertSame('Headless title - ' . $site->name, $array['title']);
        $I->assertSame('Headless title', $array['socialTitle']);
        $I->assertSame('Headless description.', $array['description']);
        $I->assertSame('noindex', $array['robots']);
        $I->assertSame('website', $array['ogType']);

        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);
        $I->assertStringContainsString('content="' . $array['robots'] . '"', $html);
    }

    /**
     * Every resolved value names the input that won its fallback chain, so
     * "why is this page's description that" is answerable without guessing.
     * The provenance stays out of toArray() — headless output is unchanged.
     */
    public function sourcesReportTheWinningInput(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $I->seedPluginSettings([
            'siteSettings' => [
                $site->uid => ['defaultDescription' => 'Site default description.'],
            ],
        ]);

        // Field values win.
        $withValues = $plugin->getMeta()->resolve($this->_entry($I, [
            'title' => 'Sourced title',
            'description' => 'Sourced description.',
            'noindex' => true,
            'canonical' => 'https://example.com/elsewhere',
        ]));
        $I->assertSame(ResolvedMeta::SOURCE_FIELD, $withValues->sources['title']);
        $I->assertSame(ResolvedMeta::SOURCE_FIELD, $withValues->sources['description']);
        $I->assertSame(ResolvedMeta::SOURCE_FIELD, $withValues->sources['canonical']);
        $I->assertSame(ResolvedMeta::SOURCE_FIELD, $withValues->sources['robots']);
        $I->assertArrayNotHasKey('sources', $withValues->toArray());

        // Empty field falls through: entry title, site default, no robots.
        $fallback = $plugin->getMeta()->resolve($this->_entry($I, null));
        $I->assertSame(ResolvedMeta::SOURCE_ENTRY_TITLE, $fallback->sources['title']);
        $I->assertSame(ResolvedMeta::SOURCE_SITE_DEFAULT, $fallback->sources['description']);
        $I->assertSame(ResolvedMeta::SOURCE_NONE, $fallback->sources['robots']);
        $I->assertSame(ResolvedMeta::SOURCE_NONE, $fallback->sources['ogImageUrl']);

        // Overrides beat everything they cover.
        $overridden = $plugin->getMeta()->resolve(
            $this->_entry($I, ['title' => 'Sourced title']),
            ['title' => 'Override title', 'description' => 'Override description.'],
        );
        $I->assertSame(ResolvedMeta::SOURCE_OVERRIDE, $overridden->sources['title']);
        $I->assertSame(ResolvedMeta::SOURCE_OVERRIDE, $overridden->sources['description']);
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the shared field + section fixture and one saved entry.
     *
     * @param array<string,mixed>|null $seoValue
     */
    private function _entry(IntegrationTester $I, ?array $seoValue): Entry
    {
        $fixture = $I->createSeoSection('metaPages', ['name' => 'Meta Pages', 'typeHandle' => 'metaPage']);

        return $I->createEntryWithSeo($fixture, 'Meta Page', $seoValue);
    }

    /**
     * Creates an entry whose layout carries no SEO field.
     */
    private function _entryWithoutField(IntegrationTester $I): Entry
    {
        $fixture = $I->createSeoSection('barePages', [
            'name' => 'Bare Pages',
            'typeHandle' => 'barePage',
            'withSeoField' => false,
        ]);

        return $I->createEntryWithSeo($fixture, 'Bare Page');
    }
}
