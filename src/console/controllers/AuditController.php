<?php

namespace anvildev\simpleseo\console\controllers;

use anvildev\simpleseo\helpers\Lookup;
use anvildev\simpleseo\models\AuditReport;
use anvildev\simpleseo\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\ExitCode;

/**
 * Meta completeness reporting.
 *
 * Facts about the meta that will ship, never a score.
 * Exits non-zero when anything is found, so it can gate a launch checklist;
 * pass --tolerate to report without failing.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AuditController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Site handle to audit. Defaults to the primary site.
     */
    public ?string $site = null;

    /**
     * @var string|null Limit to one section handle.
     */
    public ?string $section = null;

    /**
     * @var int Entries to list per issue type before summarising the rest.
     */
    public int $limit = 20;

    /**
     * @var bool Exit zero even when failing issues are found.
     */
    public bool $tolerate = false;

    /**
     * @var bool Print the report as JSON instead of the work list. Every
     * issue row is included — --limit only truncates the human output. The
     * exit code is unchanged.
     */
    public bool $json = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site', 'section', 'limit', 'tolerate', 'json']);
    }

    /**
     * Lists live pages whose meta is missing, duplicated, or over the soft
     * length limits.
     */
    public function actionMeta(): int
    {
        $site = Lookup::site($this->site);
        if (is_string($site)) {
            $this->stderr($site . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->section !== null) {
            $section = Lookup::section($this->section);
            if (is_string($section)) {
                $this->stderr($section . "\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Progress only on a real, human-facing terminal: \r is literal in a
        // piped CI log, and the JSON output must stay pure.
        $showProgress = !$this->json && $this->isColorEnabled();

        if (!$this->json) {
            $this->stdout("\nAuditing " . $site->name . ($this->section !== null ? ' · ' . $this->section : '') . "\n", Console::BOLD);
        }

        $report = Plugin::getInstance()->getAudit()->run(
            $site,
            $this->section,
            $showProgress
                ? function(int $count): void {
                    $this->stdout("\r  " . $count . ' entries…', Console::FG_GREY);
                }
                : null,
        );

        if ($this->json) {
            $this->stdout(Json::encode(
                $report->toPayload($site->handle, $this->section),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n");
        } else {
            $this->_printReport($report, $showProgress);
        }

        if ($report->failingTotals() === [] || $this->tolerate) {
            return ExitCode::OK;
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

    // Private Methods
    // =========================================================================

    /**
     * Prints the human-readable report: totals ordered by count, then a
     * per-issue work list.
     */
    private function _printReport(AuditReport $report, bool $clearProgress): void
    {
        if ($clearProgress) {
            $this->stdout("\r" . str_repeat(' ', 40) . "\r");
        }
        $this->stdout('  ' . $report->examined . " live page(s) examined.\n\n");

        if ($report->issues === []) {
            $this->stdout("  Nothing to report.\n\n", Console::FG_GREEN);

            return;
        }

        $failing = $report->failingTotals();
        arsort($report->totals);
        foreach ($report->totals as $issue => $count) {
            $this->stdout(
                sprintf('  %5d  %s' . "\n", $count, $issue),
                isset($failing[$issue]) ? Console::FG_YELLOW : Console::FG_GREY,
            );
        }
        $this->stdout("\n");

        // Grouped by issue so the output reads as a work list rather than a
        // per-entry dump; long lists are truncated because nobody acts on
        // page 4 of a terminal scroll.
        $byIssue = [];
        foreach ($report->issues as $row) {
            $byIssue[$row['issue']][] = $row;
        }

        foreach ($byIssue as $issue => $rows) {
            $this->stdout('  ' . $issue . "\n", Console::BOLD);
            foreach (array_slice($rows, 0, $this->limit) as $row) {
                $this->stdout(sprintf("    #%-8d %s\n", $row['id'], $row['uri']));
            }
            if (count($rows) > $this->limit) {
                $this->stdout(sprintf("    … and %d more\n", count($rows) - $this->limit), Console::FG_GREY);
            }
            $this->stdout("\n");
        }
    }
}
