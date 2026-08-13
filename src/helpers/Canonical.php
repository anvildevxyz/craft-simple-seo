<?php

namespace anvildev\simpleseo\helpers;

/**
 * Canonical URL normalization — pure functions, no app state.
 *
 * Ether kept three canonical bugs open for years: UTF-8 slugs emitted raw
 * (ethercreative/seo#508, #502), query params never handled (#485), and
 * paginated pages canonicalizing to page one (#335). Every canonical this
 * plugin emits — tag and header alike — passes through normalize(), so the
 * two can never disagree and the encoding is always correct.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class Canonical
{
    // Public Methods
    // =========================================================================

    /**
     * Normalizes a URL for canonical use: percent-encodes each path segment
     * (idempotently — already-encoded input is not double-encoded), filters
     * query params against the allowlist (or keeps them all for
     * author-entered URLs), and strips fragments and credentials.
     *
     * @param string[] $allowedQueryParams Query params to keep; ignored when
     * $keepAllQueryParams is true
     */
    public static function normalize(string $url, array $allowedQueryParams = [], bool $keepAllQueryParams = false): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '') {
            $segments = array_map(
                static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
                explode('/', $path),
            );
            $path = implode('/', $segments);
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            if (!$keepAllQueryParams) {
                $params = array_intersect_key($params, array_flip($allowedQueryParams));
            }
            if ($params !== []) {
                $query = str_replace('%2F', '/', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            }
        }

        return self::_origin($parts) . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Appends the page reference for paginated requests, honoring Craft's
     * pageTrigger style — path segments (`/p3`) or query string (`?page=3`).
     * Page one returns the URL untouched (ethercreative/seo#335).
     */
    public static function paginated(string $url, int $pageNum, string $pageTrigger): string
    {
        if ($pageNum <= 1) {
            return $url;
        }

        if (str_starts_with($pageTrigger, '?')) {
            $param = trim($pageTrigger, '?=');
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url . $separator . rawurlencode($param) . '=' . $pageNum;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $path = rtrim($parts['path'] ?? '', '/') . '/' . $pageTrigger . $pageNum;

        return self::_origin($parts) . $path . $query;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reassembles the scheme://host:port prefix from parse_url() parts —
     * empty for relative URLs, credentials (and everything else) dropped.
     *
     * @param array<string, string|int> $parts
     */
    private static function _origin(array $parts): string
    {
        $origin = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $origin .= $parts['host'] ?? '';

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
