<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\services\EtherMigrationService;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use DateTime;
use IntegrationTester;

/**
 * Ether/seo migration against fabricated ether-shaped DATABASE state (the
 * migrator reads raw DB shapes, never ether's classes — so the fixture flips
 * a real field's stored type to ether's class and writes ether-shaped JSON
 * into elements_sites, exactly what a real pre-migration install looks like).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class EtherMigrationCest
{
    // Public Methods
    // =========================================================================

    /**
     * Dry run reports everything and writes NOTHING; apply converts values
     * and the field in place; a second run finds nothing (idempotent).
     */
    public function dryRunThenApplyThenIdempotent(IntegrationTester $I): void
    {
        $fixture = $this->_etherFixture($I);
        $service = Plugin::getInstance()->getEtherMigration();
        $csvPath = dirname(__DIR__) . '/_output/ether-redirects-test.csv';
        @unlink($csvPath);

        // --- Dry run
        $dry = $service->analyze();
        $I->assertFalse($dry->applied);
        $I->assertCount(1, $dry->fields);
        $I->assertSame('etherSeo', $dry->fields[0]['handle']);
        $I->assertSame(2, $dry->etherValues);
        $I->assertSame(2, $dry->converted);
        // 1 pre-converted canonical + 3 revision rows (each save created a
        // revision whose content still holds the placeholder in our shape).
        $I->assertSame(4, $dry->alreadyMigrated);
        $I->assertSame(2, $dry->titles);
        $I->assertSame(1, $dry->images);
        $I->assertSame(1, $dry->robots);
        $I->assertSame(1, $dry->canonicals);
        $I->assertSame(3, $dry->droppedKeywords);
        $I->assertSame(2, $dry->redirectsFound);

        // Nothing was written: field type unchanged, CSV absent.
        $I->assertSame(
            EtherMigrationService::ETHER_FIELD_TYPE,
            (new Query())->select('type')->from(Table::FIELDS)->where(['handle' => 'etherSeo'])->scalar(),
        );
        $I->assertFileDoesNotExist($csvPath);

        // --- Apply
        $applied = $service->apply($csvPath);
        $I->assertTrue($applied->applied);
        $I->assertSame(2, $applied->converted);

        $I->assertSame(
            SeoField::class,
            (new Query())->select('type')->from(Table::FIELDS)->where(['handle' => 'etherSeo'])->scalar(),
        );
        $I->assertSame([], $applied->failures);

        // saveField() rewrites the whole field config, so the conversion has to
        // carry every column it did not set — otherwise a per-site-translatable
        // field silently becomes non-translatable.
        /** @var array<string, mixed> $converted */
        $converted = (new Query())
            ->select(['translationMethod', 'searchable', 'instructions'])
            ->from(Table::FIELDS)
            ->where(['handle' => 'etherSeo'])
            ->one();
        $I->assertSame(SeoField::TRANSLATION_METHOD_SITE, $converted['translationMethod']);
        $I->assertTrue((bool)$converted['searchable']);
        $I->assertSame('Ether instructions that must survive.', $converted['instructions']);

        // Values load as SeoData with everything mapped.
        Craft::$app->getFields()->refreshFields();
        $entry = Entry::find()->id($fixture['richEntryId'])->status(null)->one();
        /** @var SeoData $value */
        $value = $entry->getFieldValue('etherSeo');
        $I->assertInstanceOf(SeoData::class, $value);
        $I->assertSame('Migrated title', $value->title);
        $I->assertSame('Migrated description', $value->description);
        $I->assertSame(4242, $value->socialImageId);
        $I->assertTrue($value->noindex);
        $I->assertTrue($value->nofollow);
        $I->assertSame('https://example.com/legacy', $value->canonical);

        $plain = Entry::find()->id($fixture['plainEntryId'])->status(null)->one();
        /** @var SeoData $plainValue */
        $plainValue = $plain->getFieldValue('etherSeo');
        $I->assertSame('Old shape title', $plainValue->title);
        $I->assertFalse($plainValue->noindex);

        // The pre-converted value survived untouched.
        $ours = Entry::find()->id($fixture['oursEntryId'])->status(null)->one();
        $I->assertSame('Already ours', $ours->getFieldValue('etherSeo')->title);

        // Redirects CSV: header + 2 rows, Retour-mappable columns.
        $I->assertFileExists($csvPath);
        $csv = (string)file_get_contents($csvPath);
        $I->assertStringContainsString('legacyUrlPattern,destinationUrl,matchType,httpCode,siteId', $csv);
        $I->assertStringContainsString('/old-page,/new-page,exactmatch,301', $csv);
        $I->assertStringContainsString('/gone,https://example.com/elsewhere,exactmatch,302', $csv);

        // --- Idempotency: nothing left to find.
        $again = $service->analyze();
        $I->assertSame([], $again->fields);
        $I->assertSame(0, $again->etherValues);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a pre-migration DB state: a field whose stored type is ether's
     * class, three entries whose stored values are (1) ether's rich shape,
     * (2) ether's oldest plain shape, (3) already ours; plus ether's
     * redirects table with two rows.
     *
     * @return array{richEntryId: int, plainEntryId: int, oursEntryId: int}
     */
    private function _etherFixture(IntegrationTester $I): array
    {
        // Real field + layout + entries first (created as OUR type so Craft
        // APIs work), then flip the stored DB state to look pre-migration.
        $field = new SeoField();
        $field->name = 'Ether SEO';
        $field->handle = 'etherSeo';
        // Non-default field settings, so the in-place conversion is forced to
        // carry them: a translatable ether field coming out non-translatable
        // would propagate one site's SEO over every other on the next save.
        $field->translationMethod = SeoField::TRANSLATION_METHOD_SITE;
        $field->searchable = true;
        $field->instructions = 'Ether instructions that must survive.';
        $I->assertTrue(Craft::$app->getFields()->saveField($field), json_encode($field->getErrors()));

        $entryType = new EntryType();
        $entryType->name = 'Ether Page';
        $entryType->handle = 'etherPage';
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    ['type' => EntryTitleField::class],
                    ['type' => CustomField::class, 'fieldUid' => $field->uid],
                ],
            ],
        ]);
        $entryType->setFieldLayout($layout);
        $I->assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), json_encode($entryType->getErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = 'Ether Pages';
        $section->handle = 'etherPages';
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section), json_encode($section->getErrors()));

        $makeEntry = function(string $title) use ($section, $entryType, $I): Entry {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $entryType->id;
            $entry->title = $title;
            $entry->postDate = new DateTime('-1 hour');
            $entry->setFieldValue('etherSeo', ['title' => 'placeholder']);
            $I->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));

            return $entry;
        };

        $rich = $makeEntry('Rich');
        $plain = $makeEntry('Plain');
        $ours = $makeEntry('Ours');

        // The content key = the field's layout element UID — read it from the
        // PERSISTED row (the in-memory layout object's uid can diverge from
        // what the save actually wrote).
        $layoutElementUid = null;
        $richRow = (new Query())->select(['content'])->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $rich->id])->one();
        foreach ((array)Json::decodeIfJson($richRow['content']) as $key => $val) {
            $val = Json::decodeIfJson($val);
            if (is_array($val) && ($val['title'] ?? null) === 'placeholder') {
                $layoutElementUid = (string)$key;
                break;
            }
        }
        $I->assertNotNull($layoutElementUid, 'find the layout element uid in the stored content row');

        $write = static function(int $elementId, array $value) use ($layoutElementUid): void {
            $row = (new Query())->select(['id', 'content'])->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => $elementId])->one();
            $content = Json::decodeIfJson($row['content']) ?: [];
            $content[$layoutElementUid] = $value;
            // Pass the ARRAY — a pre-encoded string double-encodes on JSON columns.
            Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['id' => $row['id']]);
        };

        // (1) ether's rich v3+ shape
        $write((int)$rich->id, [
            'titleRaw' => ['1' => 'Migrated title', '2' => ''],
            'descriptionRaw' => 'Migrated description',
            'keywords' => [
                ['keyword' => 'a', 'rating' => 0],
                ['keyword' => 'b', 'rating' => 1],
                ['keyword' => 'c', 'rating' => 2],
            ],
            'score' => 'unranked',
            'social' => [
                'twitter' => ['title' => '', 'image' => 4242, 'description' => ''],
                'facebook' => ['title' => '', 'image' => null, 'description' => ''],
            ],
            'advanced' => [
                'robots' => ['noindex', 'nofollow'],
                'canonical' => 'https://example.com/legacy',
            ],
        ]);

        // (2) ether's oldest plain shape
        $write((int)$plain->id, [
            'title' => 'Old shape title',
            'description' => '',
        ]);

        // (3) already migrated (previous run) — must be skipped untouched
        $write((int)$ours->id, [
            'title' => 'Already ours',
            'description' => null,
            'socialImageId' => null,
            'noindex' => false,
            'nofollow' => false,
            'canonical' => null,
        ]);

        // Flip the stored field type to ether's class: pre-migration state.
        Db::update(Table::FIELDS, ['type' => EtherMigrationService::ETHER_FIELD_TYPE], ['id' => $field->id]);
        Craft::$app->getFields()->refreshFields();

        // Ether's redirects table (DDL survives the test transaction, so
        // guard creation and clear rows for repeatability).
        $db = Craft::$app->getDb();
        if (!$db->tableExists(EtherMigrationService::ETHER_REDIRECTS_TABLE)) {
            $db->createCommand()->createTable(EtherMigrationService::ETHER_REDIRECTS_TABLE, [
                'id' => 'pk',
                'siteId' => 'int null',
                'uri' => 'string',
                'to' => 'string',
                'type' => 'string',
            ])->execute();
        }
        $db->createCommand()->delete(EtherMigrationService::ETHER_REDIRECTS_TABLE)->execute();
        $db->createCommand()->batchInsert(
            EtherMigrationService::ETHER_REDIRECTS_TABLE,
            ['siteId', 'uri', 'to', 'type'],
            [
                [$site->id, '/old-page', '/new-page', '301'],
                [null, '/gone', 'https://example.com/elsewhere', '302'],
            ],
        )->execute();

        return [
            'richEntryId' => (int)$rich->id,
            'plainEntryId' => (int)$plain->id,
            'oursEntryId' => (int)$ours->id,
        ];
    }
}
