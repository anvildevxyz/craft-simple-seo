<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\models\EtherMigrationReport;
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
                $content = Json::decodeIfJson($row['content']);
                if (is_string($content)) {
                    $content = Json::decodeIfJson($content);
                }
                if (!is_array($content)) {
                    continue;
                }

                $changed = false;
                foreach ($layoutElementUids as $uid) {
                    if (!isset($content[$uid])) {
                        continue;
                    }
                    $value = Json::decodeIfJson($content[$uid]);
                    if (!is_array($value)) {
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
        $title = null;
        $titleRaw = $old['titleRaw'] ?? null;
        if (is_array($titleRaw)) {
            $parts = array_filter(
                array_map(static fn(mixed $p): string => is_string($p) ? trim($p) : '', $titleRaw),
                static fn(string $p): bool => $p !== '',
            );
            $title = $parts !== [] ? implode(' ', $parts) : null;
        } elseif (is_string($titleRaw) && trim($titleRaw) !== '') {
            $title = trim($titleRaw);
        } elseif (isset($old['title']) && is_string($old['title']) && trim($old['title']) !== '') {
            $title = trim($old['title']);
        }

        $description = null;
        foreach (['descriptionRaw', 'description'] as $key) {
            if (isset($old[$key]) && is_string($old[$key]) && trim($old[$key]) !== '') {
                $description = trim($old[$key]);
                break;
            }
        }

        $imageId = null;
        foreach (['twitter', 'facebook'] as $network) {
            $image = $old['social'][$network]['image'] ?? null;
            if (is_array($image)) {
                $image = $image['id'] ?? ($image[0] ?? null);
            }
            if (is_numeric($image) && (int)$image > 0) {
                $imageId = (int)$image;
                break;
            }
        }

        $robotsRaw = $old['advanced']['robots'] ?? [];
        $robots = is_array($robotsRaw) ? array_map('strval', array_values($robotsRaw)) : [];
        $noindex = in_array('noindex', $robots, true) || in_array('none', $robots, true);
        $nofollow = in_array('nofollow', $robots, true) || in_array('none', $robots, true);

        $canonical = $old['advanced']['canonical'] ?? null;
        $canonical = is_string($canonical) && trim($canonical) !== '' ? trim($canonical) : null;

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
            // ether/seo has no equivalent of the extra directives — its robots
            // UI is the same two toggles, so there is nothing to carry over.
            'robotsDirectives' => [],
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
