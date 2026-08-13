<?php

namespace anvildev\simpleseo\tests\unit\helpers;

use anvildev\simpleseo\helpers\SitemapXml;
use PHPUnit\Framework\TestCase;

/**
 * Sitemap XML building: encoding, alternates, and the never-silently-empty
 * comment.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SitemapXmlTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * URLs are XML-entity-encoded — ampersands in query strings are the
     * classic sitemap-breaker.
     */
    public function testUrlsAreEncoded(): void
    {
        $entry = SitemapXml::urlEntry('https://example.com/x?a=1&b=2');

        $this->assertStringContainsString('https://example.com/x?a=1&amp;b=2', $entry);
        $this->assertStringNotContainsString('a=1&b', $entry);
    }

    /**
     * Alternates render as xhtml:link elements with hreflang.
     */
    public function testAlternatesRender(): void
    {
        $entry = SitemapXml::urlEntry('https://example.com/en/page', '2026-08-06T00:00:00+00:00', [
            ['hreflang' => 'en-US', 'href' => 'https://example.com/en/page'],
            ['hreflang' => 'fr-FR', 'href' => 'https://example.com/fr/page'],
        ]);

        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="fr-FR" href="https://example.com/fr/page"/>', $entry);
        $this->assertStringContainsString('<lastmod>2026-08-06T00:00:00+00:00</lastmod>', $entry);
    }

    /**
     * An empty urlset is never silent: it carries a reason comment and stays
     * well-formed (no double hyphens even if the reason has them).
     */
    public function testEmptyUrlsetExplainsItself(): void
    {
        $xml = SitemapXml::urlset([], 'all 3 live entries are noindexed -- check the field');

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('<!-- 0 URLs: all 3 live entries are noindexed', $xml);
        $this->assertStringNotContainsString('----', $xml);
        $this->assertStringContainsString('</urlset>', $xml);
    }

    /**
     * The index document lists sitemap locs and also explains emptiness.
     */
    public function testIndexDocument(): void
    {
        $xml = SitemapXml::index(['https://example.com/sitemaps/section-pages.xml']);
        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('<loc>https://example.com/sitemaps/section-pages.xml</loc>', $xml);

        $empty = SitemapXml::index([], 'no sections enabled');
        $this->assertStringContainsString('<!-- 0 URLs: no sections enabled -->', $empty);
    }
}
