<?php

namespace anvildev\simpleseo\tests\unit;

use anvildev\simpleseo\mcp\ToolResponseTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies guard()'s information-disclosure control: a tool body that throws
 * never leaks its exception message to the MCP client. The response carries
 * only the fixed generic error plus the exception short name — the details
 * belong in the Craft logs, not on the wire.
 *
 * Runs without a Craft app: the facade bootstrap is enough, because with no
 * app Yii's logger only accumulates the warning instead of dispatching it.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ToolResponseTraitTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * A throwing tool body reduces to the generic error response, and no
     * fragment of the internal message survives anywhere in the encoded
     * response.
     */
    public function testGuardReducesThrowablesToAGenericResponse(): void
    {
        $consumer = new class {
            use ToolResponseTrait;

            /**
             * Runs a body that throws through the private guard().
             *
             * @return array<string, mixed>
             */
            public function run(): array
            {
                return $this->guard(static function(): array {
                    throw new RuntimeException('secret detail');
                });
            }
        };

        $response = $consumer->run();

        $this->assertSame(
            'An internal error occurred while running the tool; see the Craft logs for details.',
            $response['error'],
        );
        $this->assertSame('RuntimeException', $response['type']);
        $this->assertStringNotContainsString(
            'secret',
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * An anonymous exception class's name embeds the defining file's path
     * (`RuntimeException@anonymous /path/to/file.php:12$0`), and
     * ReflectionClass::getShortName() keeps all of it — so the type key must
     * be truncated, or the control leaks a server path after all.
     */
    public function testGuardTruncatesAnonymousClassNames(): void
    {
        $consumer = new class {
            use ToolResponseTrait;

            /**
             * Runs a body throwing an anonymous exception through guard().
             *
             * @return array<string, mixed>
             */
            public function run(): array
            {
                return $this->guard(static function(): array {
                    throw new class ('secret detail') extends RuntimeException {};
                });
            }
        };

        $response = $consumer->run();

        $this->assertSame('RuntimeException@anonymous', $response['type']);
        $this->assertStringNotContainsString(__FILE__, (string)json_encode($response));
    }
}
