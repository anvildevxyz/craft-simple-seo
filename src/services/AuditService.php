<?php

namespace anvildev\simpleseo\services;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\models\AuditReport;
use anvildev\simpleseo\Plugin;
use craft\elements\Entry;
use craft\models\Site;
use yii\base\Component;

/**
 * Meta completeness reporting behind `craft simple-seo/audit/meta`.
 *
 * Reports facts about the meta that will actually ship, never a judgement
 * about the writing: no keyword scoring, no readability grade, no overall
 * score. That line is the scope charter's, and it is the difference between
 * a report someone can act on and the "SEO score" theatre this plugin exists
 * to avoid.
 *
 * Every check runs against RESOLVED meta — the values the front end emits,
 * with the per-site defaults and title format already applied. Auditing raw
 * field values instead would report a missing description on every entry of
 * a site whose default fills it in, which is the classic false positive.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AuditService extends Component
{
    // Const Properties
    // =========================================================================

    public const ISSUE_NO_DESCRIPTION = 'no description at all';
    public const ISSUE_INHERITED_DESCRIPTION = 'no own description (site default shown)';
    public const ISSUE_TITLE_LONG = 'title over ' . SeoField::TITLE_LIMIT . ' chars';
    public const ISSUE_DESCRIPTION_LONG = 'description over ' . SeoField::DESCRIPTION_LIMIT . ' chars';
    public const ISSUE_DUPLICATE_TITLE = 'duplicate title';
    public const ISSUE_DUPLICATE_DESCRIPTION = 'duplicate description';

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

    /**
     * @var int Entries hydrated per batch.
     */
    public int $batchSize = 100;

    // Public Methods
    // =========================================================================

    /**
     * Audits every live, URL-having entry on a site.
     *
     * Live and URL-having because those are the pages search engines actually
     * see — auditing drafts or URL-less entries would bury the real findings.
     *
     * @param callable(int): void|null $onProgress Called with the running count.
     */
    public function run(Site $site, ?string $sectionHandle = null, ?callable $onProgress = null): AuditReport
    {
        $report = new AuditReport();
        $meta = Plugin::getInstance()->getMeta();

        /** @var array<string, array<int, string>> $titles */
        $titles = [];
        /** @var array<string, array<int, string>> $descriptions */
        $descriptions = [];

        $offset = 0;
        while (true) {
            $query = Entry::find()
                ->site($site)
                ->status(Entry::STATUS_LIVE)
                ->uri(':notempty:')
                ->orderBy(['elements.id' => SORT_ASC])
                ->offset($offset)
                ->limit($this->batchSize);

            if ($sectionHandle !== null) {
                $query->section($sectionHandle);
            }

            $entries = $query->all();
            if ($entries === []) {
                break;
            }
            $offset += $this->batchSize;

            foreach ($entries as $entry) {
                $report->examined++;
                $uri = (string)$entry->uri;
                $id = (int)$entry->id;

                $resolved = $meta->resolve($entry);
                $title = trim((string)$resolved->title);
                $description = trim((string)$resolved->description);

                // Whether the description is the entry's own matters more than
                // whether one exists. Every entry without one resolves to the
                // same site default, so counting those as duplicates would
                // bury the actual finding under one row per page — the noise
                // that makes audits get ignored.
                $own = trim((string)(SeoFieldReader::read($entry)?->description ?? ''));

                if ($own === '') {
                    $report->add(
                        $id,
                        $uri,
                        $description === '' ? self::ISSUE_NO_DESCRIPTION : self::ISSUE_INHERITED_DESCRIPTION,
                    );
                } else {
                    // Only authored descriptions can meaningfully duplicate.
                    $descriptions[mb_strtolower($own)][$id] = $uri;

                    if (mb_strlen($description) > SeoField::DESCRIPTION_LIMIT) {
                        $report->add($id, $uri, self::ISSUE_DESCRIPTION_LONG);
                    }
                }

                if ($title !== '') {
                    $titles[mb_strtolower($title)][$id] = $uri;

                    if (mb_strlen($title) > SeoField::TITLE_LIMIT) {
                        $report->add($id, $uri, self::ISSUE_TITLE_LONG);
                    }
                }
            }

            if ($onProgress !== null) {
                $onProgress($report->examined);
            }
        }

        $this->_addDuplicates($report, $titles, self::ISSUE_DUPLICATE_TITLE);
        $this->_addDuplicates($report, $descriptions, self::ISSUE_DUPLICATE_DESCRIPTION);

        return $report;
    }

    // Private Methods
    // =========================================================================

    /**
     * Records every entry sharing a value with at least one other.
     *
     * Compared case-insensitively on the resolved value, so two pages whose
     * titles differ only in capitalisation still count as duplicates —
     * search engines treat them as the same string.
     *
     * @param array<string, array<int, string>> $seen Value => [entry id => uri]
     */
    private function _addDuplicates(AuditReport $report, array $seen, string $issue): void
    {
        foreach ($seen as $entries) {
            if (count($entries) < 2) {
                continue;
            }

            foreach ($entries as $id => $uri) {
                $report->add($id, $uri, $issue . ' (' . count($entries) . ')');
            }
        }
    }
}
