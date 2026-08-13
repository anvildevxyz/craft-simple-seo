<?php

namespace anvildev\simpleseo\controllers;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\Permissions;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\services\RobotsService;
use Craft;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Plugin settings screens. General, Sitemap and Robots each edit one site at
 * a time, picked with Craft's native site crumb — a 20-site install gets a
 * dropdown, not 20 tabs, and every save touches exactly one site's slice.
 * Fields is install-wide and has no site selector.
 *
 * The General save splits by storage: title format + default description go
 * to plugin settings (project config, requires allowAdminChanges), while
 * default social images are persisted DB-side and stay editable in
 * production (ethercreative/seo#243).
 * Sitemap and Robots are project config throughout, so their saves require
 * allowAdminChanges outright.
 *
 * Access is permission-based rather than admin-only, so an SEO role can own
 * these screens: `accessPlugin-simple-seo` to view, MANAGE_SETTINGS to save
 * General, Sitemap and Fields, MANAGE_ROBOTS to save robots.txt.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Seeing the screens is Craft's own per-plugin access permission; the
        // save actions add their own, finer checks. Not requireAdmin(), so
        // these screens can be delegated to a non-admin SEO role.
        $this->requirePermission(Permissions::ACCESS);

        return parent::beforeAction($action);
    }

    /**
     * Renders the General settings screen.
     */
    public function actionEdit(): Response
    {
        return $this->renderTemplate('simple-seo/settings/general.twig', $this->_generalVariables($this->_requestedSite()));
    }

    /**
     * Renders the Sitemap settings screen.
     */
    public function actionEditSitemap(): Response
    {
        return $this->renderTemplate('simple-seo/settings/sitemap.twig', $this->_sitemapVariables($this->_requestedSite()));
    }

    /**
     * Renders the Robots settings screen.
     */
    public function actionEditRobots(): Response
    {
        return $this->renderTemplate('simple-seo/settings/robots.twig', $this->_robotsVariables($this->_requestedSite()));
    }

    /**
     * Renders the Fields settings screen. Unlike the others this is not
     * per-site: fields are global, so the screen shows no site selector.
     */
    public function actionEditFields(): Response
    {
        return $this->renderTemplate('simple-seo/settings/fields.twig', $this->_fieldsVariables());
    }

    /**
     * Saves which SEO field controls this install offers.
     *
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSaveFields(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::MANAGE_SETTINGS);
        $this->_requireAdminChanges();

        $available = $this->request->getBodyParam('availableSubfields', []);
        if (!is_array($available)) {
            $available = [];
        }
        $available = array_values(array_intersect(
            array_keys(SeoField::SUBFIELDS),
            array_map('strval', $available),
        ));

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $saved = Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            $settings->projectConfigPayload(['availableSubfields' => $available]),
        );

        if (!$saved) {
            $this->setFailFlash(Craft::t('simple-seo', 'Couldn’t save settings.'));

            return $this->renderTemplate('simple-seo/settings/fields.twig', $this->_fieldsVariables());
        }

        $this->setSuccessFlash(Craft::t('simple-seo', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the General settings for one site.
     *
     * @throws BadRequestHttpException
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::MANAGE_SETTINGS);

        $plugin = Plugin::getInstance();
        $site = $this->_postedSite();

        $raw = $this->request->getBodyParam('defaultSocialImage');
        $raw = is_array($raw) ? ($raw[0] ?? null) : $raw;
        $assetId = is_numeric($raw) && (int)$raw > 0 ? (int)$raw : null;

        // Project config goes first because its validation is the only thing
        // on this screen that can fail. Committing the DB-side image before
        // it would leave a rejected save half-applied — the screen saying
        // nothing saved while the image quietly changed.
        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $saved = $plugin->getSiteDefaults()->saveSiteSettings(
                $site,
                (string)$this->request->getBodyParam('titleFormat', ''),
                (string)$this->request->getBodyParam('defaultDescription', ''),
            );

            if (!$saved) {
                $this->setFailFlash(Craft::t('simple-seo', 'Couldn’t save settings.'));

                // Re-render with the image they picked, not the stored one —
                // nothing was persisted, so the stored value would silently
                // discard their selection.
                $variables = $this->_generalVariables($site);
                $variables['image'] = array_values(array_filter([
                    $assetId !== null ? Asset::find()->id($assetId)->one() : null,
                ]));

                return $this->renderTemplate('simple-seo/settings/general.twig', $variables);
            }
        }

        // save(false) — no validation, so this cannot fail here and produce
        // the inverse partial save.
        $plugin->getSiteDefaults()->saveDefaultSocialImageId((int)$site->id, $assetId);

        $this->setSuccessFlash(Craft::t('simple-seo', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the Sitemap settings for one site. Everything on that screen is
     * project config, so the whole action requires allowAdminChanges.
     *
     * @throws BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSaveSitemap(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::MANAGE_SETTINGS);
        $this->_requireAdminChanges();

        $plugin = Plugin::getInstance();
        $site = $this->_postedSite();

        $checked = $this->request->getBodyParam('sitemapSections', []);
        if (!is_array($checked)) {
            $checked = [];
        }
        $checked = array_map('strval', $checked);

        $priorities = $this->request->getBodyParam('sitemapPriorities', []);
        if (!is_array($priorities)) {
            $priorities = [];
        }
        $priorities = array_map('strval', $priorities);
        $enabled = (bool)$this->request->getBodyParam('sitemapEnabled', false);

        if (!$plugin->getSitemap()->saveSiteSettings($site, $checked, $priorities, $enabled)) {
            $this->setFailFlash(Craft::t('simple-seo', 'Couldn’t save settings.'));

            return $this->renderTemplate('simple-seo/settings/sitemap.twig', $this->_sitemapVariables($site));
        }

        $this->setSuccessFlash(Craft::t('simple-seo', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the robots.txt content for one site. Project config throughout,
     * so the whole action requires allowAdminChanges.
     *
     * @throws BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSaveRobots(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::MANAGE_ROBOTS);
        $this->_requireAdminChanges();

        $site = $this->_postedSite();
        $body = $this->request->getBodyParam('robotsTxt');
        $body = is_string($body) ? $body : '';
        $enabled = (bool)$this->request->getBodyParam('robotsTxtEnabled', false);

        if (!Plugin::getInstance()->getRobots()->saveSiteSettings($site, $body, $enabled)) {
            $this->setFailFlash(Craft::t('simple-seo', 'Couldn’t save settings.'));

            return $this->renderTemplate('simple-seo/settings/robots.twig', $this->_robotsVariables($site));
        }

        $this->setSuccessFlash(Craft::t('simple-seo', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    // Private Methods
    // =========================================================================

    /**
     * Guards the project-config writes. `allowAdminChanges` is orthogonal to
     * permissions: with it off, project config is frozen for everyone, admin
     * or not, so a permitted user still cannot write it.
     *
     * @throws ForbiddenHttpException
     */
    private function _requireAdminChanges(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }
    }

    /**
     * Resolves the site being viewed from the `site` query param (validated
     * against editable sites, same as element indexes), falling back to the
     * primary site.
     */
    private function _requestedSite(): Site
    {
        return Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
    }

    /**
     * Resolves the site a save action targets from the posted site UID.
     *
     * @throws BadRequestHttpException
     */
    private function _postedSite(): Site
    {
        $uid = (string)$this->request->getRequiredBodyParam('siteUid');

        try {
            return Craft::$app->getSites()->getSiteByUid($uid);
        } catch (SiteNotFoundException) {
            // A bogus posted UID is a client error, not a server one.
            throw new BadRequestHttpException("Invalid site UID: $uid");
        }
    }

    /**
     * Builds a CP URL that keeps the selected site in the query string on
     * multi-site installs.
     */
    private function _cpUrl(string $path, Site $site): string
    {
        $params = Craft::$app->getIsMultiSite() ? ['site' => $site->handle] : [];

        return UrlHelper::cpUrl($path, $params);
    }

    /**
     * Variables the per-site settings screens share.
     *
     * @return array<string, mixed>
     */
    private function _sharedVariables(Site $site): array
    {
        $user = Craft::$app->getUser();
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return [
            'settings' => Plugin::getInstance()->getSettings(),
            'currentSite' => $site,
            'supportedSites' => Craft::$app->getSites()->getEditableSites(),
            'allowAdminChanges' => $allowAdminChanges,
            // Two independent reasons a screen goes read-only: no permission,
            // or project config frozen. The templates tell them apart so the
            // note explains the one that actually applies.
            'canManageSettings' => $user->checkPermission(Permissions::MANAGE_SETTINGS),
            'canManageRobots' => $user->checkPermission(Permissions::MANAGE_ROBOTS),
        ];
    }

    /**
     * Builds the variables the General screen needs.
     *
     * @return array<string, mixed>
     */
    private function _generalVariables(Site $site): array
    {
        $asset = Plugin::getInstance()->getSiteDefaults()->getForSite((int)$site->id)->getDefaultSocialImage();

        return $this->_sharedVariables($site) + [
            'image' => $asset !== null ? [$asset] : [],
            'assetElementType' => Asset::class,
            'defaultTitleFormat' => TitleFormatter::DEFAULT_FORMAT,
            // Drives the first-run pointer — the screen renders setup steps
            // until an SEO field exists.
            'hasSeoField' => Craft::$app->getFields()->getFieldsByType(SeoField::class) !== [],
            'redirectUrl' => $this->_cpUrl('simple-seo/settings', $site),
        ];
    }

    /**
     * Builds the variables the Sitemap screen needs.
     *
     * @return array<string, mixed>
     */
    private function _sitemapVariables(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $sitemap = $plugin->getSitemap();

        return $this->_sharedVariables($site) + [
            // Same per-section diagnosis as /sitemap.xml?explain — counts and
            // the reason a section is missing, which is the question this
            // screen otherwise leaves people guessing at. Hydration-free, so
            // it stays cheap even on sections with thousands of entries.
            'sitemapEnabled' => $sitemap->isEnabledForSite($site),
            'rows' => $sitemap->explain($site),
            'priorities' => $plugin->getSettings()->sitemapPriorities[$site->uid] ?? [],
            // Blank first: no priority is the default, and emits no element.
            'priorityOptions' => ['' => Craft::t('simple-seo', '—')] + array_combine(
                array_map(static fn(int $i): string => number_format($i / 10, 1, '.', ''), range(10, 0, -1)),
                array_map(static fn(int $i): string => number_format($i / 10, 1, '.', ''), range(10, 0, -1)),
            ),
            'sitemapUrl' => $plugin->getRobots()->sitemapUrl($site),
            'sitemapBaseUrl' => UrlHelper::siteUrl('sitemaps/', null, null, (int)$site->id),
            'redirectUrl' => $this->_cpUrl('simple-seo/settings/sitemap', $site),
        ];
    }

    /**
     * Builds the variables the Fields screen needs. No site in play — field
     * availability is install-wide.
     *
     * @return array<string, mixed>
     */
    private function _fieldsVariables(): array
    {
        $user = Craft::$app->getUser();
        $labels = [];
        foreach (SeoField::SUBFIELDS as $key => $label) {
            $labels[$key] = Craft::t('simple-seo', $label);
        }

        return [
            'subfieldGroups' => SeoField::groupedSubfieldOptions(array_keys(SeoField::SUBFIELDS)),
            'subfieldLabels' => $labels,
            'available' => SeoField::availableSubfields(),
            'seoFields' => Craft::$app->getFields()->getFieldsByType(SeoField::class),
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'canManageSettings' => $user->checkPermission(Permissions::MANAGE_SETTINGS),
            'redirectUrl' => UrlHelper::cpUrl('simple-seo/settings/fields'),
        ];
    }

    /**
     * Builds the variables the Robots screen needs.
     *
     * @return array<string, mixed>
     */
    private function _robotsVariables(Site $site): array
    {
        $robots = Plugin::getInstance()->getRobots();
        $custom = $robots->customForSite($site);

        return $this->_sharedVariables($site) + [
            // The author's stored choice, NOT isEnabledForSite() — a lockdown
            // forces the file on, and a toggle showing "on" for a site the
            // author switched off would misreport what is saved. The notice
            // below explains the override instead.
            'robotsTxtEnabled' => Plugin::getInstance()->getSettings()->robotsTxtEnabled[$site->uid] ?? true,
            'lockdownForcesRobots' => Plugin::getInstance()->getSettings()->siteWideNoindex,
            'robotsTxt' => $custom,
            'defaultRobotsTxt' => $robots->defaultForSite($site),
            'sitemapToken' => RobotsService::SITEMAP_TOKEN,
            'blocksEverything' => $robots->blocksEverything($robots->contentForSite($site)),
            'shadowedByFile' => $robots->isShadowedByFile(),
            'robotsUrl' => UrlHelper::siteUrl('robots.txt', null, null, (int)$site->id),
            'redirectUrl' => $this->_cpUrl('simple-seo/settings/robots', $site),
        ];
    }
}
