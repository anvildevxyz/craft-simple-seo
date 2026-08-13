<?php

namespace anvildev\simpleseo\web\twig\variables;

use anvildev\simpleseo\models\ResolvedMeta;
use anvildev\simpleseo\Plugin;
use craft\base\ElementInterface;
use Twig\Markup;

/**
 * `craft.simpleSeo` template variable.
 *
 * One-line integration: `{{ craft.simpleSeo.renderMeta(entry) }}` in the
 * layout head. `resolveMeta()` returns the identical data as an array for
 * headless/JSON consumers.
 *
 * @phpstan-import-type ResolvedMetaArray from ResolvedMeta
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SimpleSeoVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Renders the full set of head tags for an element (or the current site).
     *
     * @param array<string, string|null> $overrides e.g. `{ ogType: 'article' }`
     * @throws \yii\base\InvalidArgumentException on unknown override keys
     */
    public function renderMeta(?ElementInterface $element = null, array $overrides = []): Markup
    {
        return Plugin::getInstance()->getMeta()->renderTags($element, $overrides);
    }

    /**
     * Returns the resolved meta as an array — the same data renderMeta()
     * serializes to tags.
     *
     * @param array<string, string|null> $overrides
     * @return ResolvedMetaArray
     * @throws \yii\base\InvalidArgumentException on unknown override keys
     */
    public function resolveMeta(?ElementInterface $element = null, array $overrides = []): array
    {
        /** @var ResolvedMetaArray $meta */
        $meta = Plugin::getInstance()->getMeta()->resolve($element, $overrides)->toArray();

        return $meta;
    }
}
