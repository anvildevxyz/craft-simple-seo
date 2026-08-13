<?php

namespace anvildev\simpleseo\models;

use craft\base\Model;

/**
 * Fully resolved meta for one page: every fallback already applied, every
 * value final. Twig rendering serializes this to tags; headless consumers get
 * it as an array — same data either way.
 *
 * @phpstan-type ResolvedMetaArray array{title: string, socialTitle: string, description: string|null, canonical: string|null, robots: string|null, ogType: string, ogSiteName: string, ogImageUrl: string|null, twitterCard: string}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ResolvedMeta extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The value came from a per-call override.
     */
    public const SOURCE_OVERRIDE = 'override';

    /**
     * @var string The value came from the element's SEO field.
     */
    public const SOURCE_FIELD = 'field';

    /**
     * @var string The value came from the per-site default.
     */
    public const SOURCE_SITE_DEFAULT = 'site-default';

    /**
     * @var string The value fell back to the element's own title.
     */
    public const SOURCE_ENTRY_TITLE = 'entry-title';

    /**
     * @var string The value fell back to the site name.
     */
    public const SOURCE_SITE_NAME = 'site-name';

    /**
     * @var string The value was derived from the element's own URL.
     */
    public const SOURCE_ELEMENT_URL = 'element-url';

    /**
     * @var string The value was forced by the siteWideNoindex config flag.
     */
    public const SOURCE_LOCKDOWN = 'site-wide-noindex';

    /**
     * @var string Nothing produced a value; the tag is not emitted.
     */
    public const SOURCE_NONE = 'none';

    // Public Properties
    // =========================================================================

    /**
     * @var array<string, string> Where each resolved value came from, keyed
     * by attribute name, as SOURCE_* constants. Covers title, description,
     * canonical, robots, and ogImageUrl — the values with a fallback chain.
     */
    public array $sources = [];

    /**
     * @var string The formatted document title (site title-format applied).
     */
    public string $title = '';

    /**
     * @var string The bare title for social cards (no site-name suffix).
     */
    public string $socialTitle = '';

    /**
     * @var string|null The meta description; null renders no description tags.
     */
    public ?string $description = null;

    /**
     * @var string|null The canonical URL; null renders no canonical/og:url tags.
     */
    public ?string $canonical = null;

    /**
     * @var string|null The robots directive ("noindex", "nofollow",
     * "noindex, nofollow"); null renders no robots tag — absent means the
     * default index,follow. Site-wide noindex is only possible via the
     * config-file-only siteWideNoindex setting, never from CP state.
     */
    public ?string $robots = null;

    /**
     * @var string The Open Graph type.
     */
    public string $ogType = 'website';

    /**
     * @var string The Open Graph site name.
     */
    public string $ogSiteName = '';

    /**
     * @var string|null Absolute URL of the social image; null renders no image tags.
     */
    public ?string $ogImageUrl = null;

    /**
     * @var string The Twitter card type.
     */
    public string $twitterCard = 'summary';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Provenance is tooling metadata, not page data — kept out of the array
     * so the headless/Twig output keeps its stable shape.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['sources']);

        return $fields;
    }
}
