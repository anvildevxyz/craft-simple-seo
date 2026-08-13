<?php

namespace anvildev\simpleseo\tests\unit\models;

use anvildev\simpleseo\models\Settings;
use craft\base\Model;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidArgumentException;

/**
 * Harness proof for the unit suite: the settings model autoloads and validates.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SettingsTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * The empty settings model must be a valid Craft model out of the box.
     */
    public function testSettingsValidatesWithDefaults(): void
    {
        $settings = new Settings();

        $this->assertInstanceOf(Model::class, $settings);
        $this->assertTrue($settings->validate());
    }

    /**
     * Pins the exact set of settings written to project config.
     *
     * savePluginSettings() deletes any group a save leaves out, so a new
     * setting that nobody classifies would either vanish on every save or get
     * written to project config when it was meant to stay config-file-only.
     * Adding a property to Settings fails this test until it is deliberately
     * put on one side of the line.
     */
    public function testProjectConfigPayloadCoversEveryCpEditableSetting(): void
    {
        $payload = (new Settings())->projectConfigPayload();

        $this->assertSame([
            'siteSettings',
            'sitemapEnabled',
            'robotsTxtEnabled',
            'sitemapExcludedSections',
            'sitemapPriorities',
            'robotsTxt',
            'availableSubfields',
        ], array_keys($payload));
    }

    /**
     * The caller's groups win, and every other group still rides along.
     */
    public function testProjectConfigPayloadMergesChangesOverCurrentValues(): void
    {
        $settings = new Settings();
        $settings->robotsTxt = ['site-uid' => "User-agent: *\nDisallow: /private"];
        $settings->availableSubfields = ['preview', 'title'];

        $payload = $settings->projectConfigPayload(['availableSubfields' => ['title']]);

        $this->assertSame(['title'], $payload['availableSubfields']);
        $this->assertSame(['site-uid' => "User-agent: *\nDisallow: /private"], $payload['robotsTxt']);
    }

    /**
     * A mistyped group name must fail loudly rather than being merged into
     * project config as a junk node.
     */
    public function testProjectConfigPayloadRejectsAnUnknownGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Settings())->projectConfigPayload(['robotsTxts' => []]);
    }

    /**
     * Config-file-only flags must never reach project config: writing an
     * env-gated staging lockdown into project.yaml would make it permanent
     * and deploy it to production (ethercreative/seo#244).
     */
    public function testProjectConfigPayloadNeverCarriesConfigFileOnlyFlags(): void
    {
        $payload = (new Settings())->projectConfigPayload();

        foreach (Settings::CONFIG_FILE_ONLY as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }
}
