<?php

namespace anvildev\simpleseo\tests\unit;

use anvildev\simpleseo\mcp\SeoTools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies the craft-mcp integration surface: the tool class declares
 * exactly the expected `#[McpTool]` set, every write is flagged dangerous,
 * and the plugin registers it behind a class_exists() guard.
 *
 * Pure reflection and source scanning — the suite passes whether or not
 * craft-mcp is installed. Two gotchas shape the approach: attributes are
 * matched by NAME (never instantiated, the attribute classes may not
 * exist), and `McpToolMeta` arguments are never read through
 * `ReflectionAttribute::getArguments()`, because that would autoload the
 * absent ToolCategory enum and fatal. The dangerous flag is asserted by
 * scanning the source instead.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class McpToolsTest extends TestCase
{
    private const MCP_TOOL_ATTRIBUTE = 'Mcp\\Capability\\Attribute\\McpTool';

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
        $declared = [];
        foreach ((new ReflectionClass(SeoTools::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(self::MCP_TOOL_ATTRIBUTE) as $attribute) {
                // McpTool carries only scalar arguments, so reading them
                // does not autoload anything from the absent package.
                $declared[] = $attribute->getArguments()['name'];
            }
        }

        sort($declared);
        $expected = self::EXPECTED_TOOLS;
        sort($expected);

        $this->assertSame($expected, $declared);
    }

    /**
     * Every write tool is flagged dangerous, asserted on the source text —
     * reading McpToolMeta's arguments reflectively would autoload the
     * absent ToolCategory enum and fatal.
     */
    public function testWriteToolsAreFlaggedDangerous(): void
    {
        $source = (string)file_get_contents(
            (string)(new ReflectionClass(SeoTools::class))->getFileName(),
        );

        foreach (self::WRITE_TOOLS as $method) {
            $position = strpos($source, "public function $method(");
            $this->assertNotFalse($position, "$method() should exist.");

            $preceding = substr($source, max(0, $position - 800), 800);
            $this->assertStringContainsString(
                'dangerous: true',
                $preceding,
                "$method() must carry McpToolMeta(dangerous: true).",
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
    }
}
