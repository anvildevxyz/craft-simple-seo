<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\models\AuditReport;
use anvildev\simpleseo\Plugin;
use anvildev\simpleseo\services\AuditService;
use Craft;
use IntegrationTester;

/**
 * The meta audit behind `craft simple-seo/audit/meta`.
 *
 * The distinction that matters: an entry with no description of its own is
 * NOT a duplicate of every other such entry. They all resolve to the same
 * site default, so counting them as duplicates buries the real finding under
 * one row per page. That false positive is what this covers.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AuditCest
{
    // Public Methods
    // =========================================================================

    /**
     * Entries falling back to the site default are reported as lacking their
     * own description, not as duplicates of each other — and it is advisory,
     * because a site default is a supported way to run a site.
     */
    public function inheritedDescriptionsAreAdvisoryNotDuplicates(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $I->assertTrue($plugin->getSiteDefaults()->saveSiteSettings($site, '{title}', 'One shared default.'));

        $fixture = $I->createSeoSection('auditPages', ['uriFormat' => 'audit/{slug}', 'template' => '_page']);
        $I->createEntryWithSeo($fixture, 'Audit One', []);
        $I->createEntryWithSeo($fixture, 'Audit Two', []);

        $report = $plugin->getAudit()->run($site, 'auditPages');

        $I->assertSame(2, $report->examined);
        $I->assertSame(2, $report->totals[AuditReport::ISSUE_INHERITED_DESCRIPTION] ?? 0);
        $I->assertSame([], $report->failingTotals(), 'inheriting the site default must not fail a run');

        foreach (array_keys($report->totals) as $issue) {
            $I->assertStringNotContainsString('duplicate description', $issue);
        }
    }

    /**
     * Two entries that author the same description are a genuine duplicate,
     * and that does fail.
     */
    public function authoredDuplicateDescriptionsFail(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $fixture = $I->createSeoSection('auditDupes', ['uriFormat' => 'dupes/{slug}', 'template' => '_page']);

        $I->createEntryWithSeo($fixture, 'Dupe One', ['description' => 'Exactly the same text.']);
        $I->createEntryWithSeo($fixture, 'Dupe Two', ['description' => 'Exactly the same text.']);

        $report = Plugin::getInstance()->getAudit()->run($site, 'auditDupes');

        $failing = $report->failingTotals();
        $I->assertNotEmpty($failing);
        $I->assertNotEmpty(
            array_filter(array_keys($failing), static fn(string $k): bool => str_contains($k, 'duplicate description')),
            'authored duplicates must fail: ' . json_encode($failing),
        );
    }

    /**
     * An entry with no description and no site default has nothing to ship,
     * which is a different finding from inheriting one.
     */
    public function anEntryWithNothingToShipIsReportedSeparately(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $I->assertTrue($plugin->getSiteDefaults()->saveSiteSettings($site, '{title}', ''));

        $fixture = $I->createSeoSection('auditBare', ['uriFormat' => 'bare/{slug}', 'template' => '_page']);
        $I->createEntryWithSeo($fixture, 'Bare One', []);

        $report = $plugin->getAudit()->run($site, 'auditBare');

        $I->assertSame(1, $report->totals[AuditService::ISSUE_NO_DESCRIPTION] ?? 0);
        $I->assertArrayNotHasKey(AuditReport::ISSUE_INHERITED_DESCRIPTION, $report->totals);
        $I->assertNotEmpty($report->failingTotals(), 'shipping no description at all must fail');
    }

    /**
     * Over-length is measured on the resolved value against the same soft
     * limit the field's counter uses.
     */
    public function overLengthValuesAreReported(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $fixture = $I->createSeoSection('auditLong', ['uriFormat' => 'long/{slug}', 'template' => '_page']);

        $I->createEntryWithSeo($fixture, 'Long One', [
            'description' => str_repeat('a', SeoField::DESCRIPTION_LIMIT + 1),
        ]);

        $report = Plugin::getInstance()->getAudit()->run($site, 'auditLong');

        $I->assertSame(1, $report->totals[AuditService::ISSUE_DESCRIPTION_LONG] ?? 0);
    }

    /**
     * The --json payload shape is contract: pipelines parse it. Advisory
     * issues count in totals but stay out of `failing`, and an empty totals
     * map must encode as an object, never as an empty list.
     */
    public function jsonPayloadShapeIsStable(IntegrationTester $I): void
    {
        $report = new AuditReport();
        $report->examined = 3;
        $report->add(11, 'about', AuditService::ISSUE_DESCRIPTION_LONG);
        $report->add(12, 'contact', AuditReport::ISSUE_INHERITED_DESCRIPTION);

        $payload = $report->toPayload('default', null);

        $I->assertSame(
            ['site', 'section', 'examined', 'totals', 'failing', 'issues'],
            array_keys($payload),
        );
        $I->assertSame('default', $payload['site']);
        $I->assertNull($payload['section']);
        $I->assertSame(3, $payload['examined']);
        $I->assertSame([AuditService::ISSUE_DESCRIPTION_LONG], $payload['failing']);
        $I->assertSame(
            ['id' => 11, 'uri' => 'about', 'issue' => AuditService::ISSUE_DESCRIPTION_LONG],
            $payload['issues'][0],
        );

        $empty = (new AuditReport())->toPayload('default', 'news');
        $I->assertInstanceOf(\stdClass::class, $empty['totals']);
        $I->assertSame('{}', json_encode($empty['totals']));
    }
}
