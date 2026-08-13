<?php

namespace anvildev\simpleseo\models;

use anvildev\simpleseo\helpers\TitleFormatter;
use Craft;
use craft\base\Model;
use yii\base\InvalidArgumentException;

/**
 * Plugin settings model.
 *
 * Holds only project-config-safe structure: per-site title formats and
 * default descriptions, keyed by site UID (stable across environments).
 * Asset references (the default social image) are deliberately NOT here —
 * they live DB-side via SiteDefaultsService, so `allowAdminChanges: false`
 * environments keep working (ethercreative/seo#243).
 *
 * @phpstan-type SiteSeoConfig array{titleFormat?: string|null, defaultDescription?: string|null}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Settings extends Model
{
    // Constants
    // =========================================================================

    /**
     * @var string[] Settings that live only in `config/simple-seo.php` and are
     * never written to project config. Everything else on this model is
     * CP-editable and must survive every save — see projectConfigPayload().
     */
    public const CONFIG_FILE_ONLY = [
        'canonicalLinkHeader',
        'canonicalAllowedQueryParams',
        'siteWideNoindex',
    ];

    // Public Properties
    // =========================================================================

    /**
     * @var array<string, SiteSeoConfig> Per-site settings keyed by site UID.
     */
    public array $siteSettings = [];

    /**
     * @var bool Whether to emit a `Link: <…>; rel="canonical"` HTTP header
     * alongside the tag. The header and tag always carry the identical URL
     * (ethercreative/seo#516); set false in `config/simple-seo.php` to
     * disable the header entirely (ethercreative/seo#423).
     */
    public bool $canonicalLinkHeader = true;

    /**
     * @var string[] Query params kept on element-derived canonical URLs. By
     * default every param is stripped (ethercreative/seo#485); author-entered
     * canonical overrides always keep their params verbatim.
     */
    public array $canonicalAllowedQueryParams = [];

    /**
     * @var array<string, bool> Sites where this plugin does NOT serve
     * `/sitemap.xml`, keyed by site UID. Only disabled sites are stored, so a
     * site added later serves one without anyone opting it in.
     *
     * Turning it off unregisters the routes rather than 404ing them, which is
     * the point: a site serving its own sitemap from a template or another
     * plugin needs those URLs to fall through to normal routing.
     */
    public array $sitemapEnabled = [];

    /**
     * @var array<string, bool> Sites where this plugin does NOT serve
     * `/robots.txt`, keyed by site UID. Stored and resolved exactly like
     * {@see self::$sitemapEnabled}.
     *
     * `siteWideNoindex` overrides this: a lockdown that a settings toggle can
     * poke a hole in is not a lockdown (ethercreative/seo#244).
     */
    public array $robotsTxtEnabled = [];

    /**
     * @var array<string, string[]> Section UIDs excluded from the sitemap,
     * keyed by site UID. Default empty: every section with URLs is included —
     * the zero-config sitemap works immediately.
     */
    public array $sitemapExcludedSections = [];

    /**
     * @var array<string, array<string, string>> Per-section sitemap priority,
     * keyed by site UID then section UID. A section with no entry emits no
     * `<priority>` at all — Google and Bing both document that they ignore
     * the tag, so the default is to claim nothing rather than stamp 0.5 on
     * every URL the way other plugins do.
     */
    public array $sitemapPriorities = [];

    /**
     * @var array<string, string> Author-authored robots.txt bodies, keyed by
     * site UID. A site with no entry serves the shipped default (open, with a
     * sitemap reference) — the zero-config path stays zero-config. Content is
     * served verbatim apart from token expansion; it is never rendered as
     * Twig, so a settings field can't become a code-execution surface.
     */
    public array $robotsTxt = [];

    /**
     * @var string[] Which SEO field controls are offered at all, across every
     * field on the install (keys from SeoField::SUBFIELDS). A field can only
     * enable what is listed here, so this is the master switch and the
     * per-field setting picks from it.
     *
     * Empty means "not configured" rather than "nothing available" — a fresh
     * install offers everything, and the resolution treats an empty list as
     * the full set so nobody can lock every control off by accident.
     */
    public array $availableSubfields = [];

    /**
     * @var bool THE robots invariant flag. False (always the default) means
     * this plugin can never emit a site-wide noindex — ether/seo silently
     * de-indexed entire live sites this way (ethercreative/seo#244, its
     * most-commented issue ever). Set true ONLY via `config/simple-seo.php`
     * (typically env-gated for staging): every front-end response then gets
     * `X-Robots-Tag: noindex, nofollow`, rendered meta forces the same,
     * robots.txt disallows everything, and the CP shows a persistent warning
     * banner. There is deliberately no CP control for this.
     */
    public bool $siteWideNoindex = false;

    // Public Methods
    // =========================================================================

    /**
     * The full payload for `savePluginSettings()`, with the caller's changed
     * groups merged over the current values.
     *
     * Craft writes back exactly the keys it is handed
     * (`Plugins::savePluginSettings()` → `toArray(array_keys($settings))`) and
     * `ProjectConfig::set()` replaces the node rather than merging it, so any
     * group left out of a save is DELETED. Every screen therefore has to send
     * every group, and that set is derived here rather than restated at each
     * call site — three of the four once drifted, silently wiping robots.txt.
     *
     * @param array<string, mixed> $changed
     * @return array<string, mixed>
     * @throws InvalidArgumentException if a group name is not a CP-editable setting
     */
    public function projectConfigPayload(array $changed = []): array
    {
        $current = $this->getAttributes(null, self::CONFIG_FILE_ONLY);

        // A mistyped group would otherwise sail through array_merge() and land
        // in project config as a junk node. An array shape can't catch this —
        // PHPStan does not reject extra keys when every shape key is optional.
        $unknown = array_diff(array_keys($changed), array_keys($current));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown settings group(s): %s. Expected one of: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys($current)),
            ));
        }

        return array_merge($current, $changed);
    }

    /**
     * Merges one site's on/off choice into a per-site toggle map.
     *
     * Only disabled sites are stored. An absent site therefore means "on",
     * which is what lets a site created later serve a sitemap and robots.txt
     * without anyone opting it in — the same reason sitemap section choices
     * are stored inverted.
     *
     * @param array<string, bool> $map
     * @return array<string, bool>
     */
    public static function withSiteToggle(array $map, string $siteUid, bool $enabled): array
    {
        unset($map[$siteUid]);
        if (!$enabled) {
            $map[$siteUid] = false;
        }

        return $map;
    }

    /**
     * Validates each site's title format: a non-empty format must contain the
     * {title} token, or every page on the site would share one title
     * (ethercreative/seo#472-class misconfiguration, caught at save).
     */
    public function validateSiteSettings(): void
    {
        foreach ($this->siteSettings as $siteUid => $config) {
            if (!TitleFormatter::isValidFormat($config['titleFormat'] ?? null)) {
                $this->addError(
                    "siteSettings.$siteUid.titleFormat",
                    Craft::t('simple-seo', 'The title format must include the {token} token.', ['token' => '{title}']),
                );
            }
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return array<int, array<array-key, mixed>|\yii\validators\Validator>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['siteSettings'], 'validateSiteSettings'];

        return $rules;
    }
}
