# Simple SEO

Simple SEO fields for Craft CMS — meta title, description, social image, canonical, robots, and an XML sitemap. Nothing else.

The maintained, lightweight alternative for small Craft sites, with a [one-command migration from ether/seo](docs/migrating-from-ether-seo.md).

**[Documentation →](docs/README.md)**

## Install

```bash
composer require anvildev/craft-simple-seo
php craft plugin/install simple-seo
```

Then add an **SEO** field to the entry types you want editors to control, and render the tags with the one-liner below. `/sitemap.xml` and `/robots.txt` work with zero configuration.

## Scope charter

Simple SEO stays small on purpose — that's the product, the price, and the support model. Feature requests outside the charter are closed with a pointer, not implemented.

**In scope:**

- SEO field type: meta title, description, social image, per-entry robots (noindex/nofollow plus the full directive set), canonical override
- Live SERP + social preview (fully client-side)
- One-line Twig meta rendering (title, description, canonical, robots, Open Graph, Twitter)
- Canonical URLs (link tag + optional header, always in agreement)
- Robots handling that can never de-index a site by accident, plus a per-site, CP-editable `robots.txt`
- Permission-based access, so a non-admin SEO role can own the settings (robots.txt gated separately)
- XML sitemap: multi-site, cached, never silently empty
- GraphQL read access to field values and resolved meta

**Out of scope, permanently:**

- **Redirects** → use [Retour](https://plugins.craftcms.com/retour). The Ether SEO migration exports redirect data as a Retour-importable CSV.
- **Content analysis / focus keywords** → intentionally absent; it's the most fragile, support-heavy part of every SEO plugin.
- **Structured data / schema builder, llms.txt, GEO** → use [Beacon](https://plugins.craftcms.com/beacon).

## Usage

Add the SEO field to your entry types, then render everything with one line in your layout's `<head>`:

```twig
{{ craft.simpleSeo.renderMeta(entry) }}
```

That outputs the `<title>`, meta description, canonical, robots (only when set — absent means index,follow), Open Graph, and Twitter tags, with the full fallback chain applied (field value → per-site default → entry title). Per-template overrides:

```twig
{{ craft.simpleSeo.renderMeta(entry, { ogType: 'article' }) }}
```

Headless? The same resolved data as an array:

```twig
{% set meta = craft.simpleSeo.resolveMeta(entry) %}
```

Or over GraphQL — `simpleSeo` on any entry/category is the fully resolved meta, and the field itself supports sub-selections:

```graphql
{
  entry(uri: "about") {
    simpleSeo { title description canonical robots ogImageUrl twitterCard }
    seo { title noindex socialImageUrl }   # raw field value
  }
}
```

The `/sitemap.xml` and `/robots.txt` routes are controller-backed (no templates involved), so they keep working in `headlessMode`.

## Configuration

Create `config/simple-seo.php` to override config-level settings:

```php
return [
    // Emit the Link: <…>; rel="canonical" header (always identical to the tag).
    'canonicalLinkHeader' => true,

    // Query params kept on element-derived canonical URLs (default: all stripped).
    'canonicalAllowedQueryParams' => ['category'],

    // Hide a whole environment from search engines (staging). Config-file only,
    // deliberately no CP control. Forces noindex/nofollow meta + X-Robots-Tag on
    // every front-end response, disallows everything in robots.txt, and shows a
    // persistent CP warning banner while active.
    'siteWideNoindex' => App::env('CRAFT_ENVIRONMENT') !== 'production',
];
```

With `siteWideNoindex` at its default (`false`), the plugin **cannot** emit a site-wide noindex — no CP setting, save, or template call can cause it. This invariant is enforced by tests.

The plugin also serves an environment-aware `/robots.txt` (a physical `web/robots.txt` always wins over the route).

## Sitemap

`/sitemap.xml` works with zero configuration: every section with URLs is included, entries marked noindex are excluded, multi-site entries carry hreflang alternates, and files are cached until an entry or section changes. Per-site section toggles and an optional per-section `<priority>` live on the Sitemap settings screen.

When something's missing, nothing is ever silently empty: empty sitemap files carry an XML comment explaining why, and anyone with **Access Simple SEO** can open `/sitemap.xml?explain` for the full per-section diagnosis.

Canonical behavior: UTF-8 slugs are always percent-encoded, paginated pages canonicalize to themselves, and author-entered canonical overrides keep their query params verbatim.

## Migrating from Ether SEO

```bash
php craft simple-seo/migrate/ether           # dry run — reports everything, writes nothing
php craft simple-seo/migrate/ether --apply   # migrate for real
```

The migration converts every ether SEO field **in place** (same field ID/UID/handle — your field layouts keep working untouched), mapping titles, descriptions, social images, robots, and canonicals per site. Ether's redirects are exported as a Retour-importable CSV (`--csv=path` to choose where). Focus keywords are dropped — Simple SEO has no content analysis on purpose — and the report says exactly how many. Re-running is always safe: already-migrated values are recognized and skipped.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Development

```bash
composer install
composer check              # ECS + PHPStan + unit tests
composer test:integration   # Codeception integration suite (needs a test DB — see tests/.env)
```
