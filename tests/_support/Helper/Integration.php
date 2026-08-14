<?php

namespace Helper;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\Plugin;
use Codeception\Module;
use Codeception\TestInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fs\Local;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use craft\web\View;
use DateTime;

/**
 * Integration-suite helper.
 *
 * Pins every test to a front-end web request context so the plugin's
 * site-facing code (meta rendering, sitemap routes) runs as it would on the
 * site, not as a console request — and provides the shared fixture actions
 * (SEO field, sections, entries) every Cest builds on.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Integration extends Module
{
    /**
     * Runs after the Craft module's _before (which boots the test app and opens
     * the per-test transaction). Under the CLI SAPI the app flags its request as
     * a console request, so front-end-only code would treat every test as an
     * admin/console context. Pin a front-end web request — the Craft module's
     * transaction still rolls back anything tests write.
     */
    public function _before(TestInterface $test): void
    {
        $request = \Craft::$app->getRequest();
        $request->setIsConsoleRequest(false);
        if (method_exists($request, 'setIsCpRequest')) {
            $request->setIsCpRequest(false);
        }
        // Front-end rendering may emit csrfInput(), which reads the CSRF cookie
        // token; the CLI test request has no cookie validation key configured.
        if (empty($request->cookieValidationKey)) {
            $request->cookieValidationKey = 'simple-seo-test-cookie-validation-key';
        }

        // The plugin resolves its controllerNamespace under the CLI SAPI at
        // boot, landing on console\controllers; pin the web namespace so
        // runAction() routes the way a real request does.
        Plugin::getInstance()->controllerNamespace = 'anvildev\\simpleseo\\controllers';
    }

    /**
     * Runs a controller action as a POST request, restoring the request state
     * afterwards even when the action throws.
     *
     * There is no browser to carry a CSRF token, so validation is off — the
     * actions' own POST and permission guards are what tests exercise. The
     * restore has to be in a finally: without it a failing assertion inside
     * expectThrowable() leaves REQUEST_METHOD=POST and CSRF off for every
     * later test in the suite, turning one failure into a cascade.
     *
     * @param array<string, mixed> $params
     */
    public function postToAction(string $route, array $params = []): mixed
    {
        $request = \Craft::$app->getRequest();
        $request->setBodyParams($params);
        $request->enableCsrfValidation = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            return \Craft::$app->runAction($route);
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $request->enableCsrfValidation = true;
            $request->setBodyParams([]);
        }
    }

    /**
     * Signs in as the test install's admin, for tests that drive a
     * permission-gated CP action rather than the service behind it.
     */
    public function beAdmin(): void
    {
        $admin = \craft\elements\User::find()->admin()->one();
        $this->assertInstanceOf(\craft\elements\User::class, $admin, 'test install must have an admin');
        \Craft::$app->getUser()->setIdentity($admin);
    }

    /**
     * The plugin's settings as they are actually stored in project config.
     *
     * This is the only assertion target that can catch a save dropping another
     * screen's settings: savePluginSettings() never clears the in-memory model,
     * so `$plugin->getSettings()` keeps groups that were just deleted from
     * project.yaml. Associative arrays are packed on write
     * (Plugins::savePluginSettings) and unpacked on read (Plugins:1345), so
     * unpack here too or every nested comparison sees `__assoc__` wrappers.
     *
     * @return array<string, mixed>
     */
    public function persistedPluginSettings(): array
    {
        $stored = \Craft::$app->getProjectConfig()->get('plugins.simple-seo.settings');

        return is_array($stored) ? ProjectConfigHelper::unpackAssociativeArrays($stored) : [];
    }

    /**
     * Returns the shared `seo` field, creating it on first use per test.
     */
    public function ensureSeoField(): SeoField
    {
        $fields = \Craft::$app->getFields();
        $field = $fields->getFieldByHandle('seo');
        if ($field instanceof SeoField) {
            return $field;
        }

        $field = new SeoField();
        $field->name = 'SEO';
        $field->handle = 'seo';
        $this->assertTrue($fields->saveField($field), 'save field: ' . json_encode($field->getErrors()));

        return $field;
    }

    /**
     * Creates a channel section + entry type carrying the shared SEO field
     * (idempotent: an existing section with the handle is returned as-is).
     *
     * Options: `name` / `typeName` / `typeHandle` (derived from the handle by
     * default), `uriFormat` + `template` (omit for a URL-less section),
     * `withSeoField` (default true — false builds the layout without it).
     *
     * @param array{name?: string, typeName?: string, typeHandle?: string, uriFormat?: string, template?: string, withSeoField?: bool} $options
     * @return array{section: Section, entryType: EntryType, field: SeoField}
     */
    public function createSeoSection(string $handle, array $options = []): array
    {
        $field = $this->ensureSeoField();
        $entries = \Craft::$app->getEntries();

        $existing = $entries->getSectionByHandle($handle);
        if ($existing !== null) {
            return ['section' => $existing, 'entryType' => $existing->getEntryTypes()[0], 'field' => $field];
        }

        $name = $options['name'] ?? ucfirst($handle);

        $entryType = new EntryType();
        $entryType->name = $options['typeName'] ?? $name;
        $entryType->handle = $options['typeHandle'] ?? $handle . 'Type';
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        // Without an explicit EntryTitleField element, Craft 5 treats the type
        // as title-less and NULLS the title on save — every fallback-title
        // assertion depends on it being here.
        $elements = [['type' => EntryTitleField::class]];
        if ($options['withSeoField'] ?? true) {
            $elements[] = ['type' => CustomField::class, 'fieldUid' => $field->uid];
        }
        $layout->setTabs([
            ['name' => 'Content', 'elements' => $elements],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($entryType),
            'save entry type: ' . json_encode($entryType->getErrors()),
        );

        $site = \Craft::$app->getSites()->getPrimarySite();
        $siteSettings = new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => isset($options['uriFormat']),
        ]);
        if (isset($options['uriFormat'])) {
            $siteSettings->uriFormat = $options['uriFormat'];
            $siteSettings->template = $options['template'] ?? '_page';
        }

        $section = new Section();
        $section->name = $name;
        $section->handle = $handle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([$siteSettings]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(
            $entries->saveSection($section),
            'save section: ' . json_encode($section->getErrors()),
        );

        return ['section' => $section, 'entryType' => $entryType, 'field' => $field];
    }

    /**
     * Saves one live entry in a fixture section, optionally with an SEO value.
     *
     * @param array{section: Section, entryType: EntryType} $fixture
     * @param array<string, mixed>|null $seoValue
     */
    public function createEntryWithSeo(array $fixture, string $title, ?array $seoValue = null, ?string $slug = null): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $fixture['section']->id;
        $entry->typeId = $fixture['entryType']->id;
        $entry->title = $title;
        if ($slug !== null) {
            $entry->slug = $slug;
        }
        $entry->postDate = new DateTime('-1 hour');
        if ($seoValue !== null && $seoValue !== []) {
            $entry->setFieldValue('seo', $seoValue);
        }
        $this->assertTrue(
            \Craft::$app->getElements()->saveElement($entry),
            'save entry: ' . json_encode($entry->getErrors()),
        );

        return $entry;
    }

    /**
     * Returns a real, saved image asset, creating the filesystem, volume, and
     * file on first use. Tests that touch `defaultSocialImageId` need one:
     * the column carries a foreign key to `assets`, so a made-up ID fails on
     * the constraint rather than on the assertion under test.
     */
    public function ensureAsset(): Asset
    {
        $fsService = \Craft::$app->getFs();
        if ($fsService->getFilesystemByHandle('seoTestFs') === null) {
            $fs = new Local([
                'name' => 'SEO Test FS',
                'handle' => 'seoTestFs',
                'hasUrls' => true,
                'url' => 'https://cdn.test/uploads',
                // Must live outside the project root — Craft rejects Local
                // filesystems within it.
                'path' => sys_get_temp_dir() . '/seo-test-fs',
            ]);
            $this->assertTrue($fsService->saveFilesystem($fs), json_encode($fs->getErrors()));
        }

        $volumes = \Craft::$app->getVolumes();
        $volume = $volumes->getVolumeByHandle('seoTestVolume');
        if ($volume === null) {
            $volume = new Volume([
                'name' => 'SEO Test Volume',
                'handle' => 'seoTestVolume',
                'fsHandle' => 'seoTestFs',
            ]);
            $this->assertTrue($volumes->saveVolume($volume), json_encode($volume->getErrors()));
        }

        $asset = Asset::find()->volume('seoTestVolume')->filename('seo-test.png')->one();
        if ($asset instanceof Asset) {
            return $asset;
        }

        // The DB rolls back between tests; the physical file does not.
        @unlink(sys_get_temp_dir() . '/seo-test-fs/seo-test.png');
        $tempPath = tempnam(sys_get_temp_dir(), 'seo') . '.png';
        file_put_contents($tempPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename('seo-test.png');
        $asset->newFolderId = \Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id)->id;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $this->assertTrue(
            \Craft::$app->getElements()->saveElement($asset),
            'save asset: ' . json_encode($asset->getErrors()),
        );

        return $asset;
    }

    /**
     * Runs a callable in CP template mode, restoring the previous mode even
     * when it throws — a leaked CP mode would poison every later test.
     *
     * @param callable(): mixed $fn
     */
    public function inCpTemplateMode(callable $fn): mixed
    {
        $view = \Craft::$app->getView();
        $originalMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $fn();
        } finally {
            $view->setTemplateMode($originalMode);
        }
    }

    /**
     * Renders an SEO field's input HTML for an entry (or without element
     * context when the entry is null), in CP template mode.
     */
    public function renderSeoFieldInput(SeoField $field, ?Entry $entry): string
    {
        return (string)$this->inCpTemplateMode(
            static fn(): string => $field->getInputHtml(
                $entry !== null ? $entry->getFieldValue($field->handle) : $field->normalizeValue(null),
                $entry,
            ),
        );
    }

    /**
     * Saves plugin settings for a test fixture, failing with the settings
     * model's errors when the save is rejected.
     *
     * @param array<string, mixed> $groups
     */
    public function seedPluginSettings(array $groups): void
    {
        $plugin = Plugin::getInstance();
        $saved = \Craft::$app->getPlugins()->savePluginSettings($plugin, $groups);
        $this->assertTrue($saved, json_encode($plugin->getSettings()->getErrors()) ?: '');
    }
}
