<?php

namespace anvildev\simpleseo\tests\unit\helpers;

use anvildev\simpleseo\helpers\SiteUrl;
use PHPUnit\Framework\TestCase;

/**
 * The CP preview and the rendered og:image resolve social image URLs through
 * this one helper, so they cannot drift apart (ethercreative/seo#395). The
 * relative-URL branch was untested while the logic lived in two private
 * methods — every fixture used an absolute filesystem URL and took the early
 * return.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SiteUrlTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * @dataProvider absolutizeProvider
     */
    public function testAbsolutize(?string $url, string $baseUrl, ?string $expected): void
    {
        $this->assertSame($expected, SiteUrl::absolutize($url, $baseUrl));
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string|null}>
     */
    public static function absolutizeProvider(): array
    {
        return [
            'null stays null' => [null, 'https://example.com', null],
            'empty becomes null' => ['', 'https://example.com', null],
            'absolute passes through' => ['https://cdn.test/a.png', 'https://example.com', 'https://cdn.test/a.png'],
            // Inherited behaviour, pinned rather than endorsed:
            // UrlHelper::isAbsoluteUrl() is false for a protocol-relative URL,
            // so it gets joined to the base. Both implementations this helper
            // replaced did the same, so extraction keeps it. Only reachable
            // with a filesystem base URL like `//cdn.example.com`, where the
            // result would be a broken og:image.
            'protocol-relative is treated as relative' => [
                '//cdn.test/a.png',
                'https://example.com',
                'https://example.com/cdn.test/a.png',
            ],
            'relative joins base' => ['uploads/a.png', 'https://example.com', 'https://example.com/uploads/a.png'],
            'base trailing slash' => ['uploads/a.png', 'https://example.com/', 'https://example.com/uploads/a.png'],
            'leading slash' => ['/uploads/a.png', 'https://example.com', 'https://example.com/uploads/a.png'],
            'both slashes collapse to one' => ['/uploads/a.png', 'https://example.com/', 'https://example.com/uploads/a.png'],
            'base with a path' => ['a.png', 'https://example.com/site', 'https://example.com/site/a.png'],
            // A site with no base URL cannot be absolutized against; returning
            // the relative URL beats inventing a host.
            'empty base passes through' => ['uploads/a.png', '', 'uploads/a.png'],
        ];
    }
}
