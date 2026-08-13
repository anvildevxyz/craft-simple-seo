<?php

namespace anvildev\simpleseo\models;

use anvildev\simpleseo\services\AuditService;
use craft\base\Model;

/**
 * What a meta audit found.
 *
 * Counts and lists only. There is deliberately no score, grade, or overall
 * verdict here. Every number below is a fact about the meta that will ship;
 * none of them is an opinion about the writing.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AuditReport extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int Live, URL-having entries examined.
     */
    public int $examined = 0;

    /**
     * @var array<int, array{id: int, uri: string, issue: string}> One row per
     * problem found, in report order.
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
     * @return array{site: string, section: string|null, examined: int, totals: array<string, int>|\stdClass, failing: list<string>, issues: array<int, array{id: int, uri: string, issue: string}>}
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
                AuditService::ADVISORY,
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
