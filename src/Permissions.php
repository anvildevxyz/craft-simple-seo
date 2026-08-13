<?php

namespace anvildev\simpleseo;

/**
 * The plugin's user-permission handles.
 *
 * Constants rather than string literals on purpose: a mistyped handle is
 * invisible in testing, because admins bypass every permission check and
 * non-admins are denied a permission that was never registered — so it looks
 * like it works, and it looks like it correctly denies.
 *
 * Access itself is Craft's own `accessPlugin-simple-seo`, registered
 * automatically for any plugin with a CP section and checked before
 * getCpNavItem() ever runs. These two sit on top of it and govern *writing*:
 *
 * - access only          → the settings screens, read-only
 * - MANAGE_SETTINGS      → save General, Sitemap and Fields
 * - MANAGE_ROBOTS        → save robots.txt as well
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class Permissions
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Craft's automatic per-plugin access permission. Required to
     * see the CP section at all, and the read-only floor for the screens.
     */
    public const ACCESS = 'accessPlugin-simple-seo';

    /**
     * @var string Save the General, Sitemap and Fields screens.
     */
    public const MANAGE_SETTINGS = 'simple-seo:manage-settings';

    /**
     * @var string Save robots.txt. Deliberately separate from
     * MANAGE_SETTINGS: it is the one screen where a wrong value stops search
     * engines crawling the whole site.
     */
    public const MANAGE_ROBOTS = 'simple-seo:manage-robots';
}
