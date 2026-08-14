<?php

namespace anvildev\simpleseo\fields\data;

use anvildev\simpleseo\helpers\MemoizesAsset;
use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\validators\UrlValidator;

/**
 * Value object for the SEO field.
 *
 * Everything is nullable/off by default so entries that existed before the
 * field was added — or that authors never touched — normalize to a value that
 * renders graceful defaults instead of erroring.
 *
 * @phpstan-type SeoDataArray array{title: string|null, description: string|null, socialImageId: int|null, noindex: bool, nofollow: bool, canonical: string|null, robotsDirectives: string[]}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoData extends Model
{
    // Traits
    // =========================================================================

    use MemoizesAsset;

    // Const Properties
    // =========================================================================

    /**
     * @var string[] Supported extra robots directives, in the order shown in
     * the CP. A deliberate subset of Google's list: each one changes what a
     * result looks like, and none duplicate the noindex/nofollow toggles.
     * Unknown values are dropped on normalization rather than passed through
     * — a typo'd directive is worse than none, because it looks like it works.
     */
    public const ROBOTS_DIRECTIVES = [
        'noarchive',
        'nosnippet',
        'noimageindex',
        'notranslate',
        'nositelinkssearchbox',
        'indexifembedded',
        'max-image-preview:large',
        'max-image-preview:none',
        'max-snippet:0',
        'max-video-preview:0',
    ];

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Meta title override. Null falls back to the element title.
     */
    public ?string $title = null;

    /**
     * @var string|null Meta description override.
     */
    public ?string $description = null;

    /**
     * @var int|null Asset ID of the social share image.
     */
    public ?int $socialImageId = null;

    /**
     * @var bool Whether this element asks search engines not to index it.
     */
    public bool $noindex = false;

    /**
     * @var bool Whether this element asks search engines not to follow its links.
     */
    public bool $nofollow = false;

    /**
     * @var string|null Absolute canonical URL override. Null falls back to the
     * element's own URL.
     */
    public ?string $canonical = null;

    /**
     * @var string[] Extra robots directives beyond noindex/nofollow — the
     * ones Google documents but most pages never need (see
     * {@see self::ROBOTS_DIRECTIVES}). Kept separate from the two toggles so
     * the common case stays two switches, not a directive soup.
     */
    public array $robotsDirectives = [];

    // Private Properties
    // =========================================================================

    /**
     * @var Asset|false|null Memoized social image lookup (false = not found).
     */
    private Asset|false|null $_socialImage = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the social share image asset, if one is selected and still exists.
     */
    public function getSocialImage(): ?Asset
    {
        return $this->_assetById($this->socialImageId, $this->_socialImage);
    }

    /**
     * Whether the value carries no author-entered data at all.
     */
    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->description === null
            && $this->socialImageId === null
            && $this->noindex === false
            && $this->nofollow === false
            && $this->canonical === null
            && $this->robotsDirectives === [];
    }

    /**
     * The full robots directive string for this element, or null when the
     * element asks for nothing unusual (absent beats `index, follow` — the
     * default needs no tag).
     */
    public function robots(): ?string
    {
        $directives = [];

        if ($this->noindex) {
            $directives[] = 'noindex';
        }
        if ($this->nofollow) {
            $directives[] = 'nofollow';
        }

        $directives = [...$directives, ...self::canonicalizeDirectives($this->robotsDirectives)];

        return $directives !== [] ? implode(', ', $directives) : null;
    }

    /**
     * Restricts directives to the supported set, deduplicated, in the
     * constant's canonical order — not the order checkboxes happened to
     * post in — so storage, settings, and rendered output are stable across
     * saves and can never disagree on ordering.
     *
     * @param string[] $directives
     * @return list<value-of<self::ROBOTS_DIRECTIVES>>
     */
    public static function canonicalizeDirectives(array $directives): array
    {
        return array_values(array_filter(
            self::ROBOTS_DIRECTIVES,
            static fn(string $directive): bool => in_array($directive, $directives, true),
        ));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return array<int, array<array-key, mixed>|\yii\validators\Validator>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title', 'description'], 'string', 'max' => 1000];
        $rules[] = [
            ['canonical'],
            UrlValidator::class,
            'message' => Craft::t('simple-seo', 'The canonical override must be a full URL, including https://.'),
        ];

        return $rules;
    }
}
