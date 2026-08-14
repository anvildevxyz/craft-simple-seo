<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\models\Site;
use yii\base\Component;

/**
 * robots.txt content: the shipped default, per-site author overrides, and the
 * safety checks around both.
 *
 * The zero-config default stays the default — an install that never opens the
 * Robots screen keeps serving an open robots.txt referencing its sitemap.
 * Author content is stored verbatim and served verbatim (no Twig rendering:
 * robots.txt is data, and running it through a template engine would turn a
 * settings field into a code-execution surface).
 *
 * `siteWideNoindex` still wins over everything here — it is the one flag that
 * can lock a whole environment, it is config-file-only, and it stays that way
 * (ethercreative/seo#244).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class RobotsService extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Token replaced with the site's sitemap index URL. Plain
     * string substitution, deliberately not Twig.
     */
    public const SITEMAP_TOKEN = '{sitemapUrl}';

    // Public Methods
    // =========================================================================

    /**
     * Whether this plugin serves `/robots.txt` for a site.
     *
     * `siteWideNoindex` forces it on: robots.txt is one of the three arms of
     * that lockdown, and a settings toggle must not be able to remove one
     * (ethercreative/seo#244).
     */
    public function isEnabledForSite(Site $site): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->siteWideNoindex || ($settings->robotsTxtEnabled[$site->uid] ?? true);
    }

    /**
     * Whether a physical `web/robots.txt` exists and will be served instead of
     * this plugin's route — web servers serve files before Craft.
     */
    public function isShadowedByFile(): bool
    {
        $webroot = Craft::getAlias('@webroot', false);

        return is_string($webroot) && file_exists($webroot . DIRECTORY_SEPARATOR . 'robots.txt');
    }

    /**
     * Returns the robots.txt body served for a site: the environment lockdown
     * if `siteWideNoindex` is on, otherwise the author's content, otherwise
     * the shipped default.
     */
    public function contentForSite(Site $site): string
    {
        if (Plugin::getInstance()->getSettings()->siteWideNoindex) {
            return "User-agent: *\nDisallow: /\n";
        }

        $body = $this->customForSite($site) ?? $this->defaultForSite($site);

        return rtrim($this->_expandTokens($body, $site), "\n") . "\n";
    }

    /**
     * Returns the author's stored content for a site, or null when they have
     * never saved any (which is what keeps the default live).
     */
    public function customForSite(Site $site): ?string
    {
        $stored = Plugin::getInstance()->getSettings()->robotsTxt[$site->uid] ?? null;
        $stored = is_string($stored) ? trim($stored) : '';

        return $stored !== '' ? $stored : null;
    }

    /**
     * The shipped default: allow everything, and point at the sitemap when
     * this plugin is the one serving it. With the sitemap switched off we
     * can't vouch for what lives at that URL, so the reference is dropped
     * rather than advertising a file that may 404.
     */
    public function defaultForSite(Site $site): string
    {
        $body = "User-agent: *\nDisallow:\n";

        if (Plugin::getInstance()->getSitemap()->isEnabledForSite($site)) {
            $body .= "\nSitemap: " . $this->sitemapUrl($site) . "\n";
        }

        return $body;
    }

    /**
     * The site's sitemap index URL — also what {@see self::SITEMAP_TOKEN}
     * expands to.
     */
    public function sitemapUrl(Site $site): string
    {
        return UrlHelper::siteUrl('sitemap.xml', null, null, (int)$site->id);
    }

    /**
     * Whether a robots.txt body blocks every crawler from the whole site.
     * Used to warn in the CP before saving: an author is allowed to do this
     * deliberately, but never without being told what it means.
     */
    public function blocksEverything(string $body): bool
    {
        $wildcardGroup = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$directive, $value] = array_pad(explode(':', $line, 2), 2, '');
            $directive = strtolower(trim($directive));
            $value = trim($value);

            if ($directive === 'user-agent') {
                $wildcardGroup = $value === '*';
                continue;
            }

            if ($wildcardGroup && $directive === 'disallow' && $value === '/') {
                return true;
            }
        }

        return false;
    }

    /**
     * Persists one site's robots.txt content and whether this plugin serves
     * the file at all, leaving every other site's untouched. Empty content
     * clears the override and restores the default.
     */
    public function saveSiteSettings(Site $site, string $body, bool $enabled = true): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $robotsTxt = Settings::withSiteSlice($settings->robotsTxt, (string)$site->uid, trim($body));

        // Content is kept even when the file is switched off, so turning it
        // back on restores what the author wrote.
        return Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->projectConfigPayload([
            'robotsTxt' => $robotsTxt,
            'robotsTxtEnabled' => Settings::withSiteToggle($settings->robotsTxtEnabled, (string)$site->uid, $enabled),
        ]));
    }

    // Private Methods
    // =========================================================================

    /**
     * Expands the supported tokens in a robots.txt body.
     */
    private function _expandTokens(string $body, Site $site): string
    {
        return str_replace(self::SITEMAP_TOKEN, $this->sitemapUrl($site), $body);
    }
}
