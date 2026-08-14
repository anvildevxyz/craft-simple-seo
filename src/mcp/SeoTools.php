<?php

namespace anvildev\simpleseo\mcp;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\helpers\Coerce;
use anvildev\simpleseo\helpers\Lookup;
use anvildev\simpleseo\helpers\SeoFieldReader;
use anvildev\simpleseo\models\Finding;
use anvildev\simpleseo\Plugin;
use Craft;
use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools exposing Simple SEO's diagnostics and meta resolution.
 *
 * The reads mirror the console commands (`doctor`, `audit/meta`,
 * `sitemap/explain`, `meta/show`) — targets resolve through the shared
 * {@see Lookup} helper and payloads come from the shared model builders, so
 * an agent sees exactly the facts a pipeline does. The two writes edit one
 * entry's SEO field value through the normal element save, so validation
 * and events all apply; the noindex switch is flagged dangerous because it
 * removes the page from search results and the sitemap.
 *
 * The attributes are read reflectively by craft-mcp and never instantiated
 * here, so the package stays a soft dependency.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoTools
{
    // Traits
    // =========================================================================

    use ToolResponseTrait;

    // Public Methods
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_doctor',
        description: 'Check the install for configuration that would damage its search visibility (site-wide noindex, blocking robots.txt, empty sitemap, shadowed robots.txt, broken title format). Levels: problem, note, ok — only problems are wrong.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function doctor(): array
    {
        return $this->guard(static fn(): array => Finding::listPayload(
            Plugin::getInstance()->getDiagnostics()->run(),
        ));
    }

    /**
     * @param string|null $siteHandle Site to audit; defaults to the primary site.
     * @param string|null $sectionHandle Limit the audit to one section.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_audit_meta',
        description: 'List live pages whose meta is missing, duplicated, or over the soft length limits, measured on the resolved values that ship. Facts only, no score. Examines every live entry — can be slow on large sites; pass sectionHandle to narrow it.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function auditMeta(?string $siteHandle = null, ?string $sectionHandle = null): array
    {
        return $this->guard(static function() use ($siteHandle, $sectionHandle): array {
            $site = Lookup::site($siteHandle);
            if (is_string($site)) {
                return ['error' => $site];
            }

            if ($sectionHandle !== null) {
                $section = Lookup::section($sectionHandle);
                if (is_string($section)) {
                    return ['error' => $section];
                }
            }

            return Plugin::getInstance()->getAudit()
                ->run($site, $sectionHandle)
                ->toPayload($site->handle, $sectionHandle);
        });
    }

    /**
     * @param string|null $siteHandle Site to explain; defaults to the primary site.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_explain_sitemap',
        description: 'Explain the XML sitemap per section: whether each section is included, how many URLs it contributes, and why a section contributes none. The same diagnosis as /sitemap.xml?explain.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function explainSitemap(?string $siteHandle = null): array
    {
        return $this->guard(static function() use ($siteHandle): array {
            $site = Lookup::site($siteHandle);
            if (is_string($site)) {
                return ['error' => $site];
            }

            return [
                'site' => $site->handle,
                'sections' => Plugin::getInstance()->getSitemap()->explain($site),
            ];
        });
    }

    /**
     * @param int $entryId The entry to resolve.
     * @param string|null $siteHandle Site to resolve for; defaults to the entry's own site.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_resolve_meta',
        description: 'The fully resolved meta for one entry — every fallback applied, the same values the front end and GraphQL render. Each value names its source (field, site-default, entry-title, element-url, none), so "why is this page\'s description that" is answerable directly.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function resolveMeta(int $entryId, ?string $siteHandle = null): array
    {
        return $this->guard(function() use ($entryId, $siteHandle): array {
            $entry = Lookup::entry($entryId, $siteHandle);
            if (is_string($entry)) {
                return ['error' => $entry];
            }

            return $this->_resolvedPayload($entry);
        });
    }

    /**
     * @param int $entryId The entry to edit.
     * @param string|null $title New meta title; empty string clears it, null leaves it unchanged.
     * @param string|null $description New meta description; empty string clears it, null leaves it unchanged.
     * @param string|null $siteHandle Site to edit; defaults to the entry's own site.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_set_entry_meta',
        description: 'Set the meta title and/or description on one entry\'s SEO field. Pass an empty string to clear a value back to its fallback; omit to leave it unchanged. Saves through the normal element save, so validation applies. Returns the re-resolved meta.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setEntryMeta(int $entryId, ?string $title = null, ?string $description = null, ?string $siteHandle = null): array
    {
        return $this->_updateSeoValue($entryId, $siteHandle, static function(SeoData $value) use ($title, $description): void {
            if ($title !== null) {
                $value->title = Coerce::stringOrNull($title);
            }
            if ($description !== null) {
                $value->description = Coerce::stringOrNull($description);
            }
        });
    }

    /**
     * @param int $entryId The entry to edit.
     * @param bool $noindex Whether search engines should be asked to skip the page.
     * @param string|null $siteHandle Site to edit; defaults to the entry's own site.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'simple_seo_set_entry_noindex',
        description: 'Switch noindex on or off for one entry. On, the page asks search engines not to list it AND drops out of the sitemap — this removes the page from search results. Returns the re-resolved meta.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setEntryNoindex(int $entryId, bool $noindex, ?string $siteHandle = null): array
    {
        return $this->_updateSeoValue($entryId, $siteHandle, static function(SeoData $value) use ($noindex): void {
            $value->noindex = $noindex;
        });
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads, mutates, and saves one entry's SEO field value inside the
     * shared error guard, returning the re-resolved meta so the caller sees
     * the actual effect.
     *
     * @param \Closure(SeoData): void $mutate
     * @return array<string, mixed>
     */
    private function _updateSeoValue(int $entryId, ?string $siteHandle, \Closure $mutate): array
    {
        return $this->guard(function() use ($entryId, $siteHandle, $mutate): array {
            $entry = Lookup::entry($entryId, $siteHandle);
            if (is_string($entry)) {
                return ['error' => $entry];
            }

            $handle = SeoFieldReader::field($entry)?->handle;
            if ($handle === null) {
                return ['error' => 'This entry\'s field layout carries no SEO field.'];
            }

            $value = $entry->getFieldValue($handle);
            if (!$value instanceof SeoData) {
                $value = new SeoData();
            }
            $mutate($value);
            $entry->setFieldValue($handle, $value);

            if (!Craft::$app->getElements()->saveElement($entry)) {
                return ['error' => 'The entry did not validate.', 'errors' => $entry->getErrors()];
            }

            return ['saved' => true] + $this->_resolvedPayload($entry);
        });
    }

    /**
     * The shared "entry + meta + sources" response block, so every tool
     * reports the resolution identically.
     *
     * @return array<string, mixed>
     */
    private function _resolvedPayload(Entry $entry): array
    {
        $resolved = Plugin::getInstance()->getMeta()->resolve($entry);

        return [
            'entry' => [
                'id' => (int)$entry->id,
                'title' => (string)$entry->title,
                'site' => $entry->getSite()->handle,
                'url' => $entry->getUrl(),
            ],
            'meta' => $resolved->toArray(),
            'sources' => $resolved->sources,
        ];
    }
}
