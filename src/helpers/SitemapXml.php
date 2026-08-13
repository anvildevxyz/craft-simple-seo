<?php

namespace anvildev\simpleseo\helpers;

/**
 * Sitemap XML building — pure string functions, fully encoding-safe.
 *
 * URLs pass through XML entity encoding (& in query strings is the classic
 * sitemap-breaker), and empty urlsets carry a human-readable comment saying
 * WHY they are empty — ether's tracker is full of "sitemap empty/missing"
 * with no diagnosis path (ethercreative/seo#422, #343, #430, #466).
 *
 * @phpstan-type SitemapAlternate array{hreflang: string, href: string}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class SitemapXml
{
    // Public Methods
    // =========================================================================

    /**
     * Builds a sitemap index document from absolute sitemap URLs.
     *
     * @param string[] $sitemapUrls
     */
    public static function index(array $sitemapUrls, ?string $emptyReason = null): string
    {
        $items = array_map(
            static fn(string $url): string => '  <sitemap><loc>' . self::encode($url) . '</loc></sitemap>',
            $sitemapUrls,
        );

        return self::_document('sitemapindex', $items, $emptyReason, false);
    }

    /**
     * Builds a urlset document from pre-built `<url>` entries.
     *
     * @param string[] $urlEntries
     */
    public static function urlset(array $urlEntries, ?string $emptyReason = null): string
    {
        return self::_document('urlset', $urlEntries, $emptyReason, true);
    }

    /**
     * Builds one `<url>` entry with optional lastmod, hreflang alternates and
     * priority.
     *
     * @param array<int, SitemapAlternate> $alternates
     */
    public static function urlEntry(
        string $loc,
        ?string $lastmod = null,
        array $alternates = [],
        ?string $priority = null,
    ): string {
        $lines = ['  <url>', '    <loc>' . self::encode($loc) . '</loc>'];

        if ($lastmod !== null) {
            $lines[] = '    <lastmod>' . self::encode($lastmod) . '</lastmod>';
        }

        // Omitted unless explicitly set. Google and Bing both document that
        // they ignore <priority>, so emitting a value on every URL — as every
        // other Craft SEO plugin does by defaulting to 0.5 — is noise that
        // says nothing. Set it and it ships; leave it and nothing is claimed.
        if ($priority !== null) {
            $lines[] = '    <priority>' . self::encode($priority) . '</priority>';
        }

        foreach ($alternates as $alternate) {
            $lines[] = sprintf(
                '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                self::encode($alternate['hreflang']),
                self::encode($alternate['href']),
            );
        }

        $lines[] = '  </url>';

        return implode("\n", $lines);
    }

    /**
     * XML-encodes a value for element/attribute content.
     */
    public static function encode(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    // Private Methods
    // =========================================================================

    /**
     * Wraps items in a root element with the sitemap namespaces; an empty
     * document gets an explanatory XML comment instead of silent emptiness.
     *
     * @param string[] $items
     */
    private static function _document(string $root, array $items, ?string $emptyReason, bool $xhtmlNs): string
    {
        $ns = ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ($xhtmlNs) {
            $ns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', "<$root$ns>"];

        if ($items === []) {
            $reason = $emptyReason !== null ? str_replace('--', '—', $emptyReason) : 'no URLs';
            $lines[] = '  <!-- 0 URLs: ' . self::encode($reason) . ' -->';
        } else {
            $lines = [...$lines, ...$items];
        }

        $lines[] = "</$root>";

        return implode("\n", $lines) . "\n";
    }
}
