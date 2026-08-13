<?php

namespace anvildev\simpleseo\tests\integration;

use anvildev\simpleseo\Permissions;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\User;
use IntegrationTester;
use yii\web\ForbiddenHttpException;

/**
 * The settings screens are permission-gated, not admin-only, so an SEO role
 * can own them. Craft's own `accessPlugin-simple-seo` is the read gate; the
 * plugin's two permissions govern saving, with robots.txt separated because
 * it is the one screen that can stop the site being crawled at all.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class PermissionsCest
{
    // Public Methods
    // =========================================================================

    /**
     * The permissions are registered under their own heading, with robots
     * nested beneath the settings permission.
     */
    public function permissionsAreRegistered(IntegrationTester $I): void
    {
        $all = Craft::$app->getUserPermissions()->getAllPermissions();

        $group = null;
        foreach ($all as $section) {
            if (($section['heading'] ?? null) === 'Simple SEO') {
                $group = $section['permissions'];
                break;
            }
        }

        $I->assertNotNull($group, 'Simple SEO permission heading must be registered');
        $I->assertArrayHasKey(Permissions::MANAGE_SETTINGS, $group);
        $I->assertArrayHasKey(
            Permissions::MANAGE_ROBOTS,
            $group[Permissions::MANAGE_SETTINGS]['nested'] ?? [],
            'robots must be nested under the settings permission',
        );
    }

    /**
     * A CP user without the plugin's access permission cannot open the
     * screens — the realistic "editor who has nothing to do with SEO" case.
     */
    public function withoutAccessTheScreensAre403(IntegrationTester $I): void
    {
        $this->_actAs($I, ['accessCp']);

        $I->expectThrowable(ForbiddenHttpException::class, function() {
            Craft::$app->runAction('simple-seo/settings/edit');
        });
    }

    /**
     * Access alone opens the screens but cannot save them — the read-only
     * reviewer role.
     */
    public function accessWithoutManageCannotSave(IntegrationTester $I): void
    {
        $this->_actAs($I, [Permissions::ACCESS]);

        // Viewing is fine.
        Craft::$app->runAction('simple-seo/settings/edit');

        $I->expectThrowable(ForbiddenHttpException::class, fn() => $I->postToAction(
            'simple-seo/settings/save',
            ['siteUid' => Craft::$app->getSites()->getPrimarySite()->uid],
        ));
    }

    /**
     * MANAGE_SETTINGS saves General, but robots.txt stays out of reach: that
     * permission is deliberately separate.
     */
    public function manageSettingsDoesNotGrantRobots(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS]);

        $I->postToAction('simple-seo/settings/save', [
            'siteUid' => $site->uid,
            'titleFormat' => '{title} — allowed',
            'defaultDescription' => '',
            'defaultSocialImage' => [],
        ]);

        $I->assertSame(
            '{title} — allowed',
            Plugin::getInstance()->getSiteDefaults()->getForSite((int)$site->id)->titleFormat,
        );

        $I->expectThrowable(ForbiddenHttpException::class, fn() => $I->postToAction(
            'simple-seo/settings/save-robots',
            ['siteUid' => $site->uid, 'robotsTxt' => "User-agent: *\nDisallow: /"],
        ));

        $I->assertNull(
            Plugin::getInstance()->getRobots()->customForSite($site),
            'robots.txt must be untouched without its own permission',
        );
    }

    /**
     * With the robots permission the same post succeeds.
     */
    public function manageRobotsGrantsTheRobotsScreen(IntegrationTester $I): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $this->_actAs($I, [Permissions::ACCESS, Permissions::MANAGE_SETTINGS, Permissions::MANAGE_ROBOTS]);

        $I->postToAction(
            'simple-seo/settings/save-robots',
            ['siteUid' => $site->uid, 'robotsTxt' => "User-agent: *\nDisallow: /private"],
        );

        $I->assertSame(
            "User-agent: *\nDisallow: /private",
            Plugin::getInstance()->getRobots()->customForSite($site),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Logs in a non-admin user holding exactly the given permissions.
     *
     * @param string[] $permissions
     */
    private function _actAs(IntegrationTester $I, array $permissions): void
    {
        $user = new User([
            'username' => 'seo-tester',
            'email' => 'seo-tester@example.test',
            'admin' => false,
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($user), json_encode($user->getErrors()));

        // Assigned to the user rather than a group: group permissions round
        // trip through project config, which does not settle inside the
        // per-test transaction. Same resolution path either way — permissions
        // are the union of group and direct grants.
        //
        // accessCp rides along because Craft nests accessPlugin-* beneath it
        // on Pro, and drops a nested grant whose parent is missing. A real SEO
        // role needs it regardless — it is what lets them into the CP at all.
        Craft::$app->getUserPermissions()->saveUserPermissions(
            (int)$user->id,
            $permissions !== [] ? array_merge(['accessCp'], $permissions) : [],
        );

        Craft::$app->getUser()->setIdentity($user);
    }
}
