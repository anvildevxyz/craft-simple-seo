<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Entry;
use IntegrationTester;

/**
 * Canonical hardening through the real render pipeline: encoding in the tag,
 * tag/header agreement, and the config kill-switch for the header.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class MetaCanonicalCest
{
    // Public Methods
    // =========================================================================

    /**
     * An author-entered canonical renders percent-encoded in the tag and
     * og:url, keeping its query params (ether #508 through the full pipeline).
     */
    public function fieldCanonicalIsEncoded(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'canonical' => 'https://example.com/über-uns?ref=launch',
        ]);

        $html = (string)Plugin::getInstance()->getMeta()->renderTags($entry);

        $I->assertStringContainsString('https://example.com/%C3%BCber-uns?ref=launch', $html);
        $I->assertStringNotContainsString('href="https://example.com/über-uns', $html);
        $I->assertStringContainsString(
            '<meta property="og:url" content="https://example.com/%C3%BCber-uns?ref=launch">',
            $html,
        );
    }

    /**
     * The Link header carries the exact URL the tag renders (ether #516), and
     * the config switch removes the header without touching the tag
     * (ether #423).
     */
    public function headerAgreesWithTagAndCanBeDisabled(IntegrationTester $I): void
    {
        $entry = $this->_entry($I, [
            'canonical' => 'https://example.com/über-uns',
        ]);

        $meta = Plugin::getInstance()->getMeta();
        $settings = Plugin::getInstance()->getSettings();
        $headers = Craft::$app->getResponse()->getHeaders();

        try {
            // Disabled: tag renders, no header.
            $settings->canonicalLinkHeader = false;
            $headers->remove('Link');
            $html = (string)$meta->renderTags($entry);
            $I->assertStringContainsString('rel="canonical"', $html);
            $I->assertNull($headers->get('Link'));

            // Enabled: header appears with the identical URL.
            $settings->canonicalLinkHeader = true;
            $html = (string)$meta->renderTags($entry);
            $link = $headers->get('Link');
            $I->assertNotNull($link);
            $I->assertSame('<https://example.com/%C3%BCber-uns>; rel="canonical"', $link);
            $I->assertStringContainsString('href="https://example.com/%C3%BCber-uns"', $html);
        } finally {
            $settings->canonicalLinkHeader = true;
            $headers->remove('Link');
        }
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
        $fixture = $I->createSeoSection('canonicalPages', [
            'name' => 'Canonical Pages',
            'typeName' => 'Canonical Page',
            'typeHandle' => 'canonicalPage',
        ]);

        return $I->createEntryWithSeo($fixture, 'Canonical Page', $seoValue);
    }
}
