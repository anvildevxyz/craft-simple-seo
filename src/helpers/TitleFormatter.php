<?php

namespace anvildev\simpleseo\helpers;

use anvildev\simpleseo\models\ResolvedMeta;

/**
 * Applies the site title format to a meta title.
 *
 * Single source of truth for the title-format contract: the CP preview
 * mirrors format() in JS (seo-field.js formatSeoTitle — DEFAULT_TITLE_FORMAT
 * must match DEFAULT_FORMAT), front-end meta rendering reuses it, and
 * per-site configured formats route through here.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class TitleFormatter
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The default title format applied when no per-site format is
     * configured. `{title}` and `{siteName}` are the only tokens.
     */
    public const DEFAULT_FORMAT = '{title} - {siteName}';

    // Public Methods
    // =========================================================================

    /**
     * Formats a page title for the `<title>` tag / SERP display.
     *
     * Guards against the two documented ether/seo title bugs: the site name is
     * never doubled when the title already carries it (ethercreative/seo#330),
     * and a format without `{siteName}` never has the site name appended
     * (ethercreative/seo#376).
     *
     * @param string|null $title The author-entered meta title, if any
     * @param string $fallbackTitle The element title used when no meta title is set
     * @param string $siteName The element's site name
     * @param string|null $format The title format; null uses DEFAULT_FORMAT
     */
    public static function format(?string $title, string $fallbackTitle, string $siteName, ?string $format = null): string
    {
        return self::resolve($title, $fallbackTitle, $siteName, $format)[0];
    }

    /**
     * Whether a title format is usable: empty (falls back to DEFAULT_FORMAT)
     * or containing the `{title}` token so pages cannot all share one title.
     *
     * @param string|null $format the configured format, or null when unset
     */
    public static function isValidFormat(?string $format): bool
    {
        $format = trim((string)$format);

        return $format === '' || str_contains($format, '{title}');
    }

    /**
     * Formats a page title and names the input that won: the formatted
     * string plus a ResolvedMeta::SOURCE_* constant. The source is computed
     * beside the base selection, in the same function, so it can never
     * disagree with what format() actually did.
     *
     * @param string|null $title The author-entered meta title, if any
     * @param string $fallbackTitle The element title used when no meta title is set
     * @param string $siteName The element's site name
     * @param string|null $format The title format; null uses DEFAULT_FORMAT
     * @return array{0: string, 1: string} The formatted title and its source
     */
    public static function resolve(?string $title, string $fallbackTitle, string $siteName, ?string $format = null): array
    {
        $base = trim($title ?? $fallbackTitle);
        $format = $format ?: self::DEFAULT_FORMAT;

        $source = match (true) {
            $base === '' => ResolvedMeta::SOURCE_SITE_NAME,
            $title !== null => ResolvedMeta::SOURCE_FIELD,
            default => ResolvedMeta::SOURCE_ENTRY_TITLE,
        };

        if ($base === '') {
            return [$siteName, $source];
        }

        if ($siteName !== '' && str_contains($format, '{siteName}') && str_contains($base, $siteName)) {
            return [$base, $source];
        }

        return [trim(strtr($format, [
            '{title}' => $base,
            '{siteName}' => $siteName,
        ])), $source];
    }
}
