<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\TagGroup;
use craft\web\View;
use DateTime;
use IntegrationTester;

/**
 * SEO field end-to-end against a real Craft app: round-trips through the
 * element save pipeline (including special characters), graceful defaults for
 * pre-existing entries, category/tag support, canonical validation, and
 * sections where only some entry types carry the field.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoFieldCest
{
    // Public Methods
    // =========================================================================

    /**
     * Special characters — %, quotes, emoji, multibyte — survive the full
     * save-and-reload pipeline (ethercreative/seo#254, #324, #265 regressions).
     */
    public function specialCharactersRoundTrip(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('pages', ['typeName' => 'Page', 'typeHandle' => 'page']);
        $entry = $I->createEntryWithSeo($fixture, 'Test Page', [
            'title' => '100% Zürich — 🚀 "Quotes" & <tags>',
            'description' => "Ümläute, emoji 🎯, 50% off & 'quotes' — fin.",
            'noindex' => true,
            'canonical' => 'https://example.com/über-uns',
        ]);

        $fresh = Entry::find()->id($entry->id)->status(null)->one();
        $I->assertNotNull($fresh);

        /** @var SeoData $value */
        $value = $fresh->getFieldValue('seo');
        $I->assertInstanceOf(SeoData::class, $value);
        $I->assertSame('100% Zürich — 🚀 "Quotes" & <tags>', $value->title);
        $I->assertSame("Ümläute, emoji 🎯, 50% off & 'quotes' — fin.", $value->description);
        $I->assertTrue($value->noindex);
        $I->assertFalse($value->nofollow);
        $I->assertSame('https://example.com/über-uns', $value->canonical);
    }

    /**
     * An entry saved without ever touching the field renders graceful defaults
     * (ethercreative/seo#182 regression).
     */
    public function untouchedFieldNormalizesToDefaults(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('pages', ['typeName' => 'Page', 'typeHandle' => 'page']);
        $entry = $I->createEntryWithSeo($fixture, 'Test Page');

        $fresh = Entry::find()->id($entry->id)->status(null)->one();
        $I->assertNotNull($fresh);

        /** @var SeoData $value */
        $value = $fresh->getFieldValue('seo');
        $I->assertInstanceOf(SeoData::class, $value);
        $I->assertTrue($value->isEmpty());
        $I->assertFalse($value->noindex);
    }

    /**
     * The field works on categories and tags, not just entries
     * (ethercreative/seo#260, #59 regressions).
     */
    public function worksOnCategoriesAndTags(IntegrationTester $I): void
    {
        $field = $I->ensureSeoField();
        $site = Craft::$app->getSites()->getPrimarySite();

        $categoryGroup = new CategoryGroup();
        $categoryGroup->name = 'Topics';
        $categoryGroup->handle = 'topics';
        $categoryGroup->setSiteSettings([
            new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]),
        ]);
        $categoryGroup->setFieldLayout($this->_layoutWithField(Category::class, $field->uid));
        $I->assertTrue(
            Craft::$app->getCategories()->saveGroup($categoryGroup),
            'save category group: ' . json_encode($categoryGroup->getErrors()),
        );

        $category = new Category();
        $category->groupId = $categoryGroup->id;
        $category->title = 'Category with SEO 🎯';
        $category->setFieldValue('seo', ['title' => 'Category SEO title 🎯']);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($category),
            'save category: ' . json_encode($category->getErrors()),
        );

        $freshCategory = Category::find()->id($category->id)->status(null)->one();
        $I->assertSame('Category SEO title 🎯', $freshCategory->getFieldValue('seo')->title);

        $tagGroup = new TagGroup();
        $tagGroup->name = 'Labels';
        $tagGroup->handle = 'labels';
        $tagGroup->setFieldLayout($this->_layoutWithField(Tag::class, $field->uid));
        $I->assertTrue(
            Craft::$app->getTags()->saveTagGroup($tagGroup),
            'save tag group: ' . json_encode($tagGroup->getErrors()),
        );

        $tag = new Tag();
        $tag->groupId = $tagGroup->id;
        $tag->title = 'Tag with SEO';
        $tag->setFieldValue('seo', ['description' => 'Tag description, 100% covered']);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($tag),
            'save tag: ' . json_encode($tag->getErrors()),
        );

        $freshTag = Tag::find()->id($tag->id)->one();
        $I->assertSame('Tag description, 100% covered', $freshTag->getFieldValue('seo')->description);
    }

    /**
     * An invalid canonical override blocks the save with a field error; a
     * valid absolute URL passes.
     */
    public function canonicalOverrideIsValidated(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('pages', ['typeName' => 'Page', 'typeHandle' => 'page']);

        $entry = new Entry();
        $entry->sectionId = $fixture['section']->id;
        $entry->typeId = $fixture['entryType']->id;
        $entry->title = 'Canonical validation';
        $entry->postDate = new DateTime('-1 hour');
        $entry->setFieldValue('seo', ['canonical' => 'not a url at all']);

        $I->assertFalse(Craft::$app->getElements()->saveElement($entry));
        $I->assertNotEmpty($entry->getErrors('seo'));

        $entry->setFieldValue('seo', ['canonical' => 'https://example.com/real-home']);
        $entry->clearErrors();
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($entry),
            'save with valid canonical: ' . json_encode($entry->getErrors()),
        );
    }

    /**
     * A section where a second entry type does NOT carry the field must save
     * without errors (ethercreative/seo#262 regression).
     */
    public function entryTypeWithoutFieldSaves(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('pages', ['typeName' => 'Page', 'typeHandle' => 'page']);

        $bareType = new EntryType();
        $bareType->name = 'Bare';
        $bareType->handle = 'bare';
        $bareType->setFieldLayout(new FieldLayout(['type' => Entry::class]));
        $I->assertTrue(
            Craft::$app->getEntries()->saveEntryType($bareType),
            'save bare entry type: ' . json_encode($bareType->getErrors()),
        );

        $section = $fixture['section'];
        $section->setEntryTypes([...$section->getEntryTypes(), $bareType]);
        $I->assertTrue(
            Craft::$app->getEntries()->saveSection($section),
            'add bare type to section: ' . json_encode($section->getErrors()),
        );

        $I->createEntryWithSeo(['section' => $section, 'entryType' => $bareType], 'No SEO field here');
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the field input in CP template mode, restoring the mode after.
     */
    private function _renderInputHtml(SeoField $field, Entry $entry): string
    {
        $view = Craft::$app->getView();
        $originalMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $field->getInputHtml($entry->getFieldValue('seo'), $entry);
        } finally {
            $view->setTemplateMode($originalMode);
        }
    }

    /**
     * Builds a single-tab field layout containing the SEO field.
     */
    private function _layoutWithField(string $elementType, string $fieldUid): FieldLayout
    {
        $layout = new FieldLayout();
        $layout->type = $elementType;
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $fieldUid],
                ],
            ],
        ]);

        return $layout;
    }

    /**
     * Turning a sub-field off hides its control but must never discard what
     * is already stored — the template round-trips hidden sub-fields through
     * hidden inputs, so a plain re-save keeps them.
     */
    public function hiddenSubfieldsKeepTheirValues(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('subfieldPages', [
            'name' => 'Subfield Pages',
            'typeName' => 'Subfield Page',
            'typeHandle' => 'subfieldPage',
        ]);
        $entry = $I->createEntryWithSeo($fixture, 'Subfield Page', [
            'title' => 'Stored title',
            'canonical' => 'https://example.com/elsewhere',
            'noindex' => true,
            'robotsDirectives' => ['noarchive'],
        ]);

        /** @var SeoField $field */
        $field = $fixture['field'];
        $field->enabledSubfields = ['title'];

        $html = $this->_renderInputHtml($field, $entry);

        // Shown.
        $I->assertStringContainsString('Stored title', $html);

        // Hidden, but still submitted — otherwise the next save would wipe them.
        $I->assertMatchesRegularExpression('/type="hidden"[^>]*canonical/', $html);
        $I->assertStringContainsString('https://example.com/elsewhere', $html);
        $I->assertStringContainsString('noarchive', $html);
        $I->assertStringNotContainsString('Canonical URL override', $html);
        $I->assertStringNotContainsString('More robots directives', $html);
    }

    /**
     * Which directives a field offers is a field setting. A hidden directive
     * loses its switch but a stored value still round-trips through a hidden
     * input — hiding never erases data.
     */
    public function hiddenDirectivesKeepTheirValues(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('directivePages', [
            'name' => 'Directive Pages',
            'typeName' => 'Directive Page',
            'typeHandle' => 'directivePage',
        ]);
        $entry = $I->createEntryWithSeo($fixture, 'Directive Page', [
            'robotsDirectives' => ['noarchive', 'nosnippet'],
        ]);

        /** @var SeoField $field */
        $field = $fixture['field'];
        $field->enabledRobotsDirectives = ['nosnippet'];

        $html = $this->_renderInputHtml($field, $entry);

        // The offered directive is a real switch.
        $I->assertStringContainsString('No text snippet or video preview (nosnippet)', $html);

        // The hidden one has no switch but still submits its stored value.
        $I->assertStringNotContainsString('No cached copy in results (noarchive)', $html);
        $I->assertMatchesRegularExpression('/type="hidden"[^>]*value="noarchive"/', $html);
    }

    /**
     * The install-wide list caps what a field can show: enabling a control on
     * the field is not enough if the install does not offer it. An empty
     * setting means "not configured", so a fresh install offers everything
     * rather than nothing.
     */
    public function installWideAvailabilityCapsTheField(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();
        $field = $I->ensureSeoField();
        $field->enabledSubfields = ['title', 'canonical'];

        // Unconfigured: everything the field asks for is offered.
        $I->assertSame([], $plugin->getSettings()->availableSubfields);
        $I->assertTrue($field->showsSubfield('title'));
        $I->assertTrue($field->showsSubfield('canonical'));

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'availableSubfields' => ['preview', 'title', 'description'],
        ]);
        $I->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');

        // Still enabled on the field, but no longer offered by the install.
        $I->assertTrue($field->showsSubfield('title'));
        $I->assertFalse($field->showsSubfield('canonical'));

        // And a control the install offers but the field did not enable stays off.
        $I->assertFalse($field->showsSubfield('description'));

        $I->assertSame(['preview', 'title', 'description'], SeoField::availableSubfields());
    }
}
