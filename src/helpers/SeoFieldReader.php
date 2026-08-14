<?php

namespace anvildev\simpleseo\helpers;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Json;

/**
 * Locates an element's SEO field value regardless of the field's handle.
 *
 * Elements whose layout carries no SEO field — or sections where only some
 * entry types include it (ethercreative/seo#262) — resolve to null instead of
 * erroring, so every downstream consumer can fall back to site defaults.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class SeoFieldReader
{
    // Private Properties
    // =========================================================================

    /**
     * @var string[]|null Memoized field-layout-element UIDs of every SEO field
     * placement — the keys its values live under in elements_sites.content.
     */
    private static ?array $_layoutElementUids = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the element's SEO field, or null when the element (or its
     * layout) has none. The single locator of an element's SEO field —
     * first match wins, whatever the field's handle.
     */
    public static function field(?ElementInterface $element): ?SeoField
    {
        $layout = $element?->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof SeoField) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Returns the element's SEO field value, or null when the element (or its
     * layout) has none.
     */
    public static function read(?ElementInterface $element): ?SeoData
    {
        $field = self::field($element);
        if ($field === null) {
            return null;
        }

        /** @var ElementInterface $element field() returned non-null, so the element exists */
        $value = $element->getFieldValue($field->handle);

        return $value instanceof SeoData ? $value : null;
    }

    /**
     * Whether a raw elements_sites content document carries a noindexed SEO
     * value — WITHOUT hydrating an element. This is the sitemap's hot path:
     * checking thousands of rows must cost thousands of JSON decodes, not
     * thousands of element materializations.
     *
     * @param string|array<array-key, mixed>|null $content The raw content
     * column value: a JSON string (MySQL), a decoded array (PostgreSQL), or
     * null.
     */
    public static function noindexFromContent(mixed $content): bool
    {
        if (is_string($content) && !str_contains($content, 'noindex')) {
            return false;
        }

        $content = self::decodeContentDocument($content);
        if ($content === null) {
            return false;
        }

        foreach (self::_layoutElementUids() as $uid) {
            $value = self::decodeFieldValue($content, $uid);
            if ($value !== null && ($value['noindex'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decodes a raw elements_sites.content document: a JSON string (MySQL),
     * an already-decoded array (PostgreSQL), sometimes double-encoded. The
     * one tolerant parse both the sitemap hot path and the ether migration
     * rely on — a cross-DB shape fix must land in exactly one place.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodeContentDocument(mixed $content): ?array
    {
        $content = Json::decodeIfJson($content);
        if (is_string($content)) {
            $content = Json::decodeIfJson($content);
        }

        return is_array($content) ? $content : null;
    }

    /**
     * One field's value out of a decoded content document, tolerating a
     * still-encoded nested value. Null when the key is absent or the value
     * is not an object.
     *
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>|null
     */
    public static function decodeFieldValue(array $document, string $layoutElementUid): ?array
    {
        $value = $document[$layoutElementUid] ?? null;
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Clears the memoized layout-element UIDs. Called on sitemap
     * invalidation so long-running processes (queue workers, tests) pick up
     * layout changes.
     */
    public static function clearMemos(): void
    {
        self::$_layoutElementUids = null;
    }

    /**
     * The field-layout-element UIDs where the given fields are placed, straight
     * from the stored layout configs — the keys their values live under in
     * elements_sites.content. Shared by the sitemap's noindex check and the
     * ether migration, which writes values under keys this walk must find.
     *
     * @param string[] $fieldUids
     * @return string[]
     */
    public static function elementUidsForFieldUids(array $fieldUids): array
    {
        if ($fieldUids === []) {
            return [];
        }

        $configs = (new Query())
            ->select(['config'])
            ->from(Table::FIELDLAYOUTS)
            ->where(['dateDeleted' => null])
            ->column();

        $uids = [];
        foreach ($configs as $configJson) {
            $config = Json::decodeIfJson($configJson);
            if (!is_array($config)) {
                continue;
            }
            foreach ($config['tabs'] ?? [] as $tab) {
                foreach ($tab['elements'] ?? [] as $element) {
                    if (in_array($element['fieldUid'] ?? null, $fieldUids, true) && isset($element['uid'])) {
                        $uids[] = (string)$element['uid'];
                    }
                }
            }
        }

        return array_values(array_unique($uids));
    }

    // Private Methods
    // =========================================================================

    /**
     * The field-layout-element UIDs of every SEO field placement. Memoized
     * until clearMemos() — wired to sitemap invalidation, which fires on
     * entry, section, and entry-type changes.
     *
     * @return string[]
     */
    private static function _layoutElementUids(): array
    {
        if (self::$_layoutElementUids !== null) {
            return self::$_layoutElementUids;
        }

        $fieldUids = (new Query())
            ->select(['uid'])
            ->from(Table::FIELDS)
            ->where(['type' => SeoField::class])
            ->column();

        return self::$_layoutElementUids = self::elementUidsForFieldUids($fieldUids);
    }
}
