# Getting Started

## Install

```bash
composer require anvildev/craft-simple-seo
php craft plugin/install simple-seo
```

## Add the SEO field

Create a field of type **SEO** (Settings → Fields → New field) and add it to the field layouts of the entry types (or categories) you want SEO control on. The field gives editors:

- **Meta title** — overrides the entry title in search results (soft 60-character counter)
- **Meta description** — soft 160-character counter
- **Social image** — shown when the page is shared
- **noindex / nofollow** — per-entry robots toggles on the field's **Robots** tab; noindex also removes the entry from the sitemap
- **Additional robots directives** — `nosnippet`, `noarchive`, and the rest, each as its own switch; the field settings choose which ones it offers
- **Canonical URL override** — validated as a full URL at save

A live search + social preview sits at the top of the field, updating as editors type. It's rendered entirely from data on the page — there is no request that could fail. Which of those seven controls an editor sees is chosen twice: the install-wide **Fields** screen caps what any SEO field may offer, and each field then picks from that list. Hiding a control never erases saved values.

Entries that existed before you added the field are fine: they render sensible defaults everywhere.

## Render the meta

One line in your layout's `<head>`:

```twig
{{ craft.simpleSeo.renderMeta(entry) }}
```

That outputs the `<title>`, meta description, canonical (tag + header), robots (only when set), Open Graph, and Twitter tags with the full fallback chain applied: field value → per-site default → entry title.

Per-template overrides when a section warrants them:

```twig
{{ craft.simpleSeo.renderMeta(entry, { ogType: 'article' }) }}
```

Available override keys: `title`, `description`, `canonical`, `robots`, `ogType`, `ogSiteName`, `ogImage`, `twitterCard`. A typo'd key throws — nothing silently does nothing. An explicit `null` clears a value (no tag rendered; `ogType`/`ogSiteName`/`twitterCard` reset to their defaults) — except `title`, which treats `null` as absent because a page always has a title.

That's it. `/sitemap.xml` and `/robots.txt` already work with zero configuration.
