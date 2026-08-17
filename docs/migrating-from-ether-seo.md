# Migrating from Ether SEO

If your site runs [ether/seo](https://plugins.craftcms.com/seo) — the free "SEO" plugin with the unique field type, sitemap, and redirect manager — you're on a plugin with ~150 open issues and effectively no maintenance. Simple SEO is built as its maintained replacement, and the migration is one command.

## What you get

| Ether SEO | Simple SEO |
|---|---|
| SEO field (title, description) | SEO field — migrated **in place**, layouts untouched |
| Social title/image (Twitter/Facebook) | Social image (migrated), og/twitter tags rendered for you |
| Robots (all six switches) | Migrated — `noindex` and `nofollow` become the two toggles, `noarchive`, `nosnippet`, `notranslate`, and `noimageindex` carry over as extra directives; plus the [never-de-index-by-accident invariant](robots.md) |
| Canonical | Migrated; plus [UTF-8 encoding, param stripping, pagination](canonicals.md) |
| Sitemap | Zero-config sitemap with a [diagnosis view](sitemap.md) — never silently empty |
| Redirects | Exported as a **Retour-importable CSV** — redirects belong in [Retour](https://plugins.craftcms.com/retour) |
| Focus keywords / score | **Dropped, and counted in the report.** Content analysis is the most fragile part of every SEO plugin; we don't do it. |

## The migration

Works whether or not ether is still installed — or still *installable* on your Craft version. The migrator reads ether's data directly from the database.

**1. Install Simple SEO** (leave ether alone for now):

```bash
composer require anvildev/craft-simple-seo
php craft plugin/install simple-seo
```

**2. Dry run.** Reports everything the migration would do; writes nothing:

```bash
php craft simple-seo/migrate/ether
```

You'll see: the ether fields found, how many values would convert (per site), what maps (titles, descriptions, images, robots, extra directives, canonicals), how many focus-keyword sets would be dropped, how many redirects would export, and ether's settings for review.

**3. Back up, then apply:**

```bash
php craft db/backup
php craft simple-seo/migrate/ether --apply
```

Each ether SEO field is converted **in place** — same field ID, UID, and handle — so every field layout, template reference, and GraphQL query keeps working. Values are rewritten per site. Re-running is always safe: converted values are recognized and skipped.

**4. Redirects → Retour.** The CSV lands in `storage/simple-seo/ether-redirects.csv` (or wherever `--csv=` points), with columns `legacyUrlPattern, destinationUrl, matchType, httpCode, siteId`. Import it via Retour → Redirects → Import, mapping the columns in its UI. All rows export as exact matches — if you used regex patterns in ether, switch those rows to regex in Retour after import.

**5. Uninstall ether**, once you're satisfied:

```bash
php craft plugin/uninstall seo
composer remove ether/seo
```

## After migrating

- Open **Settings → Plugins → Simple SEO** and set per-site title formats deliberately — ether's title templates have no clean equivalent, so the migration surfaces its settings instead of guessing.
- Add `{{ craft.simpleSeo.renderMeta(entry) }}` to your layout head, replacing ether's `{{ craft.seo.custom(...) }}` / hook calls.
- Check `/sitemap.xml?explain` signed in with **Access Simple SEO** — it tells you exactly what's in your sitemap and why.
- If your ether install relied on focus keywords, that data is gone by design; the dry-run report told you how much there was.
