<?php

namespace anvildev\simpleseo\controllers;

use anvildev\simpleseo\Permissions;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the sitemap index and the per-section files.
 *
 * `/sitemap.xml?explain` returns the plain-text per-section diagnosis instead
 * of XML, for anyone with Access Simple SEO; everyone else gets the normal
 * XML. This
 * is the "never silently empty" debug view.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SitemapController extends Controller
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
     * The sitemap index for the current site.
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $sitemap = Plugin::getInstance()->getSitemap();

        // The route is not registered when the sitemap is switched off, but
        // the /actions/ path bypasses URL rules entirely.
        if (!$sitemap->isEnabledForSite($site)) {
            throw new NotFoundHttpException();
        }

        // Anyone who can reach the plugin's CP section can diagnose its sitemap.
        if ($this->request->getQueryParam('explain') !== null && Craft::$app->getUser()->checkPermission(Permissions::ACCESS)) {
            $lines = ['Simple SEO sitemap diagnosis — site: ' . $site->handle, ''];
            foreach ($sitemap->explain($site) as $row) {
                $lines[] = sprintf(
                    '%s %-24s %5d URLs  %s',
                    $row['included'] ? '✓' : '✗',
                    $row['section'],
                    $row['urls'],
                    $row['reason'],
                );
            }

            return $this->_raw(implode("\n", $lines) . "\n", 'text/plain');
        }

        return $this->_raw($sitemap->getIndexXml($site), 'application/xml');
    }

    /**
     * One section's sitemap page. Out-of-range pages serve an empty urlset
     * with a reason comment rather than 404ing — never silently empty.
     *
     * @throws NotFoundHttpException for unknown or excluded sections, or
     * when the sitemap is switched off for this site
     */
    public function actionSection(string $sectionHandle, int $page = 1): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $sitemap = Plugin::getInstance()->getSitemap();

        if (!$sitemap->isEnabledForSite($site)) {
            throw new NotFoundHttpException();
        }

        $xml = $sitemap->getSectionXml($site, $sectionHandle, $page);

        if ($xml === null) {
            throw new NotFoundHttpException();
        }

        return $this->_raw($xml, 'application/xml');
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a raw response with the given content type (UTF-8 assumed).
     */
    private function _raw(string $data, string $contentType): Response
    {
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', "$contentType; charset=UTF-8");
        $response->data = $data;

        return $response;
    }
}
