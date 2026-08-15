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
}
