# Settings Reference

Every setting the plugin has, where it lives, and why it lives there.

## Where settings live (the storage split)

| Storage | What | Why |
|---|---|---|
| **Project config** | Title formats, default descriptions, whether the sitemap and robots.txt are served at all, sitemap section toggles, per-section priority, robots.txt content, available sub-fields, canonical behavior flags | Portable structure — deploys with your project, keyed by site UID |
| **Database** (`simpleseo_sitesettings`) | Default social image references | Asset IDs are environment-specific; putting them in project config breaks `allowAdminChanges: false` production environments |
| **Config file only** (`config/simple-seo.php`) | `canonicalLinkHeader`, `canonicalAllowedQueryParams`, `siteWideNoindex` | Canonical header and query-param allowlist are deploy-time policy; `siteWideNoindex` has no CP control because a clickable site-wide noindex is how sites get de-indexed by accident |

With `allowAdminChanges: false`, the General screen renders project-config fields read-only (with an explanatory note) while the social image stays editable — content editing keeps working in production. The Sitemap and Robots screens are project config throughout, so they go fully read-only.

## The settings screens

Settings live in the plugin's own CP section — **Simple SEO** in the sidebar, with **General**, **Sitemap**, **Robots** and **Fields** subpages (also reachable via Settings → Plugins → Simple SEO). Access is permission-based, not admin-only — see [who can manage SEO settings](#who-can-manage-seo-settings).

General, Sitemap and Robots each edit one site at a time; on multi-site installs the site is chosen with the breadcrumb at the top of the screen, and each save applies to that site only. A 20-site install gets a dropdown, not 20 tabs. **Fields** is install-wide and has no site selector.

### General

| Setting | Default | Notes |
|---|---|---|
| **Title format** | `{title} - {siteName}` | Tokens `{title}`, `{siteName}`. Leave it empty for the default. A non-empty format **must** contain `{title}`, or every page on the site would share one title — that's rejected at save. `{title}` on its own omits the site name, and is honored rather than silently "corrected". The site name is never doubled when a title already contains it. |
| **Default meta description** | — | Used when an element has none of its own |
| **Default social image** | — | Used when an element has none of its own. Stored in the **database**, not project config, so it stays editable when `allowAdminChanges` is off — asset IDs differ per environment ([ethercreative/seo#243](https://github.com/ethercreative/seo/issues/243)) |

### Sitemap

| Setting | Default | Notes |
|---|---|---|
| **Serve this site’s sitemap** | on | Off unregisters the plugin’s sitemap routes for this site. Section choices and priorities are kept, so switching back on restores them. See [sitemap](sitemap.md) |
| **Sitemap sections** | all URL-having sections | Per-site checkboxes; unchecking all excludes everything. Stored inverted (as exclusions), so newly created sections are included automatically |
| **Priority** | — (no element emitted) | Per section, per site. Opt-in: both Google and Bing document that they ignore `<priority>`, so the default claims nothing rather than stamping `0.5` on every URL. See [sitemap](sitemap.md) |

### Robots

| Setting | Default | Notes |
|---|---|---|
| **Serve this site’s robots.txt** | on | Off unregisters `/robots.txt` for this site. Saved content is kept. `siteWideNoindex` still serves a full disallow — a lockdown that a toggle can punch a hole in is not a lockdown. See [robots](robots.md) |
| **robots.txt** | open, with a `Sitemap:` line | Per-site content. Leave it empty and the shipped default is served — the field shows that default as placeholder text so you can see what you'd be replacing. `{sitemapUrl}` expands to this site's sitemap index. |

Content is served **exactly as written** and is never evaluated as a template: a settings textarea that renders Twig is a code-execution surface, which is why the token is plain string substitution rather than `{{ }}`. Three things override or shadow it, and the screen warns about each: `siteWideNoindex` forces a full disallow, a physical `web/robots.txt` is served by the web server before Craft routes ever run, and content that blocks every crawler from the whole site gets a prominent notice. Full detail: [robots](robots.md).

### Fields

| Setting | Default | Notes |
|---|---|---|
| **Controls available in SEO fields** | all seven | Install-wide (not per-site). Caps what any SEO field may offer; each field then picks from it. Empty means "not configured" and offers everything, so the list can't be emptied into a blank field by accident. Detail: [the field](the-field.md) |

## Who can manage SEO settings

The settings screens are **not admin-only**. Three permissions, under Settings → Users → (group) → Permissions:

| Permission | Grants |
|---|---|
| **Access Simple SEO** (`accessPlugin-simple-seo`, Craft's own, nested under *Access the control panel*) | Sees the Simple SEO nav section and can open all four screens **read-only** |
| **Manage SEO settings** (`simple-seo:manage-settings`) | Saves General, Sitemap and Fields |
| **Edit robots.txt** (`simple-seo:manage-robots`, nested under the above) | Saves robots.txt as well |

robots.txt is deliberately separate: it is the one screen where a wrong value stops search engines crawling the site at all, so granting day-to-day SEO work does not hand over that switch.

A typical **SEO Manager** group therefore needs: *Access the control panel* → *Access Simple SEO* → *Manage SEO settings*. Add *Edit robots.txt* only for people who should own crawling.

Two things worth knowing:

- **`allowAdminChanges` still wins.** Title formats, descriptions, sitemap settings and robots.txt live in project config, which is frozen when `allowAdminChanges` is `false` — for everyone, permission or not. The default social image is stored in the database, so it stays editable there.
- **Editing SEO *content* needs none of this.** The SEO field on an entry is governed by ordinary entry permissions. These three only govern the site-wide settings screens.

## `config/simple-seo.php`

```php
<?php

use craft\helpers\App;

return [
    // Emit the Link: <…>; rel="canonical" header (always identical to the tag).
    // Default: true.
    'canonicalLinkHeader' => true,

    // Query params kept on element-derived canonical URLs. Default: [] (all
    // stripped). Craft's pathParam and query-style pagination params are
    // always implicitly allowed.
    'canonicalAllowedQueryParams' => [],

    // THE staging lockdown. Default: false — and with the default, the plugin
    // CANNOT emit a site-wide noindex (test-enforced). True forces
    // noindex,nofollow meta + X-Robots-Tag on every front-end response,
    // disallows everything in robots.txt, and shows a persistent CP banner.
    'siteWideNoindex' => App::env('CRAFT_ENVIRONMENT') !== 'production',
];
```

Config-file values override project config per standard Craft behavior. Multi-environment configs work the usual way (`'*'`, `'staging'`, … keys).

## What deliberately has no setting

- Robots defaults in the CP — see [robots](robots.md).
- Sitemap change-frequency — deprecated noise search engines ignore. (`<priority>` is offered, but opt-in and empty by default — see above.)
- Toggles for individual meta tags — the [override system](twig-reference.md) covers per-template needs without a settings maze.
