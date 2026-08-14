<?php

namespace anvildev\simpleseo\helpers;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Site;

/**
 * Shared target resolution for the console commands and the MCP tools.
 *
 * Both surfaces accept the same arguments — an entry ID, an optional site
 * handle — and must apply the same policy (any status, bad site handle
 * reported as such rather than as a missing entry) and the same error
 * wording. On failure the methods return the printable message instead of
 * the target, so the wording exists exactly once.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class Lookup
{
    // Public Methods
    // =========================================================================

    /**
     * Finds an entry by ID in any status, optionally on one site.
     *
     * @return Entry|string The entry, or a printable error message. A bad
     * site handle reports as exactly that, never as a missing entry.
     */
    public static function entry(int $id, ?string $siteHandle = null): Entry|string
    {
        $query = Entry::find()->id($id)->status(null);

        if ($siteHandle !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site === null) {
                return "No site with handle “{$siteHandle}”.";
            }
            $query->siteId($site->id);
        }

        // one() is typed array|ElementInterface|null because asArray() can
        // change the shape; it never does here.
        $entry = $query->one();
        if (!$entry instanceof Entry) {
            return "No entry with ID $id" . ($siteHandle !== null ? " on site “{$siteHandle}”" : '') . '.';
        }

        return $entry;
    }

    /**
     * The named section, or a printable error message.
     *
     * @return Section|string
     */
    public static function section(string $handle): Section|string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        if ($section === null) {
            return "No section with handle “{$handle}”.";
        }

        return $section;
    }

    /**
     * The named site, or the primary site when no handle is given.
     *
     * @return Site|string The site, or a printable error message.
     */
    public static function site(?string $handle = null): Site|string
    {
        if ($handle === null) {
            return Craft::$app->getSites()->getPrimarySite();
        }

        $site = Craft::$app->getSites()->getSiteByHandle($handle);
        if ($site === null) {
            return "No site with handle “{$handle}”.";
        }

        return $site;
    }
}
