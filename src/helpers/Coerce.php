<?php

namespace anvildev\simpleseo\helpers;

/**
 * Junk-tolerant scalar coercion — pure functions, no app state.
 *
 * Field values, body params, and stored settings all arrive in loose shapes.
 * The collapsing rules live here once, so the field, the MCP tools, and the
 * services cannot drift on what counts as "blank".
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class Coerce
{
    // Public Methods
    // =========================================================================

    /**
     * Trims a string value and collapses blanks: non-strings and
     * whitespace-only strings become null, everything else is trimmed.
     */
    public static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Reads an asset ID out of the shapes an element select posts and a
     * foreign plugin stores: a number, `[N]`, `{id: N}`, or `[{id: N}]`.
     * Anything else, including zero and blanks, becomes null.
     */
    public static function assetId(mixed $value): ?int
    {
        if (is_array($value)) {
            $first = $value[0] ?? null;
            $value = $value['id'] ?? (is_array($first) ? ($first['id'] ?? null) : $first);
        }

        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }
}
