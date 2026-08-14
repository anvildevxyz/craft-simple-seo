<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\models\Finding;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\base\FieldInterface;
use craft\models\Site;
use yii\base\Component;

/**
 * The pre-deploy safety check behind `craft simple-seo/doctor`.
 *
 * Every check here answers one question: is this install about to do
 * something to its search visibility that nobody intended? That is the
 * failure this plugin exists to prevent — ether/seo de-indexed live sites
 * because nothing surfaced the state until traffic vanished
 * (ethercreative/seo#244) — and a check that only runs when someone opens a
 * CP screen is a check that runs too late.
 *
 * Configuration only. Nothing here grades content: see the scope charter.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class DiagnosticsService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Runs every check across every site.
     *
     * @return Finding[]
     */
    public function run(): array
    {
        $findings = $this->_installChecks();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $findings = array_merge($findings, $this->_siteChecks($site));
        }

        return $findings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Checks that concern the install rather than one site.
     *
     * @return Finding[]
     */
    private function _installChecks(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $findings = [];

        // Not a problem — it is a deliberate, config-file-only flag — but it
        // is the single most consequential state this plugin has, so it is
        // always stated rather than left to be discovered.
        $findings[] = new Finding([
            'level' => $settings->siteWideNoindex ? Finding::LEVEL_NOTE : Finding::LEVEL_OK,
            'check' => 'Site-wide noindex',
            'detail' => $settings->siteWideNoindex
                ? 'ON — every page is hidden from search engines, everywhere.'
                : 'Off. No code path can emit a site-wide noindex.',
            'fix' => $settings->siteWideNoindex
                ? 'Correct for staging. On production, remove siteWideNoindex from config/simple-seo.php.'
                : null,
        ]);

        // A file on disk is served by the web server before Craft is reached,
        // so the Robots screen would be editing something nobody sees.
        if ($plugin->getRobots()->isShadowedByFile()) {
            $findings[] = new Finding([
                'level' => Finding::LEVEL_NOTE,
                'check' => 'robots.txt',
                'detail' => 'A physical web/robots.txt exists and is served instead of this plugin’s.',
                'fix' => 'Delete it to manage robots.txt in the CP, or ignore this if the file is intentional.',
            ]);
        }

        $findings[] = $this->_seoFieldCheck();

        return $findings;
    }

    /**
     * Everything entry-level hangs off an SEO field, and "installed it,
     * nothing shows on entries" is the most likely first-hour failure. A
     * missing or unplaced field is a note, never a problem — sitemap and
     * robots work without one, so a field-less install is a supported setup
     * and must not fail a deploy gate.
     */
    private function _seoFieldCheck(): Finding
    {
        $fields = Craft::$app->getFields()->getFieldsByType(SeoField::class);

        if ($fields === []) {
            return new Finding([
                'level' => Finding::LEVEL_NOTE,
                'check' => 'SEO field',
                'detail' => 'No field of type SEO exists, so entries offer no SEO controls.',
                'fix' => 'Create one under Settings → Fields and add it to your entry types.',
            ]);
        }

        $placements = SeoFieldReader::elementUidsForFieldUids(
            array_map(static fn(FieldInterface $field): string => (string)$field->uid, $fields),
        );

        if ($placements === []) {
            return new Finding([
                'level' => Finding::LEVEL_NOTE,
                'check' => 'SEO field',
                'detail' => 'An SEO field exists, but no field layout includes it.',
                'fix' => 'Add it to your entry types under Settings → Entry Types.',
            ]);
        }

        return new Finding([
            'level' => Finding::LEVEL_OK,
            'check' => 'SEO field',
            'detail' => sprintf(
                '%d field%s, %d placement%s in field layouts.',
                count($fields),
                count($fields) === 1 ? '' : 's',
                count($placements),
                count($placements) === 1 ? '' : 's',
            ),
        ]);
    }

    /**
     * Checks that concern one site.
     *
     * @return Finding[]
     */
    private function _siteChecks(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $sitemap = $plugin->getSitemap();
        $robots = $plugin->getRobots();
        $name = (string)$site->name;
        $findings = [];

        // Sitemap: served, and actually carrying URLs.
        if (!$sitemap->isEnabledForSite($site)) {
            $findings[] = new Finding([
                'level' => Finding::LEVEL_NOTE,
                'site' => $name,
                'check' => 'Sitemap',
                'detail' => 'Switched off — this site serves its own.',
            ]);
        } else {
            $urls = array_sum(array_column($sitemap->explain($site), 'urls'));
            $sections = count($sitemap->includedSections($site));

            // Serving an empty sitemap is the "never silently empty"
            // invariant failing in the one place the CP cannot show you: a
            // deploy. The two ways to get there need different fixes, so they
            // are reported differently rather than as one vague warning.
            $findings[] = new Finding([
                'level' => $urls === 0 ? Finding::LEVEL_PROBLEM : Finding::LEVEL_OK,
                'site' => $name,
                'check' => 'Sitemap',
                'detail' => match (true) {
                    $sections === 0 => 'Served, but no section is enabled for this site.',
                    $urls === 0 => 'Served, but every included section is empty.',
                    default => sprintf('%d URL%s across %d section(s).', $urls, $urls === 1 ? '' : 's', $sections),
                },
                'fix' => match (true) {
                    $sections === 0 => 'Switch the sitemap off for this site, or enable a section on it.',
                    $urls === 0 => 'simple-seo/sitemap/explain --site=' . $site->handle . ' names the reason per section.',
                    default => null,
                },
            ]);
        }

        // robots.txt: served, and not locking the site out by accident.
        if (!$robots->isEnabledForSite($site)) {
            $findings[] = new Finding([
                'level' => Finding::LEVEL_NOTE,
                'site' => $name,
                'check' => 'robots.txt',
                'detail' => 'Switched off — this site serves its own.',
            ]);
        } else {
            $blocks = $robots->blocksEverything($robots->contentForSite($site));
            $findings[] = new Finding([
                // Under the lockdown this is the intended state, so it is only
                // a problem when nobody asked for it.
                'level' => $blocks && !$settings->siteWideNoindex ? Finding::LEVEL_PROBLEM : Finding::LEVEL_OK,
                'site' => $name,
                'check' => 'robots.txt',
                'detail' => $blocks
                    ? 'Disallows every crawler from the whole site.'
                    : 'Served, crawlers allowed.',
                'fix' => $blocks && !$settings->siteWideNoindex
                    ? 'Correct for staging. On production, edit it under Simple SEO → Robots.'
                    : null,
            ]);
        }

        // A title format missing {title} gives every page on the site the same
        // title. The CP rejects it at save; config files and project.yaml
        // edits bypass that.
        $format = (string)($settings->siteSettings[$site->uid]['titleFormat'] ?? '');
        if (!TitleFormatter::isValidFormat($format)) {
            $findings[] = new Finding([
                'level' => Finding::LEVEL_PROBLEM,
                'site' => $name,
                'check' => 'Title format',
                'detail' => sprintf('“%s” has no {title} token, so every page shares one title.', $format),
                'fix' => 'Add {title} under Simple SEO → General.',
            ]);
        }

        return $findings;
    }
}
