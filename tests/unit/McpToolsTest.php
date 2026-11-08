<?php

namespace anvildev\simpleseo\tests\unit;

use anvildev\simpleseo\mcp\SeoTools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the MCP integration surface: the tool class declares exactly
 * the expected `#[McpTool]` set, reads are marked read-only, every write
 * is flagged dangerous, and the plugin registers the class behind
 * class_exists() guards for both Craft's server and stimmt/craft-mcp.
 *
 * Pure source scanning — the suite passes whether or not craft-mcp, the
 * MCP SDK, or Craft 5.12 is installed. Attribute arguments are never read
 * through `ReflectionAttribute::getArguments()`: a `new ToolAnnotations()`
 * argument would autoload the SDK, and `McpToolMeta` would autoload the
 * absent ToolCategory enum.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class McpToolsTest extends TestCase
{
    /**
     * @var list<string> Every tool the class must declare, exactly.
     */
    private const EXPECTED_TOOLS = [
        'simple_seo_doctor',
        'simple_seo_audit_meta',
        'simple_seo_explain_sitemap',
        'simple_seo_resolve_meta',
        'simple_seo_set_entry_meta',
        'simple_seo_set_entry_noindex',
    ];

    /**
     * @var list<string> The write tools — each must carry `dangerous: true`.
     */
    private const WRITE_TOOLS = [
        'setEntryMeta',
        'setEntryNoindex',
    ];

    // Public Methods
    // =========================================================================

    /**
     * The declared tool set is exactly the expected one — no tool appears
     * or disappears unnoticed.
     */
    public function testDeclaredToolsMatchExactly(): void
    {
        preg_match_all("/name: '([^']+)'/", $this->toolSource(), $matches);

        $declared = $matches[1];
        sort($declared);
        $expected = self::EXPECTED_TOOLS;
        sort($expected);

        $this->assertSame($expected, $declared);
        $this->assertSame(
            count(self::EXPECTED_TOOLS),
            substr_count($this->toolSource(), '#[RequiresPermission(Permissions::ACCESS)]'),
            'Every tool must require the plugin access permission.',
        );
    }

    /**
     * Every write tool is flagged dangerous, asserted on the source text —
     * reading McpToolMeta's arguments reflectively would autoload the
     * absent ToolCategory enum and fatal.
     */
    public function testWriteToolsAreFlaggedDangerous(): void
    {
        $source = $this->toolSource();

        foreach (self::WRITE_TOOLS as $method) {
            $preceding = $this->preceding($source, $method);
            $this->assertStringContainsString(
                'dangerous: true',
                $preceding,
                "$method() must carry McpToolMeta(dangerous: true).",
            );
            $this->assertStringContainsString(
                'destructiveHint: true',
                $preceding,
                "$method() must be marked destructive for Craft's MCP server.",
            );
            $this->assertStringNotContainsString(
                'readOnlyHint: true',
                $preceding,
                "$method() must not be read-only, or Craft would expose it to read tokens.",
            );
        }
    }

    /**
     * Read tools opt into Craft's read scope. Without the hint, Craft treats
     * a tool as a write and hides it from read-only tokens.
     */
    public function testReadToolsAreMarkedReadOnly(): void
    {
        $source = $this->toolSource();
        $reads = ['doctor', 'auditMeta', 'explainSitemap', 'resolveMeta'];

        foreach ($reads as $method) {
            $this->assertStringContainsString(
                'readOnlyHint: true',
                $this->preceding($source, $method),
                "$method() must set readOnlyHint so a read-only Craft token can call it.",
            );
        }
    }

    /**
     * Registration is guarded: the plugin only touches craft-mcp classes
     * after class_exists(), so an install without the package never loads
     * them.
     */
    public function testRegistrationIsClassExistsGuarded(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/Plugin.php',
        );

        $this->assertStringContainsString('class_exists(\\stimmt\\craft\\Mcp\\Mcp::class)', $source);
        $this->assertStringContainsString('EVENT_REGISTER_TOOLS', $source);
        $this->assertStringContainsString('class_exists(\\craft\\services\\Mcp::class)', $source);
        $this->assertStringContainsString('EVENT_REGISTER_CAPABILITY_TYPES', $source);
    }

    /**
     * The tool class source. Attribute arguments stay unread — see the class doc.
     */
    private function toolSource(): string
    {
        return (string)file_get_contents(
            (string)(new ReflectionClass(SeoTools::class))->getFileName(),
        );
    }

    /**
     * The attribute block immediately above a tool method.
     */
    private function preceding(string $source, string $method): string
    {
        $position = strpos($source, "public function $method(");
        if ($position === false) {
            $this->fail("$method() should exist.");
        }

        return substr($source, max(0, $position - 900), 900);
    }
}
