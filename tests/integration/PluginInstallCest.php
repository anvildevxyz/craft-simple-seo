<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\models\Settings;
use anvildev\simpleseo\Plugin;
use Craft;
use IntegrationTester;

/**
 * Harness proof for the integration suite: the plugin installs into a real
 * Craft app, is enabled, and exposes its settings model.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class PluginInstallCest
{
    // Public Methods
    // =========================================================================

    /**
     * The plugin is installed and enabled in the test app.
     */
    public function pluginIsInstalledAndEnabled(IntegrationTester $I): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('simple-seo');

        $I->assertInstanceOf(Plugin::class, $plugin);
        $I->assertTrue(Craft::$app->getPlugins()->isPluginEnabled('simple-seo'));
    }

    /**
     * The settings model resolves through the plugin instance.
     */
    public function settingsModelResolves(IntegrationTester $I): void
    {
        $plugin = Plugin::getInstance();

        $I->assertNotNull($plugin);
        $I->assertInstanceOf(Settings::class, $plugin->getSettings());
    }
}
