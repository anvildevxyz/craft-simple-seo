<?php

namespace anvildev\simpleseo;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\gql\types\ResolvedMetaType;
use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\services\AuditService;
use anvildev\simpleseo\services\DiagnosticsService;
use anvildev\simpleseo\services\EtherMigrationService;
use anvildev\simpleseo\services\MetaService;
use anvildev\simpleseo\services\RobotsService;
use anvildev\simpleseo\services\SiteDefaultsService;
use anvildev\simpleseo\services\SitemapService;
use anvildev\simpleseo\web\twig\variables\SimpleSeoVariable;
use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\gql\TypeManager;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\Request as WebRequest;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Simple SEO plugin.
 *
 * Lightweight SEO for Craft CMS: an SEO field type, meta rendering, canonical
 * URLs, robots handling, and an XML sitemap — deliberately nothing else. The
 * scope charter lives in the README; feature requests outside it are closed
 * with a pointer, not implemented.
 *
 * @method Settings getSettings()
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '0.2.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     *
     * Declared explicitly because the base plugin only auto-flips this when
     * the default getSettingsResponse() is in use — without it the settings
     * link disappears when allowAdminChanges is off, even though the screens
     * render read-only just fine.
     */
    public bool $hasReadOnlyCpSettings = true;

    // Public Methods
    // =========================================================================

    /**
     * @return array<string, array<string, class-string>>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'siteDefaults' => SiteDefaultsService::class,
                'meta' => MetaService::class,
                'sitemap' => SitemapService::class,
                'robots' => RobotsService::class,
                'etherMigration' => EtherMigrationService::class,
                'diagnostics' => DiagnosticsService::class,
                'audit' => AuditService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerFieldTypes();
        $this->_registerCpUrlRules();
        $this->_registerSiteUrlRules();
        $this->_registerCraftVariable();
        $this->_registerSiteWideNoindexGuard();
        $this->_registerSitemapInvalidation();
        $this->_registerGqlTypeFields();
        $this->_registerPermissions();
        $this->_registerMcpTools();
    }

    /**
     * Returns the per-site defaults service.
     */
    public function getSiteDefaults(): SiteDefaultsService
    {
        /** @var SiteDefaultsService */
        return $this->get('siteDefaults');
    }

    /**
     * Returns the meta resolution/rendering service.
     */
    public function getMeta(): MetaService
    {
        /** @var MetaService */
        return $this->get('meta');
    }

    /**
     * Returns the sitemap service.
     */
    public function getSitemap(): SitemapService
    {
        /** @var SitemapService */
        return $this->get('sitemap');
    }

    /**
     * Returns the robots.txt service.
     */
    public function getRobots(): RobotsService
    {
        /** @var RobotsService */
        return $this->get('robots');
    }

    /**
     * Returns the meta audit service behind `simple-seo/audit/meta`.
     */
    public function getAudit(): AuditService
    {
        /** @var AuditService */
        return $this->get('audit');
    }

    /**
     * Returns the diagnostics service behind `simple-seo/doctor`.
     */
    public function getDiagnostics(): DiagnosticsService
    {
        /** @var DiagnosticsService */
        return $this->get('diagnostics');
    }

    /**
     * Returns the ether/seo migration service.
     */
    public function getEtherMigration(): EtherMigrationService
    {
        /** @var EtherMigrationService */
        return $this->get('etherMigration');
    }

    /**
     * @inheritdoc
     *
     * No permission check here: Craft already gates plugin nav items on
     * `accessPlugin-simple-seo` before calling this. Deliberately NOT gated on
     * allowAdminChanges either — the screens render read-only there, and
     * hiding the link would make them unreachable from the nav.
     *
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        // Point at General rather than the bare `simple-seo` route: Craft only
        // marks a subnav item selected when its URL is a substring of the
        // current path, so landing on `simple-seo` highlights nothing.
        $item['url'] = 'simple-seo/settings';
        $item['subnav'] = [
            'general' => [
                'label' => Craft::t('simple-seo', 'General'),
                'url' => 'simple-seo/settings',
            ],
            'sitemap' => [
                'label' => Craft::t('simple-seo', 'Sitemap'),
                'url' => 'simple-seo/settings/sitemap',
            ],
            'robots' => [
                'label' => Craft::t('simple-seo', 'Robots'),
                'url' => 'simple-seo/settings/robots',
            ],
            'fields' => [
                'label' => Craft::t('simple-seo', 'Fields'),
                'url' => 'simple-seo/settings/fields',
            ],
        ];

        return $item;
    }

    /**
     * @inheritdoc
     * @return \craft\web\Response
     */
    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();

        return $response->redirect(UrlHelper::cpUrl('simple-seo/settings'));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Exposes the plugin's MCP tools when the stimmt/craft-mcp plugin is
     * installed. Soft dependency (class_exists-guarded), so Simple SEO runs
     * unchanged when craft-mcp is absent.
     */
    private function _registerMcpTools(): void
    {
        if (!class_exists(\stimmt\craft\Mcp\Mcp::class)) {
            return;
        }

        Event::on(
            \stimmt\craft\Mcp\Mcp::class,
            \stimmt\craft\Mcp\Mcp::EVENT_REGISTER_TOOLS,
            static function(\stimmt\craft\Mcp\events\RegisterToolsEvent $event): void {
                $event->addTool(\anvildev\simpleseo\mcp\SeoTools::class, 'simple-seo');
            },
        );
    }

    /**
     * Registers the plugin's field types.
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = SeoField::class;
            },
        );
    }

    /**
     * Attaches the `craft.simpleSeo` template variable.
     */
    private function _registerCraftVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('simpleSeo', SimpleSeoVariable::class);
            },
        );
    }

    /**
     * Registers the plugin's user permissions, so managing SEO settings is
     * not admin-only. Craft's automatic `accessPlugin-simple-seo` remains the
     * gate for seeing the section; these two govern saving.
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('simple-seo', 'Simple SEO'),
                    'permissions' => [
                        Permissions::MANAGE_SETTINGS => [
                            'label' => Craft::t('simple-seo', 'Manage SEO settings'),
                            'info' => Craft::t('simple-seo', 'Title formats, default descriptions and social images, and the sitemap. Without this, the screens are read-only.'),
                            'nested' => [
                                Permissions::MANAGE_ROBOTS => [
                                    'label' => Craft::t('simple-seo', 'Edit robots.txt'),
                                    'warning' => Craft::t('simple-seo', 'robots.txt controls whether search engines crawl the site at all.'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Registers the CP routes for the settings screens.
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['simple-seo'] = 'simple-seo/settings/edit';
                $event->rules['simple-seo/settings'] = 'simple-seo/settings/edit';
                $event->rules['simple-seo/settings/sitemap'] = 'simple-seo/settings/edit-sitemap';
                $event->rules['simple-seo/settings/robots'] = 'simple-seo/settings/edit-robots';
                $event->rules['simple-seo/settings/fields'] = 'simple-seo/settings/edit-fields';
            },
        );
    }

    /**
     * Registers the front-end routes. robots.txt precedence rules live on
     * RobotsController.
     */
    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event): void {
                // Craft resolves the current site while constructing the
                // request, before URL rules are gathered, so the per-site
                // switches can be read here.
                $site = Craft::$app->getSites()->getCurrentSite();

                // A disabled feature registers NO rule rather than a rule that
                // 404s: the URL has to fall through to normal Craft routing so
                // the site can serve its own file from a template or another
                // plugin.
                if ($this->getRobots()->isEnabledForSite($site)) {
                    $event->rules['robots.txt'] = 'simple-seo/robots/index';
                }

                if ($this->getSitemap()->isEnabledForSite($site)) {
                    $event->rules['sitemap.xml'] = 'simple-seo/sitemap/index';
                    $event->rules['sitemaps/section-<sectionHandle:{handle}>.xml'] = 'simple-seo/sitemap/section';
                    $event->rules['sitemaps/section-<sectionHandle:{handle}>-p<page:\d+>.xml'] = 'simple-seo/sitemap/section';
                }
            },
        );
    }

    /**
     * Adds `simpleSeo` — the fully RESOLVED meta — to entry and category
     * GraphQL types. Meta is public-facing data by definition, so it rides
     * the element's own schema visibility. The raw field value stays
     * available as the field's own sub-selection.
     */
    private function _registerGqlTypeFields(): void
    {
        Event::on(
            TypeManager::class,
            TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS,
            static function(DefineGqlTypeFieldsEvent $event): void {
                if (!in_array($event->typeName, ['EntryInterface', 'CategoryInterface'], true)) {
                    return;
                }
                $event->fields['simpleSeo'] = [
                    'name' => 'simpleSeo',
                    'type' => ResolvedMetaType::getType(),
                    'description' => 'The fully resolved Simple SEO meta for this element — fallback chain and title format applied.',
                    'resolve' => static fn(ElementInterface $source) => Plugin::getInstance()->getMeta()->resolve($source),
                ];
            },
        );
    }

    /**
     * Invalidates every cached sitemap file when entries or sections change —
     * the whole cache drops on any relevant write and rebuilds on the next
     * request, so a sitemap can never go stale.
     */
    private function _registerSitemapInvalidation(): void
    {
        $onElement = function(ElementEvent $event): void {
            $element = $event->element;
            if ($element instanceof Entry && !ElementHelper::isDraftOrRevision($element)) {
                $this->getSitemap()->invalidate();
            }
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $onElement);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $onElement);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $onElement);

        $onSection = fn() => $this->getSitemap()->invalidate();

        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, $onSection);
        Event::on(Entries::class, Entries::EVENT_AFTER_DELETE_SECTION, $onSection);
        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_ENTRY_TYPE, $onSection);
        Event::on(Entries::class, Entries::EVENT_AFTER_DELETE_ENTRY_TYPE, $onSection);
    }

    /**
     * When (and only when) `siteWideNoindex` is explicitly enabled via
     * config/simple-seo.php: stamp every front-end response with
     * `X-Robots-Tag: noindex, nofollow` and show a persistent CP warning
     * banner. With the flag off — the permanent default — this method
     * registers nothing, which IS the invariant: no code path can emit a
     * site-wide noindex (ethercreative/seo#244).
     */
    private function _registerSiteWideNoindexGuard(): void
    {
        if (!$this->getSettings()->siteWideNoindex) {
            return;
        }

        $request = Craft::$app->getRequest();
        if ($request instanceof WebRequest && !$request->getIsConsoleRequest() && !$request->getIsCpRequest()) {
            /** @var \craft\web\Response $response */
            $response = Craft::$app->getResponse();
            $response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
        }

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            static function(RegisterCpAlertsEvent $event): void {
                $event->alerts[] = Craft::t(
                    'simple-seo',
                    'Simple SEO: this environment is hidden from search engines site-wide (`siteWideNoindex` in config/simple-seo.php).',
                );
            },
        );
    }
}
