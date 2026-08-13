<?php

namespace anvildev\simpleseo\helpers;

use craft\elements\Asset;

/**
 * Memoized asset lookup by ID, with a false sentinel for misses.
 *
 * Shared by SeoData and SiteDefaults so the two ID-bearing models cannot
 * drift on "selected but deleted" vs "never selected".
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
trait MemoizesAsset
{
    // Private Methods
    // =========================================================================

    /**
     * Returns the asset for an ID, memoizing the lookup.
     *
     * @param int|null $id
     * @param Asset|false|null $memo false once looked up and missing
     */
    private function _assetById(?int $id, Asset|false|null &$memo): ?Asset
    {
        if ($id === null) {
            return null;
        }

        if ($memo === null) {
            $asset = Asset::find()->id($id)->one();
            $memo = $asset instanceof Asset ? $asset : false;
        }

        return $memo ?: null;
    }
}
