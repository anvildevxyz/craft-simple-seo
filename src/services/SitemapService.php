<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\helpers\SitemapXml;
use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\models\Site;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * XML sitemap generation: index + per-section files, per site.
 *
 * Zero-config: every section with URLs on a site is included until excluded
 * in the settings. Entries marked noindex are excluded
 * (ethercreative/seo#219). Every rendered file is cached behind one tag,
 * invalidated on entry/section changes. And nothing is ever silently empty:
 * empty files carry a reason comment, and explain() produces the full
 * per-section diagnosis (ethercreative/seo#422, #343, #430, #466).
 *
 * Generation is deliberately hydration-free: candidate rows come from one
 * lean query replicating live-entry semantics, and the noindex exclusion
 * parses the stored content JSON directly — a cold 1000-URL file costs two
 * lean queries, not a thousand element materializations.
 *
 * @phpstan-type SitemapExplainRow array{section: string, name: string, uid: string, included: bool, urls: int, reason: string, reasonCode: string, reasonParams: array<string, int>}
 * @phpstan-import-type SitemapAlternate from SitemapXml
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SitemapService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Cache tag every rendered sitemap file depends on.
     */
    public const CACHE_TAG = 'simpleseo-sitemap';

    /**
     * @var string explain() reason: the section contributes URLs.
     */
    public const REASON_OK = 'ok';

    /**
     * @var string explain() reason: the section is not enabled for the site.
     */
    public const REASON_NOT_ENABLED = 'notEnabled';

    /**
     * @var string explain() reason: the section has no URLs on the site.
     */
    public const REASON_NO_URLS = 'noUrls';

    /**
     * @var string explain() reason: the section is excluded in the settings.
     */
    public const REASON_EXCLUDED = 'excluded';

    /**
     * @var string explain() reason: the section has no live entries with URLs.
     */
    public const REASON_NO_ENTRIES = 'noEntries';

    /**
     * @var string explain() reason: every live entry is noindexed.
     * Params: `total` — the number of live entries.
     */
    public const REASON_ALL_NOINDEXED = 'allNoindexed';

    // Public Properties
    // =========================================================================

    /**
     * @var int URLs per sitemap file (the spec caps a file at 50k; a smaller
     * page keeps queries cheap). Overridable in tests.
     */
    public int $urlsPerPage = 1000;

    // Public Methods
    // =========================================================================

    /**
     * Whether this plugin serves the sitemap for a site.
     *
     * When false the routes are never registered, so `/sitemap.xml` falls
     * through to normal Craft routing and a site can serve its own.
     */
    public function isEnabledForSite(Site $site): bool
    {
        return Plugin::getInstance()->getSettings()->sitemapEnabled[$site->uid] ?? true;
    }

    /**
     * Renders the sitemap index for a site (cached).
     */
    public function getIndexXml(Site $site): string
    {
        return $this->_cached('index:' . $site->id, function() use ($site): string {
            $urls = [];
            foreach ($this->includedSections($site) as $section) {
                $total = (int)$this->_rowQuery($section, (int)$site->id)->count();
                $pages = max(1, (int)ceil($total / $this->urlsPerPage));
                for ($page = 1; $page <= $pages; $page++) {
                    $file = $page === 1
                        ? "section-$section->handle.xml"
                        : "section-$section->handle-p$page.xml";
                    $urls[] = UrlHelper::siteUrl("sitemaps/$file", null, null, $site->id);
                }
            }

            return SitemapXml::index($urls, 'no sections with URLs are enabled for this site');
        });
    }

    /**
     * Renders one section's sitemap page (cached), or null for an unknown or
     * excluded section → 404. An out-of-range page renders an empty urlset
     * with a reason comment — never silently empty, never a soft 404 the
     * index actually linked to.
     */
    public function getSectionXml(Site $site, string $sectionHandle, int $page = 1): ?string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if ($section === null || !$this->_sectionIncluded($section, $site)) {
            return null;
        }

        return $this->_cached("section:$site->id:$section->id:$page", function() use ($site, $section, $page): string {
            $rows = $this->_rowQuery($section, (int)$site->id)
                ->orderBy(['es.elementId' => SORT_ASC])
                ->offset(($page - 1) * $this->urlsPerPage)
                ->limit($this->urlsPerPage)
                ->all();
            /** @var array<int, array{elementId: int|string, siteId: int|string, uri: string, content: mixed, dateUpdated: string|null}> $rows */
            $total = count($rows);

            $included = [];
            foreach ($rows as $row) {
                if (SeoFieldReader::noindexFromContent($row['content'])) {
                    continue;
                }
                $included[(int)$row['elementId']] = $row;
            }

            $alternates = $this->_alternates(array_keys($included), $section, (int)$site->id);
            $priority = $this->priorityFor($site, $section);

            $urlEntries = [];
            foreach ($included as $id => $row) {
                $urlEntries[] = SitemapXml::urlEntry(
                    $this->_rowUrl((string)$row['uri'], (int)$row['siteId']),
                    $this->_lastmod($row['dateUpdated']),
                    $alternates[$id] ?? [],
                    $priority,
                );
            }

            $reason = $total === 0
                ? ($page > 1 ? 'page out of range' : 'no live entries with URLs in this section for this site')
                : "all $total live entries on this page are noindexed";

            return SitemapXml::urlset($urlEntries, $reason);
        });
    }

    /**
     * One explain row as the fixed-width text line the two diagnosis
     * surfaces print — `/sitemap.xml?explain` and `sitemap/explain` are
     * documented twins, so the column layout must exist exactly once.
     * Callers prepend their own ✓/✗ mark (the console colors it).
     *
     * @param SitemapExplainRow $row
     */
    public static function explainLine(array $row): string
    {
        return sprintf('%-28s %6d URLs  %s', $row['section'], $row['urls'], $row['reason']);
    }

    /**
     * The full per-section diagnosis — why each section is or isn't in the
     * sitemap, and with how many URLs. Never cached; this is the debug view.
     *
     * @return array<int, SitemapExplainRow>
     */
    public function explain(Site $site): array
    {
        $rows = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSettings = $section->getSiteSettings()[$site->id] ?? null;
            if ($siteSettings === null) {
                $rows[] = $this->_row($section, false, 0, self::REASON_NOT_ENABLED);
                continue;
            }
            if (!$siteSettings->hasUrls) {
                $rows[] = $this->_row($section, false, 0, self::REASON_NO_URLS);
                continue;
            }
            if ($this->_excludedInSettings($section, $site)) {
                $rows[] = $this->_row($section, false, 0, self::REASON_EXCLUDED);
                continue;
            }

            $candidates = $this->_rowQuery($section, (int)$site->id)
                ->select(['es.content'])
                ->all();
            /** @var array<int, array{content: mixed}> $candidates */
            $total = count($candidates);
            if ($total === 0) {
                $rows[] = $this->_row($section, true, 0, self::REASON_NO_ENTRIES);
                continue;
            }

            $included = 0;
            foreach ($candidates as $candidate) {
                if (!SeoFieldReader::noindexFromContent($candidate['content'])) {
                    $included++;
                }
            }

            $rows[] = $this->_row(
                $section,
                true,
                $included,
                $included > 0 ? self::REASON_OK : self::REASON_ALL_NOINDEXED,
                $included > 0 ? [] : ['total' => $total],
            );
        }

        return $rows;
    }

    /**
     * Sections that have URLs on a site — the sitemap candidates before the
     * settings exclusions. The settings screen builds its exclusion checkbox
     * list from this same predicate, so the two surfaces can never disagree.
     *
     * @return Section[]
     */
    public function urlSections(Site $site): array
    {
        return array_values(array_filter(
            Craft::$app->getEntries()->getAllSections(),
            fn(Section $section): bool => $this->_hasUrls($section, $site),
        ));
    }

    /**
     * The configured `<priority>` for a section on a site, or null when none
     * is set — which is the default, and means no element is emitted.
     */
    public function priorityFor(Site $site, Section $section): ?string
    {
        $value = Plugin::getInstance()->getSettings()
            ->sitemapPriorities[$site->uid][$section->uid] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Persists the sitemap settings for one site, leaving every other site's
     * configuration untouched — the settings screens save one site at a time.
     * Included sections arrive as the checked section UIDs and are stored
     * inverted (as exclusions) across every section, so newly created
     * sections default to included and a section can be switched off before
     * it has any URLs. The sitemap cache drops on success.
     *
     * @param string[] $includedSectionUids
     * @param array<string, string> $priorities Section UID => priority
     */
    public function saveSiteSettings(Site $site, array $includedSectionUids, array $priorities = [], bool $enabled = true): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Inverted over EVERY section, not just the ones with URLs today: a
        // section can be switched off before it has URLs, and the choice has
        // to survive until it does. includedSections() still requires URLs,
        // so an exclusion on a URL-less section simply has nothing to do yet.
        $excluded = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!in_array($section->uid, $includedSectionUids, true)) {
                $excluded[] = (string)$section->uid;
            }
        }

        $excludedSections = Settings::withSiteSlice($settings->sitemapExcludedSections, (string)$site->uid, $excluded);

        $clean = [];
        foreach ($priorities as $sectionUid => $priority) {
            $priority = trim((string)$priority);
            if ($priority !== '' && is_numeric($priority)) {
                $clean[(string)$sectionUid] = number_format((float)$priority, 1, '.', '');
            }
        }

        $prioritiesBySite = Settings::withSiteSlice($settings->sitemapPriorities, (string)$site->uid, $clean);

        // Section choices and priorities are kept even when the sitemap is
        // switched off, so turning it back on restores the configuration.
        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->projectConfigPayload([
            'sitemapExcludedSections' => $excludedSections,
            'sitemapPriorities' => $prioritiesBySite,
            'sitemapEnabled' => Settings::withSiteToggle($settings->sitemapEnabled, (string)$site->uid, $enabled),
        ]));

        if ($saved) {
            $this->invalidate();
        }

        return $saved;
    }

    /**
     * Sections included in this site's sitemap: has URLs, not excluded.
     *
     * @return Section[]
     */
    public function includedSections(Site $site): array
    {
        return array_values(array_filter(
            $this->urlSections($site),
            fn(Section $section): bool => !$this->_excludedInSettings($section, $site),
        ));
    }

    /**
     * Drops every cached sitemap file. Wired to entry, section, and settings
     * changes.
     */
    public function invalidate(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
        SeoFieldReader::clearMemos();
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a section has URLs on a site at all.
     */
    private function _hasUrls(Section $section, Site $site): bool
    {
        $siteSettings = $section->getSiteSettings()[$site->id] ?? null;

        return $siteSettings !== null && $siteSettings->hasUrls;
    }

    /**
     * Whether a section belongs in this site's sitemap.
     */
    private function _sectionIncluded(Section $section, Site $site): bool
    {
        return $this->_hasUrls($section, $site) && !$this->_excludedInSettings($section, $site);
    }

    /**
     * Whether the settings exclude a section for a site.
     */
    private function _excludedInSettings(Section $section, Site $site): bool
    {
        $excluded = Plugin::getInstance()->getSettings()->sitemapExcludedSections[$site->uid] ?? [];

        return in_array($section->uid, $excluded, true);
    }

    /**
     * The lean candidate query for a section's sitemap rows — one row per
     * entry per site, live semantics replicated in SQL (no duplicates —
     * ethercreative/seo#145). Pass null for $siteId to get all sites (the
     * alternates lookup).
     *
     * @return Query<array-key, array<string, mixed>>
     */
    private function _rowQuery(Section $section, ?int $siteId): Query
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        $query = (new Query())
            ->select(['es.elementId', 'es.siteId', 'es.uri', 'es.content', 'e.dateUpdated'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->innerJoin(['en' => Table::ENTRIES], '[[en.id]] = [[es.elementId]]')
            ->where([
                'en.sectionId' => $section->id,
                'e.enabled' => true,
                'es.enabled' => true,
                'e.archived' => false,
                'e.dateDeleted' => null,
                'e.draftId' => null,
                'e.revisionId' => null,
            ])
            ->andWhere(['not', ['es.uri' => null]])
            ->andWhere(['!=', 'es.uri', ''])
            ->andWhere(['<=', 'en.postDate', $now])
            ->andWhere(['or', ['en.expiryDate' => null], ['>', 'en.expiryDate', $now]]);

        if ($siteId !== null) {
            $query->andWhere(['es.siteId' => $siteId]);
        }

        return $query;
    }

    /**
     * hreflang alternates for a batch of entry IDs: every site where the
     * entry is live with a URL (ethercreative/seo#318), from one lean query.
     * Single-site entries get none; multi-site entries carry the full set.
     *
     * @param int[] $ids
     * @return array<int, array<int, SitemapAlternate>>
     */
    private function _alternates(array $ids, Section $section, int $currentSiteId): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->_rowQuery($section, null)
            ->select(['es.elementId', 'es.siteId', 'es.uri'])
            ->andWhere(['es.elementId' => $ids])
            ->orderBy(['es.elementId' => SORT_ASC, 'es.siteId' => SORT_ASC])
            ->all();
        /** @var array<int, array{elementId: int|string, siteId: int|string, uri: string}> $rows */

        $byElement = [];
        foreach ($rows as $row) {
            $byElement[(int)$row['elementId']][] = $row;
        }

        $sites = Craft::$app->getSites();
        $alternates = [];
        foreach ($byElement as $id => $elementRows) {
            if (count($elementRows) < 2) {
                continue;
            }
            $links = [];
            foreach ($elementRows as $row) {
                $rowSite = $sites->getSiteById((int)$row['siteId']);
                if ($rowSite === null) {
                    continue;
                }
                $links[] = [
                    'hreflang' => (string)$rowSite->language,
                    'href' => $this->_rowUrl((string)$row['uri'], (int)$row['siteId']),
                ];
            }
            if (count($links) >= 2) {
                $alternates[$id] = $links;
            }
        }

        return $alternates;
    }

    /**
     * Builds an element's URL from its stored URI — what Element::getUrl()
     * does, without the element.
     */
    private function _rowUrl(string $uri, int $siteId): string
    {
        return UrlHelper::siteUrl($uri === Element::HOMEPAGE_URI ? '' : $uri, null, null, $siteId);
    }

    /**
     * Formats a raw UTC dateUpdated for lastmod, converted to the system
     * timezone — identical output to hydrated-element formatting.
     */
    private function _lastmod(?string $raw): ?string
    {
        $date = DateTimeHelper::toDateTime($raw);

        return $date instanceof \DateTime ? $date->format(DATE_W3C) : null;
    }

    /**
     * Builds one explain() row.
     *
     * The reason travels twice: as a `reasonCode` + `reasonParams` pair the CP
     * table translates, and as the pre-built English `reason` text the console
     * command and `?explain` output print verbatim.
     *
     * @param array<string, int> $reasonParams
     * @return SitemapExplainRow
     */
    private function _row(Section $section, bool $included, int $urls, string $reasonCode, array $reasonParams = []): array
    {
        $reason = match ($reasonCode) {
            self::REASON_NOT_ENABLED => 'section is not enabled for this site',
            self::REASON_NO_URLS => 'section has no URLs for this site',
            self::REASON_EXCLUDED => 'excluded in the Simple SEO settings',
            self::REASON_NO_ENTRIES => 'no live entries with URLs',
            self::REASON_ALL_NOINDEXED => sprintf('all %d live entries are noindexed', $reasonParams['total'] ?? 0),
            default => 'OK',
        };

        return [
            'section' => (string)$section->handle,
            // Name and UID are for the CP table; the ?explain text output
            // reads only `section`.
            'name' => (string)$section->name,
            'uid' => (string)$section->uid,
            'included' => $included,
            'urls' => $urls,
            'reason' => $reason,
            'reasonCode' => $reasonCode,
            'reasonParams' => $reasonParams,
        ];
    }

    /**
     * Cache wrapper: 24h TTL behind the shared invalidation tag.
     *
     * @param callable(): string $build
     */
    private function _cached(string $key, callable $build): string
    {
        /** @var string */
        return Craft::$app->getCache()->getOrSet(
            [self::CACHE_TAG, $key],
            $build,
            86400,
            new TagDependency(['tags' => [self::CACHE_TAG]]),
        );
    }
}
