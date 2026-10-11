<?php

namespace anvildev\simpleseo\console\controllers;

use anvildev\simpleseo\models\EtherMigrationReport;
use anvildev\simpleseo\Plugin;
use craft\console\Controller;
use craft\helpers\Json;
use yii\console\ExitCode;

/**
 * Migration commands.
 *
 * `craft simple-seo/migrate/ether` is a DRY RUN by default — it reports
 * everything a migration would do and writes nothing. Pass --apply to
 * migrate for real.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class MigrateController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Actually write changes. Without it the run is a dry run.
     */
    public bool $apply = false;

    /**
     * @var string|null Target path for the Retour-importable redirects CSV.
     */
    public ?string $csv = null;

    /**
     * @var bool Also carry the ether SETTINGS that have a faithful equivalent
     * here: its site-wide robots rule, and its switched-off sitemap sections.
     * Never implied by --apply — the robots rule de-indexes pages, so opting
     * in is how you say it was deliberate.
     */
    public bool $carrySettings = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['apply', 'csv', 'carrySettings']);
    }

    /**
     * Migrates ether/seo field data, exports its redirects as a
     * Retour-importable CSV, and surfaces its settings for review.
     */
    public function actionEther(): int
    {
        $service = Plugin::getInstance()->getEtherMigration();
        $report = $this->apply
            ? $service->apply($this->csv, $this->carrySettings)
            : $service->analyze($this->carrySettings);

        $this->stdout($this->apply ? "Ether SEO migration — APPLIED\n\n" : "Ether SEO migration — DRY RUN (nothing written)\n\n");

        if ($report->fields !== []) {
            $this->stdout("Fields:\n");
            foreach ($report->fields as $field) {
                $this->stdout("  - {$field['name']} ({$field['handle']}) — {$field['layoutElements']} layout placement(s)\n");
            }
            $this->stdout("\n");
        }

        $this->stdout($this->_summary($report));

        foreach ($report->notes as $note) {
            $this->stdout("• $note\n");
        }

        if ($report->etherSettings !== null) {
            $this->stdout("\nEther settings (for manual review):\n" . Json::encode($report->etherSettings) . "\n");
        }

        if (!$this->apply) {
            $this->stdout("\nDry run only. Re-run with --apply to migrate.\n");
        }

        if ($report->carriedSiteWideRobots > 0 || $report->carriedSitemapExclusions > 0) {
            $this->stdout(sprintf(
                "\n--carry-settings: %d value(s) %s ether's site-wide robots, %d sitemap exclusion(s) %s.\n",
                $report->carriedSiteWideRobots,
                $report->applied ? 'given' : 'would be given',
                $report->carriedSitemapExclusions,
                $report->applied ? 'carried' : 'would be carried',
            ));
        }

        if ($report->failures !== []) {
            $this->stderr(sprintf(
                "\n%d step(s) failed — this migration is PARTIAL. Fix the cause and re-run; the run is idempotent.\n",
                count($report->failures),
            ));

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Formats the tallies block.
     */
    private function _summary(EtherMigrationReport $report): string
    {
        $verb = $report->applied ? 'converted' : 'would convert';
        $lines = [
            sprintf('%d ether value(s) found; %s %d (%d already migrated, skipped)', $report->etherValues, $verb, $report->converted, $report->alreadyMigrated),
            sprintf(
                '  titles: %d, descriptions: %d, images: %d, robots: %d, extra directives: %d, canonicals: %d',
                $report->titles,
                $report->descriptions,
                $report->images,
                $report->robots,
                $report->directives,
                $report->canonicals,
            ),
        ];

        foreach ($report->perSite as $siteId => $count) {
            $lines[] = "  site $siteId: $count value(s)";
        }

        if ($report->sitemapRowsFound > 0) {
            $lines[] = sprintf(
                '%d ether sitemap row(s) found — %s',
                $report->sitemapRowsFound,
                match (true) {
                    $report->carriedSitemapExclusions === 0 => 'none imported',
                    $report->applied => "$report->carriedSitemapExclusions exclusion(s) carried",
                    default => "$report->carriedSitemapExclusions exclusion(s) would be carried",
                },
            );
        }

        $lines[] = sprintf(
            '%d redirect(s) found%s',
            $report->redirectsFound,
            $report->redirectsCsvPath !== null ? " — CSV: $report->redirectsCsvPath" : '',
        );

        return implode("\n", $lines) . "\n\n";
    }
}
