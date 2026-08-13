<?php

namespace anvildev\simpleseo\models;

use anvildev\simpleseo\helpers\MemoizesAsset;
use craft\base\Model;
use craft\elements\Asset;

/**
 * Resolved per-site SEO defaults — the merged view of project-config settings
 * (title format, default description) and the DB-side asset reference
 * (default social image). Always fully populated: with nothing configured it
 * carries the zero-config defaults.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SiteDefaults extends Model
{
    // Traits
    // =========================================================================

    use MemoizesAsset;

    // Public Properties
    // =========================================================================

    /**
     * @var string The title format for the site. Tokens: {title}, {siteName}.
     */
    public string $titleFormat = '';

    /**
     * @var string|null Fallback meta description used when an element has none.
     */
    public ?string $defaultDescription = null;

    /**
     * @var int|null Asset ID of the site-wide fallback social image.
     */
    public ?int $defaultSocialImageId = null;

    // Private Properties
    // =========================================================================

    /**
     * @var Asset|false|null Memoized asset lookup (false = not found).
     */
    private Asset|false|null $_socialImage = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the fallback social image asset, if configured and still present.
     */
    public function getDefaultSocialImage(): ?Asset
    {
        return $this->_assetById($this->defaultSocialImageId, $this->_socialImage);
    }
}
