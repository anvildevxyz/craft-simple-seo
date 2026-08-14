<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\fields\SeoField;
use anvildev\simpleseo\Permissions;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\services\Entries;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;
use IntegrationTester;

/**
 * Every settings screen renders.
 *
 * Nothing else in the suite renders these templates, which is how two
 * controller variables survived the screens that stopped reading them: a
 * variable can be dropped, or a template can start reading one nobody passes,
 * and every other test stays green. Twig raises on an undefined variable, so
 * rendering each screen is the guard.
 *
 * The controller returns a `template`-format response whose Twig render is
 * deferred to the formatter, so asserting on `$response->data` straight out of
 * runAction() proves nothing — it is still null. The formatter has to be run,
 * and under CP template mode, or the loader cannot see the plugin's templates.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SettingsScreensCest
{
    // Public Methods
    // =========================================================================

    /**
     * The three per-site screens render for a user who can manage settings.
     */
    public function perSiteScreensRender(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS, Permissions::MANAGE_ROBOTS]);

        foreach (['edit', 'edit-sitemap', 'edit-robots'] as $action) {
            $html = $this->_render($I, $action);
            $I->assertNotSame('', $html, "simple-seo/settings/$action rendered nothing");
        }
    }

    /**
     * The Fields screen renders. It is install-wide, so unlike the others it
     * builds its variables without a site.
     */
    public function fieldsScreenRenders(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS]);

        $html = $this->_render($I, 'edit-fields');

        // The install-wide control list is the screen's whole purpose.
        $I->assertStringContainsString('availableSubfields', $html);
    }

    /**
     * First-run states follow the SEO field: with none, General renders the
     * setup steps and the Fields screen its empty state; once a field
     * exists, both give way.
     */
    public function firstRunStatesFollowTheSeoField(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS]);

        // Earlier Cests can leak a committed SEO field into the shared DB, so
        // the no-field state is established rather than assumed. The raw
        // delete stays inside this test's transaction.
        Db::delete(Table::FIELDS, ['type' => SeoField::class]);
        Craft::$app->getFields()->refreshFields();

        $general = $this->_render($I, 'edit');
        $I->assertStringContainsString('create one and add it to your entry types', $general);
        $I->assertStringContainsString('craft.simpleSeo.renderMeta(entry)', $general);
        $I->assertStringContainsString('No SEO field exists yet.', $this->_render($I, 'edit-fields'));

        $I->ensureSeoField();

        $I->assertStringNotContainsString('create one and add it to your entry types', $this->_render($I, 'edit'));
        $I->assertStringNotContainsString('No SEO field exists yet.', $this->_render($I, 'edit-fields'));
    }

    /**
     * The Sitemap screen names why a section carries no URLs — the reason
     * explain() computes reaches the table instead of a bare grey zero —
     * and shows an empty state when no sections exist at all.
     */
    public function sitemapScreenExplainsEmptySections(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS]);

        // Establish the no-sections state (earlier Cests can leak committed
        // sections): raw-delete inside this test's transaction — the FKs
        // cascade — and swap in a fresh Entries service, because its section
        // memo has no public refresh.
        Db::delete(Table::SECTIONS);
        Craft::$app->set('entries', Entries::class);

        $I->assertStringContainsString('No sections exist yet.', $this->_render($I, 'edit-sitemap'));

        $I->createSeoSection('screenEmptyPages', ['uriFormat' => 'screen-empty/{slug}', 'template' => '_page']);

        $html = $this->_render($I, 'edit-sitemap');
        $I->assertStringNotContainsString('No sections exist yet.', $html);
        $I->assertStringContainsString('No live entries yet.', $html);
    }

    /**
     * The screens still render for a reviewer who can open but not save them —
     * the branch where each template swaps its form for a read-only notice.
     */
    public function screensRenderReadOnly(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS]);

        foreach (['edit', 'edit-sitemap', 'edit-robots', 'edit-fields'] as $action) {
            $html = $this->_render($I, $action);
            $I->assertNotSame('', $html, "simple-seo/settings/$action rendered nothing read-only");
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a settings action and forces its deferred template render,
     * returning the HTML.
     */
    private function _render(IntegrationTester $I, string $action): string
    {
        return (string)$I->inCpTemplateMode(static function() use ($action): string {
            /** @var Response $response */
            $response = Craft::$app->runAction("simple-seo/settings/$action");
            (new TemplateResponseFormatter())->format($response);

            return (string)$response->content;
        });
    }

    /**
     * Logs in a non-admin user holding exactly the given permissions.
     *
     * @param string[] $permissions
     */
    private function _actAs(IntegrationTester $I, array $permissions): void
    {
        $user = new User([
            'username' => 'seo-screens',
            'email' => 'seo-screens@example.test',
            'admin' => false,
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($user), json_encode($user->getErrors()));

        // Direct grants, plus accessCp alongside — see PermissionsCest for why
        // both are needed inside a test transaction.
        Craft::$app->getUserPermissions()->saveUserPermissions(
            (int)$user->id,
            array_merge(['accessCp'], $permissions),
        );

        Craft::$app->getUser()->setIdentity($user);
    }
}
