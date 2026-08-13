<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\GqlSchema;
use IntegrationTester;

/**
 * Headless support through real GraphQL execution: the raw field value as a
 * sub-selection, and `simpleSeo` — the fully RESOLVED meta — on entries
 * (ethercreative/seo#372, #363: ether simply didn't work headless). Plus the
 * Element API serialization sanity check (ether #73).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class GraphQlCest
{
    // Public Methods
    // =========================================================================

    /**
     * The raw field value is queryable with sub-selections, including the
     * resolved social image URL (ether #363's exact ask).
     */
    public function rawFieldValueIsQueryable(IntegrationTester $I): void
    {
        [$schema, $entry] = $this->_fixture($I, [
            'title' => 'GQL title',
            'description' => 'GQL description',
            'noindex' => true,
        ]);

        $result = Craft::$app->getGql()->executeQuery($schema, <<<'GQL'
            { entries(section: "gqlPages") { title, seo { title description noindex nofollow socialImageUrl canonical } } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $row = $result['data']['entries'][0]['seo'];
        $I->assertSame('GQL title', $row['title']);
        $I->assertSame('GQL description', $row['description']);
        $I->assertTrue($row['noindex']);
        $I->assertFalse($row['nofollow']);
        $I->assertNull($row['socialImageUrl']);
    }

    /**
     * `simpleSeo` returns RESOLVED meta: title format applied, description
     * fallback applied, robots final — identical to what Twig would render.
     */
    public function resolvedMetaIsQueryable(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'siteSettings' => [
                $site->uid => ['defaultDescription' => 'Headless default description.'],
            ],
        ]);
        $I->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');

        [$schema] = $this->_fixture($I, ['noindex' => true]);

        $result = Craft::$app->getGql()->executeQuery($schema, <<<'GQL'
            { entries(section: "gqlPages") { simpleSeo { title socialTitle description robots ogType ogSiteName twitterCard } } }
            GQL);

        $I->assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        $meta = $result['data']['entries'][0]['simpleSeo'];

        $I->assertSame(
            TitleFormatter::format(null, 'GQL Page', (string)$site->name),
            $meta['title'],
        );
        $I->assertSame('GQL Page', $meta['socialTitle']);
        $I->assertSame('Headless default description.', $meta['description']);
        $I->assertSame('noindex', $meta['robots']);
        $I->assertSame('website', $meta['ogType']);
        $I->assertSame((string)$site->name, $meta['ogSiteName']);
        $I->assertSame('summary', $meta['twitterCard']);
    }

    /**
     * The field value serializes cleanly to arrays/JSON — Element API
     * transformers call toArray() and ether crashed exactly there (#73).
     */
    public function fieldValueSerializesForElementApi(IntegrationTester $I): void
    {
        [, $entry] = $this->_fixture($I, ['title' => 'Serialize me 🎯', 'nofollow' => true]);

        $value = $entry->getFieldValue('seo');
        $array = $value->toArray();

        $I->assertSame('Serialize me 🎯', $array['title']);
        $I->assertTrue($array['nofollow']);

        $json = Json::encode($value);
        $I->assertStringContainsString('Serialize me', $json);
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the field + section fixture, one saved entry, and a schema
     * scoped to read the section.
     *
     * @param array<string,mixed> $seoValue
     * @return array{0: GqlSchema, 1: Entry}
     */
    private function _fixture(IntegrationTester $I, array $seoValue): array
    {
        $fixture = $I->createSeoSection('gqlPages', ['name' => 'GQL Pages', 'typeName' => 'GQL Page', 'typeHandle' => 'gqlPage']);
        $entry = $I->createEntryWithSeo($fixture, 'GQL Page', $seoValue);

        $schema = new GqlSchema([
            'name' => 'Simple SEO test schema',
            'scope' => [
                "sections.{$fixture['section']->uid}:read",
                "entrytypes.{$fixture['entryType']->uid}:read",
            ],
        ]);

        return [$schema, $entry];
    }
}
