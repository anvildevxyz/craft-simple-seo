<?php

namespace anvildev\simpleseo\mcp;

use Craft;
use Throwable;

/**
 * Shared error handling for Simple SEO's MCP tools.
 *
 * The trait references nothing from the craft-mcp package, so a tool class
 * stays loadable (and unit-testable) even when that plugin is not installed.
 * Exceptions never reach the MCP client verbatim — internal errors may embed
 * SQL, schema, or paths, so they are reduced to a generic message and the
 * details stay in the Craft logs.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
trait ToolResponseTrait
{
    // Private Methods
    // =========================================================================

    /**
     * Runs a tool body, translating exceptions into an error response.
     *
     * @param \Closure(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function guard(\Closure $fn): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Craft::warning('Simple SEO MCP tool failed: ' . $e->getMessage(), __METHOD__);

            return [
                'error' => 'An internal error occurred while running the tool; see the Craft logs for details.',
                'type' => (new \ReflectionClass($e))->getShortName(),
            ];
        }
    }
}
