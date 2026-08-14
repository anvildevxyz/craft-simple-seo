<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\TitleFormatter;
use Craft;
use craft\elements\Entry;
use IntegrationTester;

/**
 * The SERP/social preview renders server-side into the field input with all
 * data embedded — the markup itself must prove no client request is needed.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoFieldPreviewCest
{
    // Public Methods
    // =========================================================================

    /**
     * The rendered input carries the preview pane, the formatted title, one
     * URL line, and the embedded data the client-side updates need.
     */
    public function previewRendersServerSideWithEmbeddedData(IntegrationTester $I): void
    {
        [$field, $entry] = $this->_fixture($I, [
            'title' => 'Custom meta title',
            'description' => 'A description that should appear verbatim.',
        ]);

        $html = $I->renderSeoFieldInput($field, $entry);
        $site = Craft::$app->getSites()->getPrimarySite();

        $I->assertStringContainsString('data-simple-seo-field', $html);
        $I->assertStringContainsString('data-site-name="' . $site->name . '"', $html);
        $I->assertStringContainsString('data-title-format="' . TitleFormatter::DEFAULT_FORMAT . '"', $html);
        $I->assertStringContainsString('data-fallback-title="Preview Page"', $html);

        $expectedTitle = TitleFormatter::format('Custom meta title', 'Preview Page', $site->name);
        $I->assertStringContainsString('data-preview-title>' . htmlspecialchars($expectedTitle, ENT_QUOTES | ENT_SUBSTITUTE) . '<', $html);
        $I->assertStringContainsString('A description that should appear verbatim.', $html);

        // Exactly one URL line (ethercreative/seo#464: "URL is shown twice").
        $I->assertSame(1, substr_count($html, 'simple-seo-preview__url'));

        // The whole preview is static markup: no action URL, no XHR endpoint.
        $I->assertStringNotContainsString('actions/', $html);
    }

    /**
     * Without an element context (field defaults screen) the preview is
     * omitted entirely instead of rendering with wrong data — and the robots
     * controls fall back to rendering inline instead of on the Robots tab.
     */
    public function previewOmittedWithoutElementContext(IntegrationTester $I): void
    {
        [$field] = $this->_fixture($I, null);

        $html = $I->renderSeoFieldInput($field, null);

        $I->assertStringContainsString('data-simple-seo-field', $html);
        $I->assertStringNotContainsString('simple-seo-preview', $html);
        $I->assertStringContainsString('Hide from search engines (noindex)', $html);
    }

    /**
     * The robots controls live on the preview's Robots tab: exactly one set
     * of real inputs, inside the pane, every directive as its own switch,
     * and no noindex badge anywhere.
     */
    public function robotsControlsRenderOnTheRobotsTab(IntegrationTester $I): void
    {
        [$field, $entry] = $this->_fixture($I, ['noindex' => true]);
        $html = $I->renderSeoFieldInput($field, $entry);

        $I->assertStringNotContainsString('data-preview-noindex', $html);
        $I->assertStringContainsString('data-preview-tab="robots"', $html);
        $I->assertSame(1, substr_count($html, 'data-preview-pane="robots"'));
        $I->assertSame(1, substr_count($html, 'Hide from search engines (noindex)'));

        // Every offered directive is a switch, not a checkbox list.
        $I->assertStringContainsString('No cached copy in results (noarchive)', $html);
        $I->assertStringContainsString('No video preview (max-video-preview:0)', $html);
        $I->assertStringNotContainsString('<details', $html);
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the field + section fixture and one saved entry.
     *
     * @param array<string,mixed>|null $seoValue
     * @return array{0: SeoField, 1: Entry|null}
     */
    private function _fixture(IntegrationTester $I, ?array $seoValue): array
    {
        $field = $I->ensureSeoField();

        if ($seoValue === null) {
            return [$field, null];
        }

        $fixture = $I->createSeoSection('previewPages', [
            'name' => 'Preview Pages',
            'typeName' => 'Preview Page',
            'typeHandle' => 'previewPage',
            'uriFormat' => 'preview-pages/{slug}',
            'template' => '_preview',
        ]);
        $entry = $I->createEntryWithSeo($fixture, 'Preview Page', $seoValue, 'preview-page');

        return [$field, $entry];
    }
}
