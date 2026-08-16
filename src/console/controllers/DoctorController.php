<?php

namespace anvildev\simpleseo\console\controllers;

use anvildev\simpleseo\models\Finding;
use anvildev\simpleseo\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\ExitCode;

/**
 * `craft simple-seo/doctor` — the pre-deploy check.
 *
 * Exits non-zero when it finds a problem, so it works as a deploy or CI gate
 * rather than something a human has to remember to read. Notes never fail the
 * run: a staging lockdown is correct, and a command that cried wolf about it
 * would get removed from the pipeline within a week.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class DoctorController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Report problems only, skipping healthy checks and notes.
     */
    public bool $quiet = false;

    /**
     * @var bool Print the findings as JSON instead of the table. The exit
     * code is unchanged, so a pipeline can parse and gate in one call.
     */
    public bool $json = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['quiet', 'json']);
    }

    /**
     * Checks this install for configuration that would damage its search
     * visibility, and exits non-zero if it finds any.
     */
    public function actionIndex(): int
    {
        $findings = Plugin::getInstance()->getDiagnostics()->run();
        $problems = array_filter($findings, static fn(Finding $f): bool => $f->isProblem());
        // --quiet filters both outputs the same way; the problems count and
        // the exit code always come from the full set.
        $shown = $this->quiet ? $problems : $findings;

        if ($this->json) {
            $this->stdout(Json::encode(Finding::listPayload($shown), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

            return $problems === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nSimple SEO check\n\n", Console::BOLD);

        // Size the columns to the content. Site names are author-supplied and
        // routinely wider than a guessed width, and padding has to be
        // character-aware — str_pad() counts bytes, so one "Français" would
        // shift every row after it.
        $maxSite = $maxCheck = 0;
        foreach ($shown as $f) {
            $maxSite = max($maxSite, mb_strlen($f->site ?? '—'));
            $maxCheck = max($maxCheck, mb_strlen($f->check));
        }
        $siteWidth = 4 + $maxSite;
        $checkWidth = 4 + $maxCheck;

        foreach ($shown as $finding) {
            [$mark, $color] = match ($finding->level) {
                Finding::LEVEL_PROBLEM => ['✗', Console::FG_RED],
                Finding::LEVEL_NOTE => ['!', Console::FG_YELLOW],
                default => ['✓', Console::FG_GREEN],
            };

            $this->stdout('  ' . $mark . ' ', $color);
            $this->stdout($this->_pad($finding->site ?? '—', $siteWidth));
            $this->stdout($this->_pad($finding->check, $checkWidth));
            $this->stdout($finding->detail . "\n");

            if ($finding->fix !== null) {
                $this->stdout(str_repeat(' ', 4 + $siteWidth + $checkWidth) . '↳ ' . $finding->fix . "\n", Console::FG_GREY);
            }
        }

        if ($problems === []) {
            $this->stdout("\nNo problems found.\n\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("\n%d problem(s) found.\n\n", count($problems)), Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    // Private Methods
    // =========================================================================

    /**
     * Character-aware right-pad. str_pad() counts bytes, so a single accented
     * site name would misalign every column to its right.
     */
    private function _pad(string $value, int $width): string
    {
        return $value . str_repeat(' ', max(1, $width - mb_strlen($value)));
    }
}
