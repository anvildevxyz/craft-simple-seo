<?php

namespace anvildev\simpleseo\web\assets\seofield;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the SEO field input.
 *
 * One delegated listener + counter styles. There is intentionally no
 * per-instance JS init, so the field needs no re-initialisation inside
 * slideouts, Matrix blocks, or element editors.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoFieldAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        // dist/ is hand-authored source, not a build output.
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['seo-field.js'];
        $this->css = ['seo-field.css'];

        parent::init();
    }
}
