<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fs\Local;
use craft\helpers\Json;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\GqlSchema;
use craft\models\Section;
use craft\models\Volume;
use IntegrationTester;

/**
 * Exhaustive GraphQL coverage: every field of both types (raw SeoData and
 * resolved meta) with a REAL asset behind socialImageUrl, categories as well
 * as entries, robots combinations, schema scoping, and the read-only-in-
 * mutations guarantee via introspection.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class GraphQlFullCest
{
    // Public Methods
    // =========================================================================

    /**
     * Every raw SeoData field resolves, including socialImageUrl from a real
     * uploaded asset.
     */
    public function rawTypeResolvesEveryField(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);

        $result = Craft::$app->getGql()->executeQuery($fx['schema'], <<<'GQL'
            { entries(section: "gqlFullPages", slug: "full") {
                seo { title description socialImageId socialImageUrl noindex nofollow canonical robotsDirectives robots }
            } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $seo = $result['data']['entries'][0]['seo'];

        $I->assertSame('Full raw title', $seo['title']);
        $I->assertSame('Full raw description', $seo['description']);
        $I->assertSame($fx['asset']->id, $seo['socialImageId']);
        $I->assertSame('https://cdn.test/uploads/gql-test.png', $seo['socialImageUrl']);
        $I->assertFalse($seo['noindex']);
        $I->assertTrue($seo['nofollow']);
        $I->assertSame('https://example.com/über-raw', $seo['canonical']);
        $I->assertSame([], $seo['robotsDirectives']);
        // Built by SeoData::robots() in canonical order, so this also covers
        // the resolver rather than just the stored value.
        $I->assertSame('nofollow', $seo['robots']);
    }

    /**
     * Every resolved-meta field resolves on a full entry: format applied,
     * image present, twitterCard flipped to summary_large_image, canonical
     * normalized to percent-encoding.
     */
    public function resolvedTypeResolvesEveryFieldOnFullEntry(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);
        $site = Craft::$app->getSites()->getPrimarySite();

        $result = Craft::$app->getGql()->executeQuery($fx['schema'], <<<'GQL'
            { entries(section: "gqlFullPages", slug: "full") {
                simpleSeo { title socialTitle description canonical robots ogType ogSiteName ogImageUrl twitterCard }
            } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $meta = $result['data']['entries'][0]['simpleSeo'];

        $I->assertSame('Full raw title - ' . $site->name, $meta['title']);
        $I->assertSame('Full raw title', $meta['socialTitle']);
        $I->assertSame('Full raw description', $meta['description']);
        $I->assertSame('https://example.com/%C3%BCber-raw', $meta['canonical']);
        $I->assertSame('nofollow', $meta['robots']);
        $I->assertSame('website', $meta['ogType']);
        $I->assertSame((string)$site->name, $meta['ogSiteName']);
        $I->assertSame('https://cdn.test/uploads/gql-test.png', $meta['ogImageUrl']);
        $I->assertSame('summary_large_image', $meta['twitterCard']);
    }

    /**
     * A bare entry resolves the fallback chain: element title + format,
     * site-default description, element URL as canonical, null robots/image,
     * twitterCard summary.
     */
    public function resolvedTypeAppliesFallbacksOnBareEntry(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [
                $site->uid => ['defaultDescription' => 'GQL default description.'],
            ],
        ]);
        $I->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');

        $result = Craft::$app->getGql()->executeQuery($fx['schema'], <<<'GQL'
            { entries(section: "gqlFullPages", slug: "bare") {
                simpleSeo { title description canonical robots ogImageUrl twitterCard }
            } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $meta = $result['data']['entries'][0]['simpleSeo'];

        $I->assertSame('Bare - ' . $site->name, $meta['title']);
        $I->assertSame('GQL default description.', $meta['description']);
        $I->assertStringContainsString('gql-full/bare', (string)$meta['canonical']);
        $I->assertNull($meta['robots']);
        $I->assertNull($meta['ogImageUrl']);
        $I->assertSame('summary', $meta['twitterCard']);
    }

    /**
     * All robots combinations resolve to their exact directive strings.
     */
    public function resolvedRobotsCombinations(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);

        $result = Craft::$app->getGql()->executeQuery($fx['schema'], <<<'GQL'
            { entries(section: "gqlFullPages", orderBy: "slug") { slug, simpleSeo { robots } } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $bySlug = [];
        foreach ($result['data']['entries'] as $row) {
            $bySlug[$row['slug']] = $row['simpleSeo']['robots'];
        }

        $I->assertNull($bySlug['bare']);
        $I->assertSame('nofollow', $bySlug['full']);
        $I->assertSame('noindex', $bySlug['noindex-only']);
        $I->assertSame('noindex, nofollow', $bySlug['both-robots']);
    }

    /**
     * Categories expose simpleSeo and the raw field exactly like entries.
     */
    public function categoriesExposeBothSurfaces(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);
        $site = Craft::$app->getSites()->getPrimarySite();

        $result = Craft::$app->getGql()->executeQuery($fx['schema'], <<<'GQL'
            { categories(group: "gqlTopics") {
                title
                seo { title noindex }
                simpleSeo { title robots ogSiteName }
            } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $cat = $result['data']['categories'][0];

        $I->assertSame('GQL category meta', $cat['seo']['title']);
        $I->assertTrue($cat['seo']['noindex']);
        $I->assertSame('GQL category meta - ' . $site->name, $cat['simpleSeo']['title']);
        $I->assertSame('noindex', $cat['simpleSeo']['robots']);
    }

    /**
     * Schema scoping is respected: without the section scope, the same query
     * yields no data.
     */
    public function schemaScopingIsRespected(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);

        $inScope = Craft::$app->getGql()->executeQuery($fx['schema'], '{ entries(section: "gqlFullPages") { title } }');
        $I->assertNotEmpty($inScope['data']['entries'] ?? []);

        // Craft caches built schemas — flush so the differently-scoped schema
        // below is actually built with its own scope.
        Craft::$app->getGql()->flushCaches();

        // NB: an empty scope array is Craft's FULL-access convention (the
        // "Full Schema"), so the negative case needs a schema scoped to
        // something else entirely.
        $elsewhere = new GqlSchema([
            'name' => 'elsewhere',
            'scope' => ['categorygroups.' . $fx['groupUid'] . ':read'],
        ]);
        $denied = Craft::$app->getGql()->executeQuery($elsewhere, '{ entries(section: "gqlFullPages") { title } }');
        $I->assertTrue(
            !empty($denied['errors']) || empty($denied['data']['entries']),
            'out-of-scope query must yield errors or no data: ' . Json::encode($denied),
        );
    }

    /**
     * Mutations accept the SEO field as a JSON string (Craft's default String
     * argument), and the value round-trips through the same junk-tolerant
     * normalization as every other input path.
     */
    public function mutationsAcceptTheSeoFieldAsJson(IntegrationTester $I): void
    {
        $fx = $this->_fixture($I);

        // Craft caches built schemas — flush so this one is built with the
        // save scope included.
        Craft::$app->getGql()->flushCaches();

        $schema = new GqlSchema([
            'name' => 'mutation schema',
            'scope' => array_merge($fx['schema']->scope, [
                'sections.' . $fx['section']->uid . ':save',
            ]),
        ]);

        $introspection = Craft::$app->getGql()->executeQuery($schema, <<<'GQL'
            { __schema { mutationType { fields { name args { name type { name ofType { name } } } } } } }
            GQL);
        $I->assertArrayNotHasKey('errors', $introspection, Json::encode($introspection['errors'] ?? null));
        $mutations = $introspection['data']['__schema']['mutationType']['fields'] ?? [];
        $save = array_values(array_filter($mutations, static fn(array $m): bool => str_starts_with($m['name'], 'save_gqlFullPages')));
        $I->assertNotEmpty($save, 'save mutation must exist with save scope; available: ' . Json::encode(array_column($mutations, 'name')));

        $args = array_column($save[0]['args'], null, 'name');
        $I->assertArrayHasKey('title', $args);
        $I->assertArrayHasKey('seo', $args, 'the SEO field is mutable as a JSON string');

        // Round-trip: mutate, then read the raw field back.
        $entryId = (int)Entry::find()->section('gqlFullPages')->slug('bare')->one()->id;
        $mutation = Craft::$app->getGql()->executeQuery(
            $schema,
            'mutation Save($id: ID, $seo: String) { save_gqlFullPages_gqlFullPage_Entry(id: $id, seo: $seo) { seo { title noindex canonical } } }',
            ['id' => $entryId, 'seo' => Json::encode(['title' => 'Mutated via GQL', 'noindex' => true, 'canonical' => 'https://example.com/mutated'])],
        );
        $I->assertArrayNotHasKey('errors', $mutation, Json::encode($mutation['errors'] ?? null));
        $seo = $mutation['data']['save_gqlFullPages_gqlFullPage_Entry']['seo'];
        $I->assertSame('Mutated via GQL', $seo['title']);
        $I->assertTrue($seo['noindex']);
        $I->assertSame('https://example.com/mutated', $seo['canonical']);

        // Persisted, not just echoed.
        $fresh = Entry::find()->section('gqlFullPages')->slug('bare')->one();
        $I->assertSame('Mutated via GQL', $fresh->getFieldValue('seo')->title);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the full fixture once per test: filesystem + volume + real
     * asset, a URL-having section with four entries (full / bare /
     * noindex-only / both-robots), a category group with one category, and a
     * read schema scoped to all of it.
     *
     * @return array{schema: GqlSchema, section: Section, asset: Asset, groupUid: string}
     */
    private function _fixture(IntegrationTester $I): array
    {
        // Craft caches built GQL schemas across executeQuery calls — earlier
        // cests in the same run may have cached a build without this
        // fixture's scopes. Always start fresh.
        Craft::$app->getGql()->flushCaches();

        $field = $I->ensureSeoField();

        // Real filesystem + volume + asset, so socialImageUrl is honest.
        $fsService = Craft::$app->getFs();
        if ($fsService->getFilesystemByHandle('gqlTestFs') === null) {
            $fs = new Local([
                'name' => 'GQL Test FS',
                'handle' => 'gqlTestFs',
                'hasUrls' => true,
                'url' => 'https://cdn.test/uploads',
                // Must live outside the project root — Craft rejects Local
                // filesystems within it.
                'path' => sys_get_temp_dir() . '/gql-test-fs',
            ]);
            $I->assertTrue($fsService->saveFilesystem($fs), json_encode($fs->getErrors()));
        }

        $volumes = Craft::$app->getVolumes();
        $volume = $volumes->getVolumeByHandle('gqlTestVolume');
        if ($volume === null) {
            $volume = new Volume([
                'name' => 'GQL Test Volume',
                'handle' => 'gqlTestVolume',
                'fsHandle' => 'gqlTestFs',
            ]);
            $I->assertTrue($volumes->saveVolume($volume), json_encode($volume->getErrors()));
        }

        $asset = Asset::find()->volume('gqlTestVolume')->filename('gql-test.png')->one();
        if ($asset === null) {
            // The DB rolls back between tests; the physical file does not.
            @unlink(sys_get_temp_dir() . '/gql-test-fs/gql-test.png');
            $tempPath = tempnam(sys_get_temp_dir(), 'gql') . '.png';
            file_put_contents($tempPath, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            ));
            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename('gql-test.png');
            $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id)->id;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $I->assertTrue(Craft::$app->getElements()->saveElement($asset), json_encode($asset->getErrors()));
        }

        $fixture = $I->createSeoSection('gqlFullPages', [
            'name' => 'GQL Full Pages',
            'typeName' => 'GQL Full Page',
            'typeHandle' => 'gqlFullPage',
            'uriFormat' => 'gql-full/{slug}',
            'template' => '_gql',
        ]);

        $I->createEntryWithSeo($fixture, 'Full', [
            'title' => 'Full raw title',
            'description' => 'Full raw description',
            'socialImageId' => $asset->id,
            'nofollow' => true,
            'canonical' => 'https://example.com/über-raw',
        ], 'full');
        $I->createEntryWithSeo($fixture, 'Bare', [], 'bare');
        $I->createEntryWithSeo($fixture, 'Noindex only', ['noindex' => true], 'noindex-only');
        $I->createEntryWithSeo($fixture, 'Both robots', ['noindex' => true, 'nofollow' => true], 'both-robots');

        $categories = Craft::$app->getCategories();
        $group = $categories->getGroupByHandle('gqlTopics');
        if ($group === null) {
            $site = Craft::$app->getSites()->getPrimarySite();
            $group = new CategoryGroup();
            $group->name = 'GQL Topics';
            $group->handle = 'gqlTopics';
            $group->setSiteSettings([
                new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]),
            ]);
            $catLayout = new FieldLayout();
            $catLayout->type = Category::class;
            $catLayout->setTabs([
                [
                    'name' => 'Content',
                    'elements' => [
                        ['type' => CustomField::class, 'fieldUid' => $field->uid],
                    ],
                ],
            ]);
            $group->setFieldLayout($catLayout);
            $I->assertTrue($categories->saveGroup($group), json_encode($group->getErrors()));

            $category = new Category();
            $category->groupId = $group->id;
            $category->title = 'GQL Topic';
            $category->setFieldValue('seo', ['title' => 'GQL category meta', 'noindex' => true]);
            $I->assertTrue(Craft::$app->getElements()->saveElement($category), json_encode($category->getErrors()));
        }

        $schema = new GqlSchema([
            'name' => 'GQL full test schema',
            'scope' => [
                "sections.{$fixture['section']->uid}:read",
                "entrytypes.{$fixture['entryType']->uid}:read",
                "categorygroups.$group->uid:read",
                "volumes.$volume->uid:read",
            ],
        ]);

        return ['schema' => $schema, 'section' => $fixture['section'], 'asset' => $asset, 'groupUid' => (string)$group->uid];
    }
}
