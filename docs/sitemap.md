# Sitemap

`/sitemap.xml` works with **zero configuration**: it indexes every section that has URLs on the current site, split into per-section files (`/sitemaps/section-<handle>.xml`, paginated at 1000 URLs per file).

## Serving it at all

**Simple SEO → Sitemap** carries a **Serve this site's sitemap** switch, per site. Turn it off and the plugin registers no sitemap routes for that site at all, so `/sitemap.xml` and `/sitemaps/…` fall through to normal Craft routing — your own template, or another plugin, can answer them. It is not a 404: the URL simply stops being ours.

Your section choices and priorities are kept while it is off, so turning it back on restores the configuration rather than starting over. Only disabled sites are stored, which means a site added later serves a sitemap without anyone opting it in.

One knock-on: the shipped default robots.txt stops advertising `Sitemap:` for a site whose sitemap is switched off, rather than pointing crawlers at a URL this plugin no longer answers.

## What's included

An entry appears in the sitemap when it is live, has a URL, and is **not marked noindex** — the field's noindex toggle is also the sitemap exclusion switch, exactly as its instructions say. Each entry appears exactly once, with `lastmod`. Entries that exist on multiple sites carry their full hreflang alternate set.

Sections can be toggled per site on the Sitemap settings screen (**Simple SEO → Sitemap** in the CP sidebar).

The screen lists every section with the number of URLs it currently contributes, linking to that section's file. Any section can be switched off — including singles, and sections that have no URLs on this site yet, so the decision is made once and survives until the section has content.

The counts are what actually ships: entries marked noindex are already excluded from them, so the number beside a section is the number of URLs in its file, not a count of entries. A section showing `0` either has no live entries with URLs on this site, isn't enabled for this site, or has every entry noindexed — `/sitemap.xml?explain` names which.

Choices are stored **inverted**, as exclusions rather than inclusions. That's why a section you create later joins the sitemap on its own instead of staying invisible until someone remembers to tick it — the failure mode that produces "my sitemap is empty" support threads for other plugins.

## Priority

Each section can carry a `<priority>`, set per site on the same screen. It is **empty by default and emits no element at all**.

That default is deliberate. Google's [sitemap documentation](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap) states plainly that it ignores `<priority>` and `<changefreq>`, and Bing [said the same in July 2025](https://blogs.bing.com/webmaster/July-2025/Keeping-Content-Discoverable-with-Sitemaps-in-AI-Powered-Search). Every other Craft SEO plugin defaults the field to `0.5`, so the usual result is every URL on the site carrying an identical value that nothing reads. Setting it here is opt-in: leave it alone and the tag never appears; set it and it ships on every URL in that section's file.

`<changefreq>` is not offered at all — same status, and unlike priority nobody asks for it by name.

## Files & pagination

| URL | Contents |
|---|---|
| `/sitemap.xml` | The index — one entry per section file, per site |
| `/sitemaps/section-<handle>.xml` | A section's first 1000 URLs |
| `/sitemaps/section-<handle>-p2.xml` … | Further pages of 1000 |

Each `<url>` carries `lastmod`; multi-site entries carry `xhtml:link` hreflang alternates (the full set, ordered by site — single-site entries carry none). No `changefreq`; `<priority>` only where you set it (above) — search engines ignore both, which is why neither is emitted by default.

## Caching & invalidation

Every file is cached (24h TTL) behind one tag, and rebuilt on the next request after any of: an entry save/delete/restore (drafts and revisions excluded), a section or entry-type change, or a plugin-settings save. There is nothing to warm and no command to run — cold rebuilds are fast by design.

## Never silently empty

The classic sitemap failure is silence: a section missing, a file empty, and nothing telling you why. Simple SEO refuses to be silent:

- An empty sitemap file always carries an XML comment stating the reason:
  `<!-- 0 URLs: all 3 live entries are noindexed -->`
- **`/sitemap.xml?explain`** (with **Access Simple SEO**; everyone else gets the normal XML) prints the full per-section diagnosis:

```
Simple SEO sitemap diagnosis — site: default

✗ fragments          0 URLs  section has no URLs for this site
✓ news            2063 URLs  OK
✓ blog               0 URLs  all 4 live entries are noindexed
✗ archive            0 URLs  excluded in the Simple SEO settings
```

If something's missing from your sitemap, `?explain` tells you exactly why in one request.
