<?php

namespace anvildev\simpleseo\models;

use craft\base\Model;

/**
 * One result from a diagnostic check.
 *
 * Three levels, and the distinction is what makes the command usable in CI:
 * PROBLEM means SEO is actively broken and the run should fail; NOTE means a
 * deliberate configuration worth restating so nobody is surprised by it; OK
 * means checked and healthy. Only PROBLEM affects the exit code.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Finding extends Model
{
    // Constants
    // =========================================================================

    public const LEVEL_OK = 'ok';
    public const LEVEL_NOTE = 'note';
    public const LEVEL_PROBLEM = 'problem';

    // Public Properties
    // =========================================================================

    /**
     * @var string One of the LEVEL_* constants.
     */
    public string $level = self::LEVEL_OK;

    /**
     * @var string|null The site this concerns, or null for install-wide.
     */
    public ?string $site = null;

    /**
     * @var string What was checked, in a few words.
     */
    public string $check = '';

    /**
     * @var string What was found, in one line.
     */
    public string $detail = '';

    /**
     * @var string|null What to do about it. Problems should always carry one.
     */
    public ?string $fix = null;

    // Public Methods
    // =========================================================================

    public function isProblem(): bool
    {
        return $this->level === self::LEVEL_PROBLEM;
    }

    /**
     * A set of findings as the one machine-readable doctor report — the
     * shape shared by `doctor --json` and the MCP doctor tool, so the two
     * can never drift.
     *
     * @param self[] $findings
     * @return array{problems: int, findings: list<array{level: string, site: string|null, check: string, detail: string, fix: string|null}>}
     */
    public static function listPayload(array $findings): array
    {
        return [
            'problems' => count(array_filter($findings, static fn(self $f): bool => $f->isProblem())),
            'findings' => array_map(static fn(self $f): array => $f->toPayload(), array_values($findings)),
        ];
    }

    /**
     * The finding as a stable machine-readable shape for --json output.
     * Key order and names are contract: pipelines parse this.
     *
     * @return array{level: string, site: string|null, check: string, detail: string, fix: string|null}
     */
    public function toPayload(): array
    {
        return [
            'level' => $this->level,
            'site' => $this->site,
            'check' => $this->check,
            'detail' => $this->detail,
            'fix' => $this->fix,
        ];
    }
}
