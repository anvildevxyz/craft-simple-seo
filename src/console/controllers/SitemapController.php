<?php

namespace anvildev\simpleseo\console\controllers;

use anvildev\simpleseo\helpers\Lookup;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\services\SitemapService;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\models\Site;
use yii\console\ExitCode;

/**
 * Sitemap commands.
 *
 * `explain` is the terminal twin of `/sitemap.xml?explain`, which until now
 * could only be read in a browser by someone who thought to look. The same
 * diagnosis in a pipeline is what turns "never silently empty" from a promise
 * into something a build can enforce.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SitemapController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Site handle to act on. Defaults to every site.
     */
    public ?string $site = null;

    /**
     * @var bool Exit non-zero if any included section contributes no URLs.
     */
    public bool $strict = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'explain' => ['site', 'strict'],
            default => [],
        });
    }

    /**
     * Prints why each section is or isn't in the sitemap, and with how many
     * URLs.
     */
    public function actionExplain(): int
    {
        $sites = $this->_sites();
        if ($sites === []) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $empty = 0;

        foreach ($sites as $site) {
            $sitemap = Plugin::getInstance()->getSitemap();
            $this->stdout("\n" . $site->name . "\n", Console::BOLD);

            if (!$sitemap->isEnabledForSite($site)) {
                $this->stdout("  sitemap switched off for this site\n", Console::FG_YELLOW);
                continue;
            }

            foreach ($sitemap->explain($site) as $row) {
                $this->stdout('  ' . ($row['included'] ? '✓' : '✗') . ' ', $row['included'] ? Console::FG_GREEN : Console::FG_GREY);
                $this->stdout(SitemapService::explainLine($row) . "\n");

                // An included section with no URLs is the case worth failing
                // a build over: it is in the index, so the file exists and is
                // empty, which is the state that looks fine until traffic goes.
                if ($row['included'] && $row['urls'] === 0) {
                    $empty++;
                }
            }
        }

        $this->stdout("\n");

        if ($this->strict && $empty > 0) {
            $this->stderr(sprintf("%d included section(s) contribute no URLs.\n\n", $empty), Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Drops every cached sitemap file.
     *
     * Entry and section changes already invalidate the cache on their own —
     * this is for the writes that bypass those events, like a raw SQL import
     * or a restore from a database dump.
     */
    public function actionFlush(): int
    {
        Plugin::getInstance()->getSitemap()->invalidate();
        $this->stdout("Sitemap cache flushed.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * The sites to act on, or an empty array after reporting a bad handle.
     *
     * @return Site[]
     */
    private function _sites(): array
    {
        if ($this->site === null) {
            return Craft::$app->getSites()->getAllSites();
        }

        $site = Lookup::site($this->site);
        if (is_string($site)) {
            $this->stderr($site . "\n", Console::FG_RED);

            return [];
        }

        return [$site];
    }
}
