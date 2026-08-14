<?php

namespace anvildev\simpleseo\controllers;

use anvildev\simpleseo\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * robots.txt, per site.
 *
 * Content comes from the Robots settings screen when an author has saved
 * any, and from the shipped default otherwise (open, with a sitemap
 * reference) — so this works with zero configuration. An environment with
 * `siteWideNoindex` on disallows everything regardless. A physical
 * web/robots.txt always wins over this route — the web server serves static
 * files before Craft routes; the settings screen warns when one exists.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class RobotsController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    // Public Methods
    // =========================================================================

    /**
     * Serves robots.txt for the current environment.
     *
     * @throws NotFoundHttpException when robots.txt is switched off for this site
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $robots = Plugin::getInstance()->getRobots();

        // The route is not registered when robots.txt is switched off, but the
        // /actions/ path bypasses URL rules entirely.
        if (!$robots->isEnabledForSite($site)) {
            throw new NotFoundHttpException();
        }

        $this->response->getHeaders()->set('Content-Type', 'text/plain; charset=UTF-8');

        return $this->asRaw($robots->contentForSite($site));
    }
}
