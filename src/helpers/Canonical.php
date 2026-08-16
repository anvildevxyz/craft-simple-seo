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
     * The query is processed pair by pair, never through parse_str() — PHP's
     * variable rules would rename `utm.id` to `utm_id`, collapse repeated
     * params to the last value, and stamp `=` onto valueless params. Names
     * survive verbatim; each name and value is re-encoded idempotently, the
     * same way as path segments.
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
            $path = implode('/', array_map(
                static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
                explode('/', $path),
            ));
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $hasValue = str_contains($pair, '=');
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                // urldecode(), not rawurldecode(): `+` means a space in a
                // query string, and must re-encode as %20, not %2B.
                $name = urldecode($name);
                if ($name === '') {
                    continue;
                }
                // `page` also covers `page[0]` — an allowlisted array param
                // keeps every member.
                $base = (string)preg_replace('/\[.*/s', '', $name);
                if (!$keepAllQueryParams && !in_array($base, $allowedQueryParams, true)) {
                    continue;
                }
                $pairs[] = rawurlencode($name) . ($hasValue ? '=' . rawurlencode(urldecode($value)) : '');
            }
            if ($pairs !== []) {
                // Slashes stay readable: Craft path-param URLs
                // (`?p=some/long/path`) must survive as the URLs they describe.
                $query = str_replace('%2F', '/', implode('&', $pairs));
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
     * A host without a scheme keeps its `//`: a protocol-relative input
     * must not degrade into a relative path.
     *
     * @param array<string, string|int> $parts
     */
    private static function _origin(array $parts): string
    {
        $origin = match (true) {
            isset($parts['scheme']) => $parts['scheme'] . '://',
            isset($parts['host']) => '//',
            default => '',
        };
        $origin .= $parts['host'] ?? '';

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
