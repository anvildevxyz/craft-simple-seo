<?php

namespace anvildev\simpleseo\helpers;

use craft\helpers\UrlHelper;

/**
 * Site-relative → absolute URL resolution.
 *
 * The CP preview and the rendered `og:image` have to show the same URL, even
 * when the CP runs on a different domain to the site (ethercreative/seo#395).
 * This is the one implementation both call, so the two cannot fall out of
 * step.
 *
 * Pure and app-free, so the base URL arrives as a string rather than a Site —
 * that is what makes it unit-testable without booting Craft.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class SiteUrl
{
    // Public Methods
    // =========================================================================

    /**
     * Absolutizes a URL against a site base URL. Empty in → null out;
     * already-absolute URLs, and sites with no base URL, pass through
     * unchanged.
     */
    public static function absolutize(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (UrlHelper::isAbsoluteUrl($url)) {
            return $url;
        }

        $base = rtrim($baseUrl, '/');

        return $base !== '' ? $base . '/' . ltrim($url, '/') : $url;
    }
}
