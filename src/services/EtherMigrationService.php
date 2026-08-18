<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\Coerce;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\models\EtherMigrationReport;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Migrates ether/seo data into Simple SEO.
 *
 * Reads ether's RAW database shapes (never its PHP classes, so ether needn't
 * be installed or even installable): converts each ether SEO field in place —
 * same field ID/UID/handle, so every field layout keeps working untouched —
 * and rewrites the stored values row by row in elements_sites, per site.
 * Redirects export to a Retour-importable CSV; we deliberately don't own
 * redirects.
 *
 * Lossless and idempotent by construction (ether wiped user data on its own
 * 4.0 upgrade — ethercreative/seo#407): dry-run is the default,
 * already-converted values are recognized and skipped, and dropped data
 * (focus keywords — we have no content analysis on purpose) is counted in
 * the report, never silent.
 *
 * @phpstan-import-type SeoDataArray from SeoData
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class EtherMigrationService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The ether field class as stored in the fields table.
     */
    public const ETHER_FIELD_TYPE = 'ether\\seo\\fields\\SeoField';

    /**
     * @var string Ether's redirects table.
     */
    public const ETHER_REDIRECTS_TABLE = '{{%seo_redirects}}';

    // Public Methods
    // =========================================================================

    /**
     * Dry run: reports everything a migration would do, writes nothing.
     */
    public function analyze(): EtherMigrationReport
    {
        return $this->_run(false, null);
    }

    /**
     * Applies the migration: converts fields + values, exports redirects.
     *
     * @param string|null $redirectsCsvPath Target CSV path; defaults to
     * storage/simple-seo/ether-redirects.csv
     * @throws \yii\db\Exception
     * @throws \yii\base\ErrorException
     */
    public function apply(?string $redirectsCsvPath = null): EtherMigrationReport
    {
        return $this->_run(true, $redirectsCsvPath);
    }

    // Private Methods
    // =========================================================================

    /**
     * The shared run: analyze and apply differ only in whether writes happen.
     *
     * @throws \yii\db\Exception
     * @throws \yii\base\ErrorException
     */
    private function _run(bool $apply, ?string $redirectsCsvPath): EtherMigrationReport
    {
        $report = new EtherMigrationReport(['applied' => $apply]);

        // Every column saveField() rewrites has to be read back and carried
        // over: createFieldConfig() writes the whole field config, so anything
        // left off the new instance is reset to the PHP default.
        /** @var array<int, array{id: string|int, uid: string, handle: string, name: string, context: string|null, columnSuffix: string|null, instructions: string|null, searchable: bool|int|string, translationMethod: string, translationKeyFormat: string|null}> $fieldRows */
        $fieldRows = (new Query())
            ->select([
                'id', 'uid', 'handle', 'name', 'context',
                'columnSuffix', 'instructions', 'searchable',
                'translationMethod', 'translationKeyFormat',
            ])
            ->from(Table::FIELDS)
            ->where(['type' => self::ETHER_FIELD_TYPE])
            ->all();

        if ($fieldRows === []) {
            $report->notes[] = 'No ether/seo fields found — field migration has nothing to do (already migrated, or ether was never installed).';
        }

        foreach ($fieldRows as $fieldRow) {
            $layoutElementUids = SeoFieldReader::elementUidsForFieldUids([(string)$fieldRow['uid']]);
            $report->fields[] = [
                'handle' => (string)$fieldRow['handle'],
                'name' => (string)$fieldRow['name'],
                'uid' => (string)$fieldRow['uid'],
                'layoutElements' => count($layoutElementUids),
            ];

            // Convert the field before its content. A failure here then leaves
            // the values ether-shaped, which _looksLikeEther() still recognises
            // on a re-run; the other order would strand Simple-SEO-shaped
            // values under a field ether still serializes.
            if ($apply) {
                $field = new SeoField();
                $field->id = (int)$fieldRow['id'];
                $field->uid = (string)$fieldRow['uid'];
                $field->handle = (string)$fieldRow['handle'];
                $field->name = (string)$fieldRow['name'];
                $field->context = (string)($fieldRow['context'] ?? 'global');
                $field->columnSuffix = $fieldRow['columnSuffix'] !== null ? (string)$fieldRow['columnSuffix'] : null;
                $field->instructions = $fieldRow['instructions'] !== null ? (string)$fieldRow['instructions'] : null;
                $field->searchable = (bool)$fieldRow['searchable'];
                $field->translationKeyFormat = $fieldRow['translationKeyFormat'] !== null
                    ? (string)$fieldRow['translationKeyFormat']
                    : null;

                // Straight out of a foreign plugin's table, so check it against
                // the set Craft accepts rather than trusting the column; an
                // unrecognised value keeps SeoField's own default.
                $translationMethod = (string)$fieldRow['translationMethod'];
                if (in_array($translationMethod, [
                    SeoField::TRANSLATION_METHOD_NONE,
                    SeoField::TRANSLATION_METHOD_SITE,
                    SeoField::TRANSLATION_METHOD_SITE_GROUP,
                    SeoField::TRANSLATION_METHOD_LANGUAGE,
                    SeoField::TRANSLATION_METHOD_CUSTOM,
                ], true)) {
                    $field->translationMethod = $translationMethod;
                }

                if (!Craft::$app->getFields()->saveField($field)) {
                    $failure = "Could not convert field '{$fieldRow['handle']}': " . Json::encode($field->getErrors());
                    $report->notes[] = $failure;
                    $report->failures[] = $failure;
                    continue;
                }
                $report->notes[] = "Converted field '{$fieldRow['handle']}' to Simple SEO in place — layouts keep working untouched.";
            }

            $this->_migrateContent($layoutElementUids, $apply, $report);
        }

        $this->_migrateRedirects($apply, $redirectsCsvPath, $report);

        $etherSettings = Craft::$app->getProjectConfig()->get('plugins.seo.settings');
        if (is_array($etherSettings) && $etherSettings !== []) {
            $report->etherSettings = $etherSettings;
            $report->notes[] = 'Ether plugin settings found (printed above): ether title templates have no clean equivalent, so nothing was guessed — review the Simple SEO settings screen and set per-site title formats deliberately.';
        }

        if ($report->droppedKeywords > 0) {
            $report->notes[] = "Dropped $report->droppedKeywords focus-keyword set(s): Simple SEO has no content analysis on purpose (it is the most fragile part of every SEO plugin).";
        }

        if ($report->droppedSocialFields > 0) {
            $report->notes[] = "Dropped $report->droppedSocialFields per-network social value(s): Simple SEO renders one social title, description, and image for every network.";
        }

        if ($report->droppedDirectives > 0) {
            $report->notes[] = "Dropped $report->droppedDirectives robots directive(s) Simple SEO does not render: a directive a crawler ignores only looks like it works.";
        }

        // Content rows were rewritten with raw SQL, so no element-save event
        // fired. Without this, cached sitemap files keep listing entries the
        // migration just marked noindex, and the memoized field-layout UIDs
        // still describe the pre-migration install.
        if ($apply) {
            Plugin::getInstance()->getSitemap()->invalidate();
        }

        return $report;
    }

    /**
     * Walks every elements_sites row carrying one of the layout keys and
     * converts ether-shaped values; already-converted values are counted and
     * skipped (idempotency).
     *
     * @param string[] $layoutElementUids
     * @throws \yii\db\Exception
     */
    private function _migrateContent(array $layoutElementUids, bool $apply, EtherMigrationReport $report): void
    {
        if ($layoutElementUids === []) {
            return;
        }

        $contentRef = Craft::$app->getDb()->getIsPgsql()
            ? new \yii\db\Expression('"content"::text')
            : 'content';

        $condition = ['or'];
        foreach ($layoutElementUids as $uid) {
            $condition[] = ['like', $contentRef, $uid];
        }

        $query = (new Query())
            ->select(['id', 'siteId', 'content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['not', ['content' => null]])
            ->andWhere($condition)
            ->orderBy(['id' => SORT_ASC]);

        $offset = 0;
        while (true) {
            $rows = $query->offset($offset)->limit(200)->all();
            /** @var array<int, array{id: int|string, siteId: int|string, content: string|array<array-key, mixed>|null}> $rows */
            if ($rows === []) {
                break;
            }
            $offset += 200;

            foreach ($rows as $row) {
                $content = SeoFieldReader::decodeContentDocument($row['content']);
                if ($content === null) {
                    continue;
                }

                $changed = false;
                foreach ($layoutElementUids as $uid) {
                    if (!isset($content[$uid])) {
                        continue;
                    }
                    $value = SeoFieldReader::decodeFieldValue($content, $uid);
                    if ($value === null) {
                        continue;
                    }

                    if (array_key_exists('socialImageId', $value)) {
                        $report->alreadyMigrated++;
                        continue;
                    }
                    if (!$this->_looksLikeEther($value)) {
                        continue;
                    }

                    $report->etherValues++;
                    $converted = $this->_transformValue($value, $report);
                    $report->converted++;
                    $siteId = (int)$row['siteId'];
                    $report->perSite[$siteId] = ($report->perSite[$siteId] ?? 0) + 1;

                    if ($apply) {
                        $content[$uid] = $converted;
                        $changed = true;
                    }
                }

                if ($changed) {
                    Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['id' => $row['id']]);
                }
            }
        }
    }

    /**
     * Whether a stored value carries ether's markers.
     *
     * @param array<array-key, mixed> $value
     */
    private function _looksLikeEther(array $value): bool
    {
        foreach (['titleRaw', 'descriptionRaw', 'social', 'advanced', 'keywords', 'score'] as $marker) {
            if (array_key_exists($marker, $value)) {
                return true;
            }
        }

        return array_key_exists('title', $value) || array_key_exists('description', $value);
    }

    /**
     * Maps one ether value to Simple SEO's shape, tallying what mapped and
     * what was dropped.
     *
     * @param array<array-key, mixed> $old
     * @return SeoDataArray
     */
    private function _transformValue(array $old, EtherMigrationReport $report): array
    {
        $titleRaw = $old['titleRaw'] ?? null;
        if (is_array($titleRaw)) {
            $parts = array_filter(
                array_map(static fn(mixed $p): string => is_string($p) ? trim($p) : '', $titleRaw),
                static fn(string $p): bool => $p !== '',
            );
            $title = $parts !== [] ? implode(' ', $parts) : null;
        } else {
            $title = Coerce::stringOrNull($titleRaw);
        }
        // Both forms fall back to the flat legacy key. An all-blank titleRaw
        // is as empty as a blank string, so it must not keep the fallback out.
        $title ??= Coerce::stringOrNull($old['title'] ?? null);

        $description = Coerce::stringOrNull($old['descriptionRaw'] ?? null)
            ?? Coerce::stringOrNull($old['description'] ?? null);

        // Ether stores the asset under `imageId`. It renames a legacy `image`
        // key to that only when it loads a value, so a stored document holds
        // either key. Reading one alone drops every social image.
        $imageId = null;
        $networkImages = [];
        foreach (['twitter', 'facebook'] as $network) {
            $social = $old['social'][$network] ?? null;
            if (!is_array($social)) {
                continue;
            }
            $networkImage = Coerce::assetId($social['imageId'] ?? null)
                ?? Coerce::assetId($social['image'] ?? null);
            if ($networkImage !== null) {
                $networkImages[] = $networkImage;
                $imageId ??= $networkImage;
            }
            // Simple SEO renders one social title and description for every
            // network, so per-network overrides cannot come across.
            $report->droppedSocialFields += count(array_filter([
                Coerce::stringOrNull($social['title'] ?? null),
                Coerce::stringOrNull($social['description'] ?? null),
            ]));
        }
        // A second, different image is dropped rather than silently preferred.
        $report->droppedSocialFields += count(array_unique($networkImages)) > 1 ? 1 : 0;

        $robotsRaw = $old['advanced']['robots'] ?? [];
        // Ether's own UI writes a list, but a hand-edited or older row can
        // hold the directives as one string. Reading only the list shape
        // would silently turn a hidden page back into an indexable one.
        // Switched-off directives leave gaps in ether's array, which stores as
        // a JSON object keyed by the surviving indexes — array_values() flattens
        // both that and the plain list back to the same thing.
        $robots = match (true) {
            is_array($robotsRaw) => array_map('strval', array_values($robotsRaw)),
            is_string($robotsRaw) => array_map('trim', explode(',', $robotsRaw)),
            default => [],
        };
        $robots = array_values(array_filter($robots, static fn(string $d): bool => $d !== ''));
        $noindex = in_array('noindex', $robots, true) || in_array('none', $robots, true);
        $nofollow = in_array('nofollow', $robots, true) || in_array('none', $robots, true);

        // Ether's robots UI is SIX switches, not two: past noindex and nofollow
        // it writes noarchive, nosnippet, notranslate, and noimageindex, and
        // every one of those is a directive Simple SEO renders. Mapping only
        // the two toggles dropped them from the rendered tag without a word in
        // the report — an entry ether kept out of the cache came back
        // cacheable, and one carrying only the extras lost its robots tag.
        $directives = SeoData::canonicalizeDirectives($robots);

        // Anything left is a directive we cannot render. Counted, never silent.
        $unmapped = array_unique(array_diff($robots, ['noindex', 'nofollow', 'none'], $directives));
        $report->droppedDirectives += count($unmapped);

        $canonical = Coerce::stringOrNull($old['advanced']['canonical'] ?? null);

        if ($title !== null) {
            $report->titles++;
        }
        if ($description !== null) {
            $report->descriptions++;
        }
        if ($imageId !== null) {
            $report->images++;
        }
        if ($noindex || $nofollow) {
            $report->robots++;
        }
        if ($directives !== []) {
            $report->directives++;
        }
        if ($canonical !== null) {
            $report->canonicals++;
        }
        if (isset($old['keywords']) && is_array($old['keywords']) && $old['keywords'] !== []) {
            $report->droppedKeywords += count($old['keywords']);
        }

        return [
            'title' => $title,
            'description' => $description,
            'socialImageId' => $imageId,
            'noindex' => $noindex,
            'nofollow' => $nofollow,
            'canonical' => $canonical,
            'robotsDirectives' => $directives,
        ];
    }

    /**
     * Exports ether's redirects to a Retour-importable CSV — we deliberately
     * don't own redirects, but the data is honored.
     *
     * @throws \yii\base\ErrorException
     */
    private function _migrateRedirects(bool $apply, ?string $csvPath, EtherMigrationReport $report): void
    {
        if (!Craft::$app->getDb()->tableExists(self::ETHER_REDIRECTS_TABLE)) {
            $report->notes[] = 'No ether redirects table found — nothing to export.';

            return;
        }

        $rows = (new Query())->from(self::ETHER_REDIRECTS_TABLE)->all();
        $report->redirectsFound = count($rows);
        $csvPath ??= Craft::getAlias('@storage') . '/simple-seo/ether-redirects.csv';
        $report->redirectsCsvPath = $csvPath;

        if ($report->redirectsFound === 0) {
            return;
        }

        $report->notes[] = 'Redirects export to Retour-importable CSV — ether patterns are exported as exact matches; review any regex patterns after import.';

        if (!$apply) {
            return;
        }

        FileHelper::createDirectory(dirname($csvPath));
        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            $failure = "Could not open $csvPath for writing.";
            $report->notes[] = $failure;
            $report->failures[] = $failure;

            return;
        }

        fputcsv($handle, ['legacyUrlPattern', 'destinationUrl', 'matchType', 'httpCode', 'siteId']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                (string)($row['uri'] ?? ''),
                (string)($row['to'] ?? ''),
                'exactmatch',
                (string)($row['type'] ?? '301'),
                (string)($row['siteId'] ?? ''),
            ]);
        }
        fclose($handle);
    }
}
