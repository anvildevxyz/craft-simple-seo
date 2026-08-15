<?php

namespace anvildev\simpleseo\tests\unit\helpers;

use anvildev\simpleseo\helpers\Coerce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The field, the MCP tools, and the services all collapse loose input through
 * this one helper, so "blank becomes null" is defined exactly once. These
 * cases pin the collapsing rules the three former implementations agreed on.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CoerceTest extends TestCase
{
    // Public Methods
    // =========================================================================

    #[DataProvider('stringOrNullProvider')]
    public function testStringOrNull(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, Coerce::stringOrNull($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function stringOrNullProvider(): array
    {
        return [
            'null becomes null' => [null, null],
            // Non-strings are junk, not values: an int is never silently
            // stringified into a meta title.
            'int becomes null' => [7, null],
            'float becomes null' => [1.5, null],
            'bool becomes null' => [true, null],
            'array becomes null' => [['a'], null],
            'empty string becomes null' => ['', null],
            'whitespace-only becomes null' => ["  \t\n  ", null],
            'plain value passes through' => ['About us', 'About us'],
            'value is trimmed' => ["  About us \n", 'About us'],
            'inner whitespace is kept' => ['  A  B  ', 'A  B'],
            'zero string is a value' => ['0', '0'],
        ];
    }
}
