<?php

namespace anvildev\simpleseo\models;

use craft\base\Model;

/**
 * What a meta audit found.
 *
 * Counts and lists only. There is deliberately no score, grade, or overall
 * verdict here. Every number below is a fact about the meta that will ship;
 * none of them is an opinion about the writing.
 *
 * @phpstan-type AuditIssueRow array{id: int, uri: string, issue: string}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AuditReport extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The issue label for an entry whose description resolves to
     * the site default. Declared here rather than beside the rest of the
     * ISSUE_* family on AuditService because ADVISORY consumes it — a model
     * referencing a service constant would put this class in a cycle.
     */
    public const ISSUE_INHERITED_DESCRIPTION = 'no own description (site default shown)';

    /**
     * @var string[] Issues that are reported but never fail the run.
     *
     * Leaning on a per-site default description is a documented feature of
     * this plugin, not a defect. Failing a build for using it as designed
     * would teach everyone to pass --tolerate permanently, which costs the
     * gate its whole value.
     */
    public const ADVISORY = [
        self::ISSUE_INHERITED_DESCRIPTION,
    ];

    // Public Properties
    // =========================================================================

    /**
     * @var int Live, URL-having entries examined.
     */
    public int $examined = 0;

    /**
     * @var array<int, AuditIssueRow> One row per problem found, in report order.
     */
    public array $issues = [];

    /**
     * @var array<string, int> Issue label => number of entries with it.
     */
    public array $totals = [];

    // Public Methods
    // =========================================================================

    /**
     * Records one issue against one entry.
     */
    public function add(int $id, string $uri, string $issue): void
    {
        $this->issues[] = ['id' => $id, 'uri' => $uri, 'issue' => $issue];
        $this->totals[$issue] = ($this->totals[$issue] ?? 0) + 1;
    }

    /**
     * The report as a stable machine-readable shape for --json output.
     * Every issue row is included, whatever the human output's --limit
     * says. Key order and names are contract: pipelines parse this.
     *
     * @return array{site: string, section: string|null, examined: int, totals: array<string, int>|\stdClass, failing: list<string>, issues: array<int, AuditIssueRow>}
     */
    public function toPayload(string $siteHandle, ?string $sectionHandle): array
    {
        return [
            'site' => $siteHandle,
            'section' => $sectionHandle,
            'examined' => $this->examined,
            // An empty totals map must encode as {}, not [].
            'totals' => $this->totals === [] ? new \stdClass() : $this->totals,
            'failing' => array_keys($this->failingTotals()),
            'issues' => $this->issues,
        ];
    }

    /**
     * Issues that should fail a run, i.e. everything not advisory.
     *
     * @return array<string, int>
     */
    public function failingTotals(): array
    {
        return array_filter(
            $this->totals,
            static fn(string $issue): bool => !in_array(
                // Duplicate labels carry their group size, so match on the stem.
                preg_replace('/ \(\d+\)$/', '', $issue),
                self::ADVISORY,
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
