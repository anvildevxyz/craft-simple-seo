<?php

namespace anvildev\simpleseo\models;

use craft\base\Model;

/**
 * Result of an ether/seo migration run (dry or applied): what was found,
 * what was (or would be) converted, and what was deliberately dropped —
 * nothing disappears silently.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class EtherMigrationReport extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether this run wrote changes (false = dry run).
     */
    public bool $applied = false;

    /**
     * @var bool Whether this run carried across the ether settings that have
     * a faithful equivalent here. Off by default: the migration reports those
     * settings rather than acting on them, because the one that matters most
     * de-indexes pages.
     */
    public bool $carrySettings = false;

    /**
     * @var array<int, array{handle: string, name: string, uid: string, layoutElements: int}>
     * The ether SEO fields found.
     */
    public array $fields = [];

    /**
     * @var int Ether-shaped field values found.
     */
    public int $etherValues = 0;

    /**
     * @var int Values converted (dry run: would be converted).
     */
    public int $converted = 0;

    /**
     * @var int Values already in Simple SEO's shape (previous run) — skipped.
     */
    public int $alreadyMigrated = 0;

    /**
     * @var array<int, int> Converted values per site ID.
     */
    public array $perSite = [];

    /**
     * @var int Titles mapped.
     */
    public int $titles = 0;

    /**
     * @var int Descriptions mapped.
     */
    public int $descriptions = 0;

    /**
     * @var int Social images mapped (from ether's twitter/facebook image).
     */
    public int $images = 0;

    /**
     * @var int Values whose noindex/nofollow toggles mapped.
     */
    public int $robots = 0;

    /**
     * @var int Values carrying at least one of ether's extra robots directives
     * (noarchive, nosnippet, notranslate, noimageindex), all of which Simple
     * SEO renders too.
     */
    public int $directives = 0;

    /**
     * @var int Canonicals mapped.
     */
    public int $canonicals = 0;

    /**
     * @var int Ether focus-keyword sets dropped — Simple SEO has no content
     * analysis on purpose. Reported, never silent.
     */
    public int $droppedKeywords = 0;

    /**
     * @var int Per-network social values dropped: a network's own title or
     * description, and a second image differing from the one carried over.
     * Simple SEO renders one set for every network. Reported, never silent.
     */
    public int $droppedSocialFields = 0;

    /**
     * @var int Robots directives dropped because Simple SEO does not render
     * them. Reported, never silent.
     */
    public int $droppedDirectives = 0;

    /**
     * @var string[] Ether's SITE-WIDE robots directives, from its plugin
     * settings. Ether applies these to every element whose own robots is
     * empty, so they are a live indexing rule, not a preference.
     */
    public array $etherSiteWideRobots = [];

    /**
     * @var int Converted values carrying no robots of their own, which ether
     * was therefore serving {@see self::$etherSiteWideRobots} for. Simple SEO
     * deliberately has no settings-screen equivalent, so these render no
     * robots tag after the migration.
     */
    public int $inheritedSiteWideRobots = 0;

    /**
     * @var int Values that were given ether's site-wide directives because
     * {@see self::$carrySettings} was on. Without it these are only counted,
     * in {@see self::$inheritedSiteWideRobots}.
     */
    public int $carriedSiteWideRobots = 0;

    /**
     * @var int Sections excluded from the sitemap, per site, because ether had
     * them switched off and {@see self::$carrySettings} was on.
     */
    public int $carriedSitemapExclusions = 0;

    /**
     * @var array<string, string[]> Ether FIELD-level settings that do not
     * carry over, keyed by field handle. Ether's per-field defaults have no
     * per-field equivalent here — Simple SEO's defaults are per site.
     */
    public array $droppedFieldSettings = [];

    /**
     * @var int Rows in ether's sitemap table. Simple SEO manages sitemaps in
     * its own per-site settings, so none of it is imported.
     */
    public int $sitemapRowsFound = 0;

    /**
     * @var string[] Sources ether had switched OFF in its sitemap. These are
     * the ones whose absence changes the output: Simple SEO includes every
     * section with URLs until told otherwise.
     */
    public array $sitemapDisabledSources = [];

    /**
     * @var int Redirect rows found in ether's table.
     */
    public int $redirectsFound = 0;

    /**
     * @var string|null Where the Retour-importable CSV was (or would be) written.
     */
    public ?string $redirectsCsvPath = null;

    /**
     * @var array<string, mixed>|null Ether's plugin settings from project
     * config, surfaced for manual review — ether's title templates have no
     * clean equivalent, so nothing is guessed.
     */
    public ?array $etherSettings = null;

    /**
     * @var string[] Human-readable notes and follow-ups.
     */
    public array $notes = [];

    /**
     * @var string[] Steps that genuinely failed during an applied run. A
     * non-empty list means the migration is partial, and the console command
     * exits non-zero so a deploy script cannot read success from it.
     */
    public array $failures = [];
}
