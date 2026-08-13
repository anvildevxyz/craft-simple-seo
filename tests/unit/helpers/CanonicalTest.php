<?php

namespace anvildev\simpleseo\tests\unit\helpers;

use anvildev\simpleseo\helpers\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * Canonical normalization contract — the ether regression suite
 * (ethercreative/seo#508, #502, #485, #335 by construction).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CanonicalTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * UTF-8 slugs are percent-encoded (ether #508).
     */
    public function testUtf8PathIsEncoded(): void
    {
        $this->assertSame(
            'https://example.com/%C3%BCber-uns',
            Canonical::normalize('https://example.com/über-uns'),
        );
    }

    /**
     * Already-encoded input is not double-encoded (ether #502-class).
     */
    public function testAlreadyEncodedIsNotDoubleEncoded(): void
    {
        $this->assertSame(
            'https://example.com/%C3%BCber-uns',
            Canonical::normalize('https://example.com/%C3%BCber-uns'),
        );
    }

    /**
     * A literal percent sign that is not an escape gets encoded safely.
     */
    public function testLiteralPercentIsEncoded(): void
    {
        $this->assertSame(
            'https://example.com/100%25-off',
            Canonical::normalize('https://example.com/100%-off'),
        );
    }

    /**
     * Query params are stripped by default (ether #485).
     */
    public function testQueryParamsStrippedByDefault(): void
    {
        $this->assertSame(
            'https://example.com/page',
            Canonical::normalize('https://example.com/page?utm_source=x&fbclid=y'),
        );
    }

    /**
     * Allowlisted params survive; everything else is stripped.
     */
    public function testAllowlistKeepsParams(): void
    {
        $this->assertSame(
            'https://example.com/page?category=news',
            Canonical::normalize('https://example.com/page?utm_source=x&category=news', ['category']),
        );
    }

    /**
     * Author-entered canonicals keep all their params, RFC3986-encoded.
     */
    public function testKeepAllPreservesParams(): void
    {
        $this->assertSame(
            'https://example.com/page?ref=a%20b',
            Canonical::normalize('https://example.com/page?ref=a b', [], true),
        );
    }

    /**
     * Slashes in query values stay readable — Craft path-param URLs
     * (`?p=some/long/path`) must survive as the URLs they describe.
     */
    public function testQuerySlashesStayReadable(): void
    {
        $this->assertSame(
            'https://example.com/index.php?p=gql-full/bare',
            Canonical::normalize('https://example.com/index.php?p=gql-full/bare', ['p']),
        );
    }

    /**
     * Fragments never belong on a canonical.
     */
    public function testFragmentIsStripped(): void
    {
        $this->assertSame(
            'https://example.com/page',
            Canonical::normalize('https://example.com/page#section-2'),
        );
    }

    /**
     * Ports and relative URLs survive normalization.
     */
    public function testPortAndRelativeUrls(): void
    {
        $this->assertSame(
            'https://example.com:8080/x',
            Canonical::normalize('https://example.com:8080/x'),
        );
        $this->assertSame(
            '/%C3%BCber/uns',
            Canonical::normalize('/über/uns'),
        );
    }

    /**
     * Path-style pagination appends the trigger segment; page one is
     * untouched (ether #335).
     */
    public function testPaginatedPathStyle(): void
    {
        $this->assertSame(
            'https://example.com/blog/p3',
            Canonical::paginated('https://example.com/blog', 3, 'p'),
        );
        $this->assertSame(
            'https://example.com/blog',
            Canonical::paginated('https://example.com/blog', 1, 'p'),
        );
    }

    /**
     * Query-style pagination appends the page param.
     */
    public function testPaginatedQueryStyle(): void
    {
        $this->assertSame(
            'https://example.com/blog?page=3',
            Canonical::paginated('https://example.com/blog', 3, '?page='),
        );
    }
}
