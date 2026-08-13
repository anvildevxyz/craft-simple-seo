<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\models\Finding;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\db\Table;
use craft\helpers\Db;
use IntegrationTester;

/**
 * The checks behind `craft simple-seo/doctor`.
 *
 * The exit code is the product here — a check that reports a problem for a
 * deliberate staging lockdown gets removed from the pipeline within a week,
 * so what counts as a PROBLEM versus a NOTE is the thing worth testing.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class DiagnosticsCest
{
    // Public Methods
    // =========================================================================

    /**
     * A default install reports no problems.
     */
    public function cleanInstallHasNoProblems(IntegrationTester $I): void
    {
        $fixture = $I->createSeoSection('doctorPages', ['uriFormat' => 'doctor/{slug}', 'template' => '_page']);
        $I->createEntryWithSeo($fixture, 'Doctor One', []);

        $problems = $this->_problems($I);

        $I->assertSame([], $problems, 'unexpected problems: ' . json_encode($problems));
    }

    /**
     * A robots.txt that blocks every crawler is a problem — unless the
     * lockdown is on, where it is the intended state.
     */
    public function blanketDisallowIsAProblemUnlessLockedDown(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $I->assertTrue($plugin->getRobots()->saveSiteSettings($site, "User-agent: *\nDisallow: /"));

        $I->assertNotEmpty(
            array_filter($this->_problems($I), static fn(string $p): bool => str_contains($p, 'robots.txt')),
            'a blanket disallow must be reported',
        );

        try {
            $settings->siteWideNoindex = true;

            $I->assertEmpty(
                array_filter($this->_problems($I), static fn(string $p): bool => str_contains($p, 'robots.txt')),
                'under the lockdown a blanket disallow is the intended state',
            );
        } finally {
            $settings->siteWideNoindex = false;
        }
    }

    /**
     * The lockdown itself is a note, never a problem: it is a deliberate,
     * config-file-only flag, and failing on it would break every staging
     * pipeline that uses it correctly.
     */
    public function theLockdownIsANoteNotAProblem(IntegrationTester $I): void
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $settings->siteWideNoindex = true;

            $findings = Plugin::getInstance()->getDiagnostics()->run();
            $lockdown = array_values(array_filter(
                $findings,
                static fn(Finding $f): bool => $f->check === 'Site-wide noindex',
            ));

            $I->assertCount(1, $lockdown);
            $I->assertSame(Finding::LEVEL_NOTE, $lockdown[0]->level);
            $I->assertNotNull($lockdown[0]->fix);
        } finally {
            $settings->siteWideNoindex = false;
        }
    }

    /**
     * A title format missing {title} gives every page on the site the same
     * title. The CP rejects it at save; a project.yaml edit does not.
     */
    public function aTitleFormatWithoutTheTokenIsAProblem(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        // Straight onto the model: savePluginSettings() would reject this, and
        // bypassing validation is exactly how it reaches a real install.
        $plugin->getSettings()->siteSettings = [$site->uid => ['titleFormat' => 'Just the site name']];

        $I->assertNotEmpty(
            array_filter($this->_problems($I), static fn(string $p): bool => str_contains($p, 'Title format')),
        );
    }

    /**
     * The SEO-field check walks its three states — missing, unplaced,
     * placed — and is a note in the first two, never a problem: an install
     * without the field still serves sitemap and robots, so it must not
     * fail a deploy gate.
     */
    public function seoFieldCheckIsANoteUntilTheFieldIsPlaced(IntegrationTester $I): void
    {
        // Earlier Cests can leak a committed SEO field into the shared DB, so
        // the no-field state is established rather than assumed. The raw
        // delete stays inside this test's transaction.
        Db::delete(Table::FIELDS, ['type' => SeoField::class]);
        Craft::$app->getFields()->refreshFields();

        $finding = $this->_seoFieldFinding($I);
        $I->assertSame(Finding::LEVEL_NOTE, $finding->level);
        $I->assertStringContainsString('No field of type SEO', $finding->detail);

        $I->ensureSeoField();

        $finding = $this->_seoFieldFinding($I);
        $I->assertSame(Finding::LEVEL_NOTE, $finding->level);
        $I->assertStringContainsString('no field layout includes it', $finding->detail);

        $I->createSeoSection('doctorFieldPages', ['uriFormat' => 'doctor-field/{slug}', 'template' => '_page']);

        $finding = $this->_seoFieldFinding($I);
        $I->assertSame(Finding::LEVEL_OK, $finding->level);
        $I->assertNull($finding->fix);
    }

    /**
     * Switching a feature off is a note, not a problem — opting out is a
     * supported choice, not a misconfiguration.
     */
    public function switchedOffFeaturesAreNotes(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();

        $I->assertTrue($plugin->getSitemap()->saveSiteSettings($site, [], [], false));
        $I->assertTrue($plugin->getRobots()->saveSiteSettings($site, '', false));

        $findings = $plugin->getDiagnostics()->run();
        $offSwitches = array_values(array_filter(
            $findings,
            static fn(Finding $f): bool => str_contains($f->detail, 'Switched off'),
        ));

        $I->assertCount(2, $offSwitches);
        foreach ($offSwitches as $finding) {
            $I->assertSame(Finding::LEVEL_NOTE, $finding->level);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * @return string[] "check: detail" for every problem-level finding.
     */
    private function _problems(IntegrationTester $I): array
    {
        return array_values(array_map(
            static fn(Finding $f): string => $f->check . ': ' . $f->detail,
            array_filter(
                Plugin::getInstance()->getDiagnostics()->run(),
                static fn(Finding $f): bool => $f->isProblem(),
            ),
        ));
    }

    /**
     * The single SEO-field finding of a doctor run.
     */
    private function _seoFieldFinding(IntegrationTester $I): Finding
    {
        $findings = array_values(array_filter(
            Plugin::getInstance()->getDiagnostics()->run(),
            static fn(Finding $f): bool => $f->check === 'SEO field',
        ));

        $I->assertCount(1, $findings);

        return $findings[0];
    }
}
