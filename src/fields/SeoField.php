<?php

namespace anvildev\simpleseo\fields;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\gql\types\SeoDataType;
use anvildev\simpleseo\helpers\Coerce;
use anvildev\simpleseo\helpers\SiteUrl;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\web\assets\seofield\SeoFieldAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GraphQL\Type\Definition\Type;
use yii\base\InvalidConfigException;
use yii\db\Schema;

/**
 * SEO field type.
 *
 * Stores meta title, meta description, social image, per-element robots
 * toggles, and a canonical override as one JSON value. Deliberately has no
 * content analysis — the field must stay boring and indestructible.
 *
 * Which of those controls an editor actually sees is per field instance, so
 * a blog can offer the full set while a landing-page section shows only a
 * title and description. Hiding a control never discards what is already
 * stored: disabled sub-fields round-trip through hidden inputs.
 *
 * @phpstan-import-type SeoDataArray from SeoData
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoField extends Field implements PreviewableFieldInterface
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Soft description limit shown on the field counter and enforced
     * by the meta audit. Google's display length, not a hard validation cap.
     */
    public const DESCRIPTION_LIMIT = 160;

    /**
     * @var float Fraction of the soft limit at which the counter turns "near".
     */
    public const LIMIT_NEAR_RATIO = 0.9;

    /**
     * @var array<string, list<key-of<self::SUBFIELDS>>> Settings-screen
     * display groups, keyed by their translation keys. Display-only —
     * SUBFIELDS order stays the render order.
     */
    public const SUBFIELD_GROUPS = [
        'Preview' => ['preview'],
        'Content' => ['title', 'description', 'socialImage'],
        'Indexing' => ['robots', 'robotsDirectives', 'canonical'],
    ];

    /**
     * @var array<string, string> Toggleable sub-fields, in render order,
     * mapped to their translation keys. All on by default.
     */
    public const SUBFIELDS = [
        'preview' => 'Live preview',
        'title' => 'Meta title',
        'description' => 'Meta description',
        'socialImage' => 'Social image',
        'robots' => 'Noindex and nofollow',
        'robotsDirectives' => 'Additional robots directives',
        'canonical' => 'Canonical URL override',
    ];

    /**
     * @var int Soft title limit shown on the field counter and enforced by
     * the meta audit. Google's display length, not a hard validation cap.
     */
    public const TITLE_LIMIT = 60;

    // Public Properties
    // =========================================================================

    /**
     * @var string[] Sub-fields shown on the element edit screen. Stored
     * values for hidden sub-fields are preserved, not cleared.
     */
    public array $enabledSubfields = [
        'preview',
        'title',
        'description',
        'socialImage',
        'robots',
        'robotsDirectives',
        'canonical',
    ];

    /**
     * @var string[] Robots directives shown on the element edit screen, each
     * as its own switch. All on by default. Stored values for hidden
     * directives are preserved, not cleared.
     */
    public array $enabledRobotsDirectives = SeoData::ROBOTS_DIRECTIVES;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('simple-seo', 'SEO');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    /**
     * @inheritdoc
     */
    public static function phpType(): string
    {
        return SeoData::class;
    }

    /**
     * @inheritdoc
     */
    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    /**
     * @inheritdoc
     */
    public function useFieldset(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     * @return SeoData
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SeoData) {
            return $value;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            return new SeoData();
        }

        $imageId = $value['socialImageId'] ?? null;
        if (is_array($imageId)) {
            $imageId = $imageId[0] ?? null;
        }

        return new SeoData([
            'title' => Coerce::stringOrNull($value['title'] ?? null),
            'description' => Coerce::stringOrNull($value['description'] ?? null),
            'socialImageId' => is_numeric($imageId) && (int)$imageId > 0 ? (int)$imageId : null,
            'noindex' => (bool)($value['noindex'] ?? false),
            'nofollow' => (bool)($value['nofollow'] ?? false),
            'canonical' => Coerce::stringOrNull($value['canonical'] ?? null),
            'robotsDirectives' => $this->_robotsDirectives($value['robotsDirectives'] ?? null),
        ]);
    }

    /**
     * @inheritdoc
     * @return SeoDataArray|null
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof SeoData || $value->isEmpty()) {
            return null;
        }

        return [
            'title' => $value->title,
            'description' => $value->description,
            'socialImageId' => $value->socialImageId,
            'noindex' => $value->noindex,
            'nofollow' => $value->nofollow,
            'canonical' => $value->canonical,
            'robotsDirectives' => $value->robotsDirectives,
        ];
    }

    /**
     * The raw field value's GraphQL shape (ethercreative/seo#372 — ether
     * simply didn't work headless). In mutations the field accepts Craft's
     * default String argument carrying a JSON object — it flows through the
     * same junk-tolerant normalizeValue() as every other input path.
     *
     * @return Type
     */
    public function getContentGqlType(): Type
    {
        return SeoDataType::getType();
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof SeoData || $value->isEmpty();
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof SeoData) {
            return '';
        }

        return trim(($value->title ?? '') . ' ' . ($value->description ?? ''));
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element): void {
                    $value = $element->getFieldValue($this->handle);
                    if ($value instanceof SeoData && !$value->validate()) {
                        foreach ($value->getFirstErrors() as $error) {
                            $element->addError($this->handle, $error);
                        }
                    }
                },
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof SeoData) {
            return '';
        }

        $parts = [];
        if ($value->title !== null) {
            $parts[] = Html::encode($value->title);
        }
        if ($value->noindex) {
            $parts[] = Html::tag('span', Craft::t('simple-seo', 'noindex'), ['class' => 'error']);
        }

        return implode(' ', $parts);
    }

    /**
     * Whether a sub-field is shown on the element edit screen: this field
     * must enable it AND the install must offer it.
     */
    public function showsSubfield(string $key): bool
    {
        return in_array($key, $this->enabledSubfields, true)
            && in_array($key, self::availableSubfields(), true);
    }

    /**
     * Grouped checkbox options for the settings screens, filtered to the
     * given sub-field keys. Groups with nothing left drop out.
     *
     * @param string[] $keys
     * @return list<array{label: string, options: list<array{label: string, value: string}>}>
     */
    public static function groupedSubfieldOptions(array $keys): array
    {
        $groups = [];
        foreach (self::SUBFIELD_GROUPS as $label => $groupKeys) {
            $options = [];
            foreach ($groupKeys as $key) {
                if (in_array($key, $keys, true)) {
                    $options[] = ['label' => Craft::t('simple-seo', self::SUBFIELDS[$key]), 'value' => $key];
                }
            }
            if ($options !== []) {
                $groups[] = ['label' => Craft::t('simple-seo', $label), 'options' => $options];
            }
        }

        return $groups;
    }

    /**
     * The robots directives this field offers, in canonical order. Values
     * that are no longer supported drop out instead of rendering.
     *
     * @return string[]
     */
    public function shownRobotsDirectives(): array
    {
        return SeoData::canonicalizeDirectives($this->enabledRobotsDirectives);
    }

    /**
     * Human labels for every supported robots directive.
     *
     * @return array<value-of<SeoData::ROBOTS_DIRECTIVES>, string>
     */
    public static function robotsDirectiveLabels(): array
    {
        return [
            'noarchive' => Craft::t('simple-seo', 'No cached copy in results (noarchive)'),
            'nosnippet' => Craft::t('simple-seo', 'No text snippet or video preview (nosnippet)'),
            'noimageindex' => Craft::t('simple-seo', 'Don’t index images on this page (noimageindex)'),
            'notranslate' => Craft::t('simple-seo', 'Don’t offer to translate this page (notranslate)'),
            'nositelinkssearchbox' => Craft::t('simple-seo', 'No sitelinks search box (nositelinkssearchbox)'),
            'indexifembedded' => Craft::t('simple-seo', 'Allow indexing when embedded elsewhere (indexifembedded)'),
            'max-image-preview:large' => Craft::t('simple-seo', 'Allow large image previews (max-image-preview:large)'),
            'max-image-preview:none' => Craft::t('simple-seo', 'No image previews (max-image-preview:none)'),
            'max-snippet:0' => Craft::t('simple-seo', 'No text snippet at all (max-snippet:0)'),
            'max-video-preview:0' => Craft::t('simple-seo', 'No video preview (max-video-preview:0)'),
        ];
    }

    /**
     * The sub-fields this install offers at all.
     *
     * An empty setting means "not configured", not "nothing available" — a
     * fresh install offers everything, and treating empty as the full set
     * means no one can switch every control off by accident and leave editors
     * with a blank field.
     *
     * @return list<key-of<self::SUBFIELDS>>
     */
    public static function availableSubfields(): array
    {
        $configured = Plugin::getInstance()->getSettings()->availableSubfields;
        $keys = array_keys(self::SUBFIELDS);

        if ($configured === []) {
            return $keys;
        }

        return array_values(array_intersect($keys, $configured));
    }

    /**
     * @inheritdoc
     *
     * The public getter is the override point — `settingsHtml()` on core
     * fields is a private helper, not a base hook, so overriding that name
     * silently renders nothing. Read-only mode is inherited: the base
     * implementation re-runs this with the inputs disabled.
     */
    public function getSettingsHtml(): ?string
    {
        $available = self::availableSubfields();

        $directiveOptions = [];
        foreach (self::robotsDirectiveLabels() as $directive => $label) {
            $directiveOptions[] = ['label' => $label, 'value' => $directive];
        }

        return Craft::$app->getView()->renderTemplate('simple-seo/_field/settings.twig', [
            'field' => $this,
            'subfieldGroups' => self::groupedSubfieldOptions($available),
            'directiveOptions' => $directiveOptions,
            'someUnavailable' => count($available) < count(self::SUBFIELDS),
            'fieldsSettingsUrl' => UrlHelper::cpUrl('simple-seo/settings/fields'),
        ]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (!$value instanceof SeoData) {
            $value = new SeoData();
        }

        $view = Craft::$app->getView();
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $view->registerAssetBundle(SeoFieldAsset::class);
        }

        $subfieldKeys = array_keys(self::SUBFIELDS);

        return $view->renderTemplate('simple-seo/_field/input.twig', [
            'id' => Html::id($this->handle),
            'name' => $this->handle,
            'value' => $value,
            'assetElementType' => Asset::class,
            'robotsDirectives' => $this->shownRobotsDirectives(),
            'directiveLabels' => self::robotsDirectiveLabels(),
            'titleLimit' => self::TITLE_LIMIT,
            'descriptionLimit' => self::DESCRIPTION_LIMIT,
            'titleNearLimit' => (int) floor(self::TITLE_LIMIT * self::LIMIT_NEAR_RATIO),
            'descriptionNearLimit' => (int) floor(self::DESCRIPTION_LIMIT * self::LIMIT_NEAR_RATIO),
            'nearRatio' => self::LIMIT_NEAR_RATIO,
            'shows' => array_combine(
                $subfieldKeys,
                array_map($this->showsSubfield(...), $subfieldKeys),
            ),
            'preview' => $inline || !$this->showsSubfield('preview')
                ? null
                : $this->_previewData($value, $element),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the data the SERP/social preview needs, all resolved server-side
     * at render time — the client never makes a request for it. Returns null
     * when there is no element context (field defaults screen).
     *
     * @return array{siteName: string, titleFormat: string, fallbackTitle: string, host: string, displayUrl: string, serpTitle: string, socialTitle: string, socialImageUrl: string, defaultSocialImageUrl: string, defaultDescription: string, resolvedDescription: string, resolvedDescriptionIsPlaceholder: bool, descriptionPlaceholder: string}|null
     */
    private function _previewData(SeoData $value, ?ElementInterface $element): ?array
    {
        if ($element === null) {
            return null;
        }

        try {
            $site = $element->getSite();
        } catch (InvalidConfigException $e) {
            Craft::warning(sprintf(
                'Could not resolve the site for element %s (%s); skipping the SEO preview.',
                $element->id ?? 'new',
                $e->getMessage(),
            ), __METHOD__);

            return null;
        }

        $url = $element->getUrl();
        if ($url === null) {
            $base = rtrim((string)$site->getBaseUrl(), '/');
            $url = $base !== '' ? $base . '/' . $element->slug : '';
        }

        $host = '';
        $displayUrl = '';
        if ($url !== '') {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));
            $displayUrl = $host . ($segments !== [] ? ' › ' . implode(' › ', $segments) : '');
        }

        $plugin = Plugin::getInstance();
        $defaults = $plugin->getSiteDefaults()->getForSite((int)$site->id);
        $meta = $plugin->getMeta()->resolveFromValue($element, $value);

        $placeholder = Craft::t(
            'simple-seo',
            'Add a meta description to control how this page appears in results.',
        );
        $defaultImageUrl = SiteUrl::absolutize($defaults->getDefaultSocialImage()?->getUrl(), (string)$site->getBaseUrl()) ?? '';

        return [
            'siteName' => (string)$site->name,
            'titleFormat' => $defaults->titleFormat,
            'fallbackTitle' => trim((string)$element->title),
            'host' => $host,
            'displayUrl' => $displayUrl,
            'serpTitle' => $meta->title,
            'socialTitle' => $meta->socialTitle,
            'socialImageUrl' => $meta->ogImageUrl ?? '',
            'defaultSocialImageUrl' => $defaultImageUrl,
            'defaultDescription' => (string)$defaults->defaultDescription,
            'resolvedDescription' => $meta->description ?? $placeholder,
            'resolvedDescriptionIsPlaceholder' => $meta->description === null,
            'descriptionPlaceholder' => $placeholder,
        ];
    }

    /**
     * Normalizes posted/stored extra robots directives to the supported set,
     * deduplicated and in canonical order. Anything unrecognized is dropped:
     * a directive Google doesn't understand only looks like it works.
     *
     * @return list<value-of<SeoData::ROBOTS_DIRECTIVES>>
     */
    private function _robotsDirectives(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $posted = array_map('strval', array_filter($raw, static fn(mixed $v): bool => is_scalar($v)));

        return SeoData::canonicalizeDirectives($posted);
    }
}
