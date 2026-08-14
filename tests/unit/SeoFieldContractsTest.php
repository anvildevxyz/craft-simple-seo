<?php

namespace anvildev\simpleseo\tests\unit;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\helpers\TitleFormatter;
use anvildev\simpleseo\models\AuditReport;
use anvildev\simpleseo\services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Guards the parallel constants that must stay in lockstep. Each pair
 * encodes one fact twice; nothing at runtime ties them together, and a
 * mismatch fails silently — a control or directive simply vanishes from a
 * settings screen while the field still honors it.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoFieldContractsTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * Every SUBFIELDS key appears in exactly one SUBFIELD_GROUPS group, in
     * render order. A key missing from the groups would silently lose its
     * settings checkbox — and its next save would disable the control.
     */
    public function testSubfieldGroupsCoverAllSubfieldsInOrder(): void
    {
        $grouped = array_merge(...array_values(SeoField::SUBFIELD_GROUPS));

        $this->assertSame(array_keys(SeoField::SUBFIELDS), $grouped);
    }

    /**
     * Every supported robots directive has a label, in canonical order. A
     * directive without a label would get no settings checkbox, and the next
     * field-settings save would silently drop it from enabledRobotsDirectives.
     */
    public function testDirectiveLabelsCoverAllDirectivesInOrder(): void
    {
        // robotsDirectiveLabels() wraps each label in Craft::t(), which
        // falls through to the source string when no app is loaded.
        $this->assertSame(SeoData::ROBOTS_DIRECTIVES, array_keys(SeoField::robotsDirectiveLabels()));
    }

    /**
     * The audit's "too long" issue strings must name the same limits the
     * field counter shows. Nothing at runtime ties the two together.
     */
    public function testAuditLimitsMatchFieldCounters(): void
    {
        $this->assertStringContainsString((string)SeoField::TITLE_LIMIT, AuditService::ISSUE_TITLE_LONG);
        $this->assertStringContainsString((string)SeoField::DESCRIPTION_LIMIT, AuditService::ISSUE_DESCRIPTION_LONG);
    }

    /**
     * AuditReport::ADVISORY is a literal copy of ISSUE_INHERITED_DESCRIPTION,
     * kept on the model rather than referenced from AuditService so the model
     * stays a leaf and does not import a service. Nothing at runtime ties the
     * two together, so this pins them in lockstep instead.
     */
    public function testAdvisoryMatchesInheritedDescriptionIssue(): void
    {
        $this->assertSame([AuditService::ISSUE_INHERITED_DESCRIPTION], AuditReport::ADVISORY);
    }

    /**
     * The JS title-format fallback must be the PHP DEFAULT_FORMAT. The two
     * implementations are otherwise locked by shared vectors; this catches
     * the one string the vectors do not name.
     */
    public function testJsTitleFormatDefaultMatchesPhp(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/seofield/dist/seo-field.js');

        $this->assertMatchesRegularExpression(
            '/DEFAULT_TITLE_FORMAT = \'' . preg_quote(TitleFormatter::DEFAULT_FORMAT, '/') . '\'/',
            $js,
        );
    }
}
