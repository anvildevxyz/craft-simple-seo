<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\helpers\Canonical;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\helpers\SiteUrl;
use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\models\ResolvedMeta;
use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\models\SiteDefaults;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\helpers\Html;
use craft\models\Site;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\UrlManager;
use Twig\Markup;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Resolves and renders front-end meta.
 *
 * One fallback chain, applied once, server-side: field value → per-site
 * default → element/site data. The same resolved model backs the Twig tag
 * output and the headless array, so the two can never disagree.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class MetaService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The override keys renderMeta()/resolveMeta() accept.
     */
    public const OVERRIDE_KEYS = [
        'title',
        'description',
        'canonical',
        'robots',
        'ogType',
        'ogSiteName',
        'ogImage',
        'twitterCard',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Resolves the final meta for an element (or the current site when null),
     * with optional per-template overrides — og:type and og:site_name are
     * overridable by design (ethercreative/seo#517, #495).
     *
     * An explicit null override CLEARS its value: description, canonical,
     * robots, and ogImage render no tag at all; ogType, ogSiteName, and
     * twitterCard reset to their computed defaults. The one exception is
     * title — a page always has a title, so a null title override is
     * treated as absent and the fallback chain runs.
     *
     * @param array<string, string|null> $overrides
     * @throws InvalidArgumentException if an override key is not supported —
     * a typo'd key silently doing nothing is exactly the frustration this
     * plugin exists to avoid
     */
    public function resolve(?ElementInterface $element = null, array $overrides = []): ResolvedMeta
    {
        return $this->_resolve($element, $overrides, SeoFieldReader::read($element));
    }

    /**
     * Same fallback chain as resolve(), using an explicit field value instead
     * of re-reading the element — so the CP preview can show unsaved edits.
     */
    public function resolveFromValue(ElementInterface $element, SeoData $value): ResolvedMeta
    {
        return $this->_resolve($element, [], $value);
    }

    /**
     * Renders the resolved meta as head tags. Every value passes through
     * attribute/entity encoding — %, quotes, ampersands, emoji, and markup in
     * titles can never break the document.
     *
     * @param array<string, string|null> $overrides
     * @throws InvalidArgumentException on unknown override keys
     */
    public function renderTags(?ElementInterface $element = null, array $overrides = []): Markup
    {
        $meta = $this->resolve($element, $overrides);
        $this->_emitCanonicalHeader($meta->canonical);

        $specs = [
            ['meta', ['name' => 'description', 'content' => $meta->description]],
            ['link', ['rel' => 'canonical', 'href' => $meta->canonical]],
            ['meta', ['name' => 'robots', 'content' => $meta->robots]],
            ['meta', ['property' => 'og:site_name', 'content' => $meta->ogSiteName]],
            ['meta', ['property' => 'og:type', 'content' => $meta->ogType]],
            ['meta', ['property' => 'og:title', 'content' => $meta->socialTitle]],
            ['meta', ['property' => 'og:description', 'content' => $meta->description]],
            ['meta', ['property' => 'og:url', 'content' => $meta->canonical]],
            ['meta', ['property' => 'og:image', 'content' => $meta->ogImageUrl]],
            ['meta', ['name' => 'twitter:card', 'content' => $meta->twitterCard]],
            ['meta', ['name' => 'twitter:title', 'content' => $meta->socialTitle]],
            ['meta', ['name' => 'twitter:description', 'content' => $meta->description]],
            ['meta', ['name' => 'twitter:image', 'content' => $meta->ogImageUrl]],
        ];

        $tags = [Html::tag('title', Html::encode($meta->title))];
        foreach ($specs as [$tag, $attrs]) {
            if (end($attrs) !== null) {
                $tags[] = Html::tag($tag, '', $attrs);
            }
        }

        return new Markup(implode("\n", $tags), Craft::$app->charset);
    }

    // Private Methods
    // =========================================================================

    /**
     * Shared fallback chain for resolve() and resolveFromValue().
     *
     * @param array<string, string|null> $overrides
     * @throws InvalidArgumentException if an override key is not supported
     */
    private function _resolve(?ElementInterface $element, array $overrides, ?SeoData $value): ResolvedMeta
    {
        $unknown = array_diff(array_keys($overrides), self::OVERRIDE_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown meta override(s): %s. Supported: %s.',
                implode(', ', $unknown),
                implode(', ', self::OVERRIDE_KEYS),
            ));
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = $this->_site($element);
        $siteName = (string)$site->name;
        $defaults = $plugin->getSiteDefaults()->getForSite((int)$site->id);

        $fallbackTitle = trim((string)($element->title ?? ''));
        $socialTitle = trim((string)($value?->title ?? $fallbackTitle));

        if (array_key_exists('canonical', $overrides)) {
            $explicit = $overrides['canonical'];
            $canonicalSource = ResolvedMeta::SOURCE_OVERRIDE;
        } else {
            $explicit = $value?->canonical;
            $canonicalSource = ResolvedMeta::SOURCE_FIELD;
        }

        if ($explicit !== null && trim($explicit) !== '') {
            $canonical = Canonical::normalize($explicit, [], true);
        } elseif ($canonicalSource === ResolvedMeta::SOURCE_OVERRIDE) {
            // An explicit null/blank override suppresses the canonical —
            // same null semantics as robots and ogImage.
            $canonical = null;
        } else {
            $canonical = $this->_elementCanonical($element, $settings);
            $canonicalSource = ResolvedMeta::SOURCE_ELEMENT_URL;
        }

        if ($settings->siteWideNoindex) {
            $robots = 'noindex, nofollow';
            $robotsSource = ResolvedMeta::SOURCE_LOCKDOWN;
        } elseif (array_key_exists('robots', $overrides)) {
            $robots = $overrides['robots'];
            $robotsSource = ResolvedMeta::SOURCE_OVERRIDE;
        } else {
            $robots = $value?->robots();
            $robotsSource = ResolvedMeta::SOURCE_FIELD;
        }

        if (array_key_exists('ogImage', $overrides)) {
            $imageUrl = $overrides['ogImage'];
            $imageSource = ResolvedMeta::SOURCE_OVERRIDE;
        } else {
            [$imageUrl, $imageSource] = $this->_imageUrl($value, $defaults, $site);
        }

        if (isset($overrides['title'])) {
            $title = $overrides['title'];
            $titleSource = ResolvedMeta::SOURCE_OVERRIDE;
        } else {
            [$title, $titleSource] = TitleFormatter::resolve($value?->title, $fallbackTitle, $siteName, $defaults->titleFormat);
        }

        [$description, $descriptionSource] = match (true) {
            array_key_exists('description', $overrides) => [$overrides['description'], ResolvedMeta::SOURCE_OVERRIDE],
            $value?->description !== null => [$value->description, ResolvedMeta::SOURCE_FIELD],
            default => [$defaults->defaultDescription, ResolvedMeta::SOURCE_SITE_DEFAULT],
        };

        $canonical = $canonical === '' ? null : $canonical;
        $robots = $robots === '' ? null : $robots;
        $imageUrl = $imageUrl === '' ? null : $imageUrl;

        return new ResolvedMeta([
            'title' => $title,
            'socialTitle' => $overrides['title'] ?? ($socialTitle !== '' ? $socialTitle : $siteName),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'ogType' => $overrides['ogType'] ?? 'website',
            'ogSiteName' => $overrides['ogSiteName'] ?? $siteName,
            'ogImageUrl' => $imageUrl,
            'twitterCard' => $overrides['twitterCard'] ?? ($imageUrl ? 'summary_large_image' : 'summary'),
            // A source names the input that actually won; a value nothing
            // produced reports SOURCE_NONE, whatever branch computed it.
            'sources' => [
                'title' => $titleSource,
                'description' => $description === null ? ResolvedMeta::SOURCE_NONE : $descriptionSource,
                'canonical' => $canonical === null ? ResolvedMeta::SOURCE_NONE : $canonicalSource,
                'robots' => $robots === null ? ResolvedMeta::SOURCE_NONE : $robotsSource,
                'ogImageUrl' => $imageUrl === null ? ResolvedMeta::SOURCE_NONE : $imageSource,
            ],
        ]);
    }

    /**
     * Builds the canonical for an element from its own URL: normalized
     * encoding, params stripped to the allowlist, and the page reference
     * appended on paginated front-end requests (ethercreative/seo#335) so
     * page two never claims to canonically be page one.
     */
    private function _elementCanonical(?ElementInterface $element, Settings $settings): ?string
    {
        $url = $element?->getUrl();
        if ($url === null || $url === '') {
            return null;
        }

        $general = Craft::$app->getConfig()->getGeneral();
        $allowed = $settings->canonicalAllowedQueryParams;

        $pathParam = $general->pathParam;
        if (is_string($pathParam) && $pathParam !== '') {
            $allowed[] = $pathParam;
        }

        $request = Craft::$app->getRequest();
        if ($request instanceof WebRequest && !$request->getIsConsoleRequest() && !$request->getIsCpRequest()) {
            $pageNum = $request->getPageNum();
            if ($pageNum > 1 && $this->_isMatchedElement($element)) {
                $trigger = $general->getPageTrigger();
                $url = Canonical::paginated($url, $pageNum, $trigger);
                if (str_starts_with($trigger, '?')) {
                    $allowed[] = trim($trigger, '?=');
                }
            }
        }

        return Canonical::normalize($url, $allowed);
    }

    /**
     * Whether the element is the one the current request's URL resolved to.
     * The page suffix describes the REQUEST's pagination, so it belongs only
     * on the matched element's canonical — any other element rendered on a
     * paginated page (a featured entry, a GraphQL list item) has no page N
     * of its own.
     */
    private function _isMatchedElement(?ElementInterface $element): bool
    {
        if ($element === null) {
            return false;
        }

        $urlManager = Craft::$app->getUrlManager();
        if (!$urlManager instanceof UrlManager) {
            return false;
        }

        $matched = $urlManager->getMatchedElement();

        return $matched instanceof ElementInterface
            && (int)$matched->id === (int)$element->id
            && (int)$matched->siteId === (int)$element->siteId;
    }

    /**
     * Emits the `Link: <…>; rel="canonical"` header carrying the exact URL
     * the tag renders (ethercreative/seo#516 — the two must always agree).
     * Skipped when disabled via config (ethercreative/seo#423), outside
     * front-end web requests, or with no canonical at all.
     */
    private function _emitCanonicalHeader(?string $canonical): void
    {
        if ($canonical === null || !Plugin::getInstance()->getSettings()->canonicalLinkHeader) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (!$request instanceof WebRequest || $request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        $response = Craft::$app->getResponse();
        if (!$response instanceof WebResponse) {
            return;
        }

        $response->getHeaders()->add('Link', sprintf('<%s>; rel="canonical"', $canonical));
    }

    /**
     * The element's own site, else the current request's site.
     */
    private function _site(?ElementInterface $element): Site
    {
        if ($element !== null) {
            try {
                return $element->getSite();
            } catch (InvalidConfigException $e) {
                Craft::warning(sprintf(
                    'Could not resolve the site for element %s (%s); falling back to the current site.',
                    $element->id ?? 'new',
                    $e->getMessage(),
                ), __METHOD__);
            }
        }

        return Craft::$app->getSites()->getCurrentSite();
    }

    /**
     * Resolves the social image URL (field → site default), absolutized
     * against the site base URL, plus which of the two supplied it.
     *
     * @return array{0: string|null, 1: string}
     */
    private function _imageUrl(?SeoData $value, SiteDefaults $defaults, Site $site): array
    {
        $asset = $value?->getSocialImage();
        $source = $asset !== null ? ResolvedMeta::SOURCE_FIELD : ResolvedMeta::SOURCE_SITE_DEFAULT;
        $asset ??= $defaults->getDefaultSocialImage();

        return [SiteUrl::absolutize($asset?->getUrl(), (string)$site->getBaseUrl()), $source];
    }
}
