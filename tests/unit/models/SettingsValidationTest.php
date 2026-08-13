<?php

namespace anvildev\simpleseo\tests\unit\models;

use anvildev\simpleseo\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Title-format validation: a format without {title} would give every page on
 * the site the same title — caught at save (ethercreative/seo#472-class).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SettingsValidationTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * A format missing the {title} token fails validation with a per-site error.
     */
    public function testFormatWithoutTitleTokenFails(): void
    {
        $settings = new Settings();
        $settings->siteSettings = [
            'site-uid-1' => ['titleFormat' => '{siteName} only'],
        ];

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('siteSettings.site-uid-1.titleFormat'));
    }

    /**
     * Valid formats — including a bare {title} (the "no site name" choice) and
     * empty (reset to default) — pass.
     */
    public function testValidFormatsPass(): void
    {
        $settings = new Settings();
        $settings->siteSettings = [
            'site-uid-1' => ['titleFormat' => '{title} | {siteName}'],
            'site-uid-2' => ['titleFormat' => '{title}'],
            'site-uid-3' => ['titleFormat' => '', 'defaultDescription' => 'Hello'],
        ];

        $this->assertTrue($settings->validate(), json_encode($settings->getErrors()) ?: '');
    }
}
