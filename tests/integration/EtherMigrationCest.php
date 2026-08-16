<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\SeoFieldReader;
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
use craft\models\Site;
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
        $csvPath = $this->_csvPath();
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

    /**
     * Every ether value shape maps to the right Simple SEO value. Each entry
     * carries one shape ether writes. A mapping that drops data fails here,
     * not in production.
     */
    public function mapsEveryEtherValueShape(IntegrationTester $I): void
    {
        $install = $this->_install($I, 'Matrix', [
            // titleRaw arrives as a string, an array of parts, or not at all
            // (ether's oldest rows carry a plain `title`).
            'TitleRawString' => ['titleRaw' => '  Spaced title  '],
            'TitleRawParts' => ['titleRaw' => ['1' => 'Part one', '2' => '', '3' => 'Part two']],
            'TitleFallback' => ['titleRaw' => '   ', 'title' => 'Fallback title'],
            // The array form must fall back too, not only the string form.
            'TitleEmptyPartsFallback' => ['titleRaw' => ['1' => '', '2' => '  '], 'title' => 'Array fallback title'],
            // descriptionRaw wins; a blank one falls through to description.
            'DescriptionRawWins' => ['descriptionRaw' => 'Raw description', 'description' => 'Old description'],
            'DescriptionFallback' => ['descriptionRaw' => '  ', 'description' => 'Old description'],
            // The social image lives under twitter or facebook. Ether stores
            // it as `imageId` and renames a legacy `image` key to that
            // whenever it loads a value, so both keys occur in the wild —
            // `imageId` is what a current ether install actually writes
            // (verified against ether/seo 5.0.0 on a live install).
            'ImageIdKey' => ['social' => ['twitter' => ['imageId' => '55']]],
            'ImageAsAssetList' => ['social' => ['twitter' => ['imageId' => [['id' => 66]]]]],
            'FacebookImage' => ['social' => [
                'twitter' => ['image' => null],
                'facebook' => ['image' => 77],
            ]],
            'ImageAsIdObject' => ['social' => ['twitter' => ['image' => ['id' => 88]]]],
            'ImageAsList' => ['social' => ['twitter' => ['image' => [99]]]],
            // `none` is ether's shorthand for noindex AND nofollow. Reading it
            // as neither would quietly re-expose pages meant to be hidden.
            'RobotsNone' => ['advanced' => ['robots' => ['none']]],
            // A hand-edited or older row can hold the directives as a string.
            'RobotsString' => ['advanced' => ['robots' => 'noindex, nofollow']],
            'BlankCanonical' => ['advanced' => ['canonical' => '   ']],
        ]);
        $shapeCount = count($install['entries']);

        $report = Plugin::getInstance()->getEtherMigration()->apply($this->_csvPath());
        $I->assertSame([], $report->failures);
        $I->assertSame($shapeCount, $report->converted);

        // Tallies count what actually mapped, so the console summary cannot
        // claim more (or less) than the values carry.
        $I->assertSame(4, $report->titles);
        $I->assertSame(2, $report->descriptions);
        $I->assertSame(5, $report->images);
        $I->assertSame(2, $report->robots);
        $I->assertSame(0, $report->canonicals);

        Craft::$app->getFields()->refreshFields();
        $value = fn(string $key): SeoData => $this->_value($install, $key);

        $I->assertSame('Spaced title', $value('TitleRawString')->title);
        $I->assertSame('Part one Part two', $value('TitleRawParts')->title);
        $I->assertSame('Fallback title', $value('TitleFallback')->title);
        $I->assertSame('Array fallback title', $value('TitleEmptyPartsFallback')->title);

        $I->assertSame('Raw description', $value('DescriptionRawWins')->description);
        $I->assertSame('Old description', $value('DescriptionFallback')->description);

        $I->assertSame(55, $value('ImageIdKey')->socialImageId, 'ether 5.x writes imageId');
        $I->assertSame(66, $value('ImageAsAssetList')->socialImageId);
        $I->assertSame(77, $value('FacebookImage')->socialImageId);
        $I->assertSame(88, $value('ImageAsIdObject')->socialImageId);
        $I->assertSame(99, $value('ImageAsList')->socialImageId);

        $none = $value('RobotsNone');
        $I->assertTrue($none->noindex, 'ether `none` means noindex');
        $I->assertTrue($none->nofollow, 'ether `none` means nofollow too');

        $stringRobots = $value('RobotsString');
        $I->assertTrue($stringRobots->noindex, 'a string robots value still hides the page');
        $I->assertTrue($stringRobots->nofollow);

        $I->assertNull($value('BlankCanonical')->canonical, 'a whitespace-only canonical is no canonical');
    }

    /**
     * Every localized row is converted and counted against its own site. The
     * per-site tally is how an operator confirms a multi-site run finished.
     */
    public function perSiteTallyCountsEveryLocalizedRow(IntegrationTester $I): void
    {
        $sites = Craft::$app->getSites();
        $primary = $sites->getPrimarySite();
        $altSite = new Site([
            'groupId' => $primary->getGroup()->id,
            'name' => 'Ether Alt',
            'handle' => 'etherAlt',
            'language' => 'de',
            'baseUrl' => 'https://alt.example.test/',
        ]);
        $I->assertTrue($sites->saveSite($altSite), 'save alt site: ' . json_encode($altSite->getErrors()));

        $install = $this->_install(
            $I,
            'Localized',
            ['Localized' => ['titleRaw' => 'Localized title', 'descriptionRaw' => 'Localized description']],
            [(int)$primary->id, (int)$altSite->id],
        );

        try {
            $report = Plugin::getInstance()->getEtherMigration()->apply($this->_csvPath());

            $I->assertSame(2, $report->converted, 'one row per site');
            $I->assertSame(1, $report->perSite[(int)$primary->id] ?? 0);
            $I->assertSame(1, $report->perSite[(int)$altSite->id] ?? 0);

            // Both localized values really carry the mapped data.
            Craft::$app->getFields()->refreshFields();
            foreach ([$primary->id, $altSite->id] as $siteId) {
                $entry = Entry::find()
                    ->id($install['entries']['Localized'])
                    ->siteId($siteId)
                    ->status(null)
                    ->one();
                /** @var SeoData $value */
                $value = $entry->getFieldValue($install['handle']);
                $I->assertSame('Localized title', $value->title, "site $siteId");
            }
        } finally {
            // The Sites service is memoized for the whole suite, so a site
            // left behind outlives the row the rollback removes and every
            // later test would iterate a site that no longer exists.
            $sites->deleteSiteById($altSite->id);
        }
    }

    /**
     * Applying the migration drops the sitemap cache. Content rows are
     * rewritten with direct SQL, so no element-save event fires: without an
     * explicit invalidation a cached file keeps listing an entry the run has
     * just marked noindex.
     */
    public function applyingDropsTheSitemapCache(IntegrationTester $I): void
    {
        $install = $this->_install(
            $I,
            'Sitemap',
            ['Hidden' => ['titleRaw' => 'Hidden page', 'advanced' => ['robots' => ['noindex']]]],
            null,
            'ether-sitemap/{slug}',
        );

        $site = Craft::$app->getSites()->getPrimarySite();
        $sitemap = Plugin::getInstance()->getSitemap();
        $sitemap->invalidate();

        // Positive control: ether's own shape carries no `noindex` key, so
        // the entry is listed and the file is now cached.
        $before = (string)$sitemap->getSectionXml($site, $install['sectionHandle']);
        $I->assertStringContainsString('ether-sitemap/hidden', $before, 'listed before the migration');

        Plugin::getInstance()->getEtherMigration()->apply($this->_csvPath());

        $after = (string)$sitemap->getSectionXml($site, $install['sectionHandle']);
        $I->assertStringNotContainsString('ether-sitemap/hidden', $after, 'the migrated noindex drops it');
    }

    /**
     * A field that fails to convert is reported as a failure and leaves its
     * values ether-shaped — the documented recovery path. Converting the
     * content anyway would strand Simple-SEO-shaped values under a field
     * ether still serializes, which a re-run could no longer recognise.
     */
    public function failedFieldConversionLeavesValuesReRunnable(IntegrationTester $I): void
    {
        $install = $this->_install($I, 'Broken', [
            'Broken' => ['titleRaw' => 'Never converted', 'advanced' => ['canonical' => 'https://example.com/x']],
        ]);

        // `archived` is a reserved field handle, so saveField() rejects the
        // converted field — the realistic stand-in for any validation failure.
        Db::update(Table::FIELDS, ['handle' => 'archived'], ['id' => $install['field']->id]);
        Craft::$app->getFields()->refreshFields();

        $service = Plugin::getInstance()->getEtherMigration();
        $report = $service->apply($this->_csvPath());

        $I->assertCount(1, $report->failures);
        // The reserved-word rejection itself, not the handle echoed back —
        // any other failure would also mention the handle.
        $I->assertStringContainsString('reserved', strtolower($report->failures[0]));
        $I->assertSame(0, $report->converted, 'content is left alone when its field did not convert');

        // Still ether's type, and the value still carries ether's markers.
        $I->assertSame(
            EtherMigrationService::ETHER_FIELD_TYPE,
            (new Query())->select('type')->from(Table::FIELDS)->where(['id' => $install['field']->id])->scalar(),
        );
        $stored = $this->_storedValue($install, 'Broken');
        $I->assertArrayHasKey('titleRaw', $stored, 'value stays ether-shaped');
        $I->assertArrayNotHasKey('socialImageId', $stored);

        // The recovery path is the point: fix the cause, re-run, and the same
        // work is found and finished.
        Db::update(Table::FIELDS, ['handle' => $install['handle']], ['id' => $install['field']->id]);
        Craft::$app->getFields()->refreshFields();

        $rerun = $service->apply($this->_csvPath());
        $I->assertSame([], $rerun->failures);
        $I->assertSame(1, $rerun->converted);

        Craft::$app->getFields()->refreshFields();
        $I->assertSame('Never converted', $this->_value($install, 'Broken')->title);
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
        $install = $this->_install($I, '', [
            // (1) ether's rich v3+ shape
            'Rich' => [
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
            ],
            // (2) ether's oldest plain shape
            'Plain' => [
                'title' => 'Old shape title',
                'description' => '',
            ],
            // (3) already migrated (previous run) — must be skipped untouched
            'Ours' => [
                'title' => 'Already ours',
                'description' => null,
                'socialImageId' => null,
                'noindex' => false,
                'nofollow' => false,
                'canonical' => null,
            ],
        ]);

        // Ether's redirects table (DDL survives the test transaction, so
        // guard creation and clear rows for repeatability).
        $site = Craft::$app->getSites()->getPrimarySite();
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
            'richEntryId' => $install['entries']['Rich'],
            'plainEntryId' => $install['entries']['Plain'],
            'oursEntryId' => $install['entries']['Ours'],
        ];
    }

    /**
     * Builds pre-migration DB state: a real field, entry type, section, and
     * one entry per supplied shape, with the shape written verbatim into
     * every one of that entry's elements_sites rows — then the field's stored
     * type flipped to ether's class, which is exactly what the migrator finds
     * on a real pre-migration install.
     *
     * Entries are created while the field is still OUR type so Craft's own
     * APIs work; the flip happens last.
     *
     * @param array<string, array<string, mixed>> $shapes Entry title => the ether-shaped stored value
     * @param int[]|null $siteIds Sites to enable the section on; defaults to the primary site
     * @param string|null $uriFormat Give the section URLs, so its entries reach the sitemap
     * @return array{field: SeoField, handle: string, sectionHandle: string, entries: array<string, int>, layoutElementUid: string}
     */
    private function _install(
        IntegrationTester $I,
        string $suffix,
        array $shapes,
        ?array $siteIds = null,
        ?string $uriFormat = null,
    ): array {
        $handle = 'etherSeo' . $suffix;

        $field = new SeoField();
        $field->name = 'Ether SEO ' . ($suffix !== '' ? $suffix : 'Base');
        $field->handle = $handle;
        // Non-default field settings, so the in-place conversion is forced to
        // carry them: a translatable ether field coming out non-translatable
        // would propagate one site's SEO over every other on the next save.
        $field->translationMethod = SeoField::TRANSLATION_METHOD_SITE;
        $field->searchable = true;
        $field->instructions = 'Ether instructions that must survive.';
        $I->assertTrue(Craft::$app->getFields()->saveField($field), json_encode($field->getErrors()));

        $entryType = new EntryType();
        $entryType->name = 'Ether Page ' . ($suffix !== '' ? $suffix : 'Base');
        $entryType->handle = 'etherPage' . $suffix;
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

        $siteIds ??= [(int)Craft::$app->getSites()->getPrimarySite()->id];
        $section = new Section();
        $section->name = 'Ether Pages ' . ($suffix !== '' ? $suffix : 'Base');
        $section->handle = 'etherPages' . $suffix;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings(array_map(
            static fn(int $siteId): Section_SiteSettings => new Section_SiteSettings([
                'siteId' => $siteId,
                'enabledByDefault' => true,
                'hasUrls' => $uriFormat !== null,
                'uriFormat' => $uriFormat,
                'template' => $uriFormat !== null ? '_page' : null,
            ]),
            $siteIds,
        ));
        $section->setEntryTypes([$entryType]);
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section), json_encode($section->getErrors()));

        $entries = [];
        foreach (array_keys($shapes) as $title) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $entryType->id;
            $entry->title = $title;
            $entry->postDate = new DateTime('-1 hour');
            $entry->setFieldValue($handle, ['title' => 'placeholder']);
            $I->assertTrue(Craft::$app->getElements()->saveElement($entry), json_encode($entry->getErrors()));
            $entries[$title] = (int)$entry->id;
        }

        // The content key = the field's layout element UID — read it from the
        // PERSISTED row (the in-memory layout object's uid can diverge from
        // what the save actually wrote).
        $layoutElementUid = null;
        $firstRow = (new Query())->select(['content'])->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => reset($entries)])->one();
        foreach (SeoFieldReader::decodeContentDocument($firstRow['content']) ?? [] as $key => $val) {
            $val = Json::decodeIfJson($val);
            if (is_array($val) && ($val['title'] ?? null) === 'placeholder') {
                $layoutElementUid = (string)$key;
                break;
            }
        }
        $I->assertNotNull($layoutElementUid, 'find the layout element uid in the stored content row');

        foreach ($shapes as $title => $shape) {
            // EVERY row for the element: a localized entry carries one per
            // site, and the migration is judged on all of them.
            $rows = (new Query())->select(['id', 'content'])->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => $entries[$title]])->all();
            foreach ($rows as $row) {
                $content = SeoFieldReader::decodeContentDocument($row['content']) ?? [];
                $content[$layoutElementUid] = $shape;
                // Pass the ARRAY — a pre-encoded string double-encodes on JSON columns.
                Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['id' => $row['id']]);
            }
        }

        // Flip the stored field type to ether's class: pre-migration state.
        Db::update(Table::FIELDS, ['type' => EtherMigrationService::ETHER_FIELD_TYPE], ['id' => $field->id]);
        Craft::$app->getFields()->refreshFields();

        return [
            'field' => $field,
            'handle' => $handle,
            'sectionHandle' => (string)$section->handle,
            'entries' => $entries,
            'layoutElementUid' => (string)$layoutElementUid,
        ];
    }

    /**
     * A CSV path under the test output directory. Every apply() gets one, so
     * a run never writes into the test install's storage directory.
     */
    private function _csvPath(): string
    {
        return dirname(__DIR__) . '/_output/ether-redirects-test.csv';
    }

    /**
     * The migrated field value of one fixture entry.
     *
     * @param array{field: SeoField, handle: string, entries: array<string, int>, layoutElementUid: string} $install
     */
    private function _value(array $install, string $key): SeoData
    {
        $entry = Entry::find()->id($install['entries'][$key])->status(null)->one();
        /** @var SeoData $value */
        $value = $entry->getFieldValue($install['handle']);

        return $value;
    }

    /**
     * The RAW stored content document of one fixture entry's field value —
     * read without hydrating, so an unconverted ether shape stays visible.
     *
     * @param array{field: SeoField, handle: string, entries: array<string, int>, layoutElementUid: string} $install
     * @return array<array-key, mixed>
     */
    private function _storedValue(array $install, string $key): array
    {
        $raw = (new Query())->select(['content'])->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $install['entries'][$key]])->scalar();
        $content = SeoFieldReader::decodeContentDocument($raw) ?? [];

        return SeoFieldReader::decodeFieldValue($content, $install['layoutElementUid']) ?? [];
    }
}
