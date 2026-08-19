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
| Site-wide robots (plugin settings) | **Warned about loudly; carried only if you ask.** Ether applies its settings-screen robots to every element that sets none of its own, so pages you never edited were being noindexed by it. `--carry-settings` writes those directives onto the entries that had them — see below. |
| Per-field defaults (title tokens, description, social image, robots) | **Not carried, and named per field in the report.** Simple SEO's defaults are per site, not per field. `hideSocial` is the exception: it maps onto the field's own controls and carries. |
| Sitemap config | **Reported; switched-off sections carried only if you ask.** Ether configures sitemaps globally; Simple SEO does it per site under Settings → Sitemap. `--carry-settings` excludes ether's switched-off sections on every site. Priorities are never imported: a section with no priority set emits no `<priority>` here, on purpose. |

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

## The site-wide robots warning

If the dry run prints a `WARNING — ether served ... SITE-WIDE` line, read it before you apply. Ether's settings screen has a robots control, and it applies to **every** element whose own robots is empty — so a site that looks clean entry-by-entry can be entirely noindexed by one setting. Sites have been de-indexed by exactly this ([ethercreative/seo#244](https://github.com/ethercreative/seo/issues/244)), which is why Simple SEO has no equivalent control and why the migration will not turn one on for you.

Decide deliberately:

- **Those pages really should keep their directives**: re-run with `--carry-settings`, which writes ether's directives onto each entry that was relying on them:

  ```bash
  php craft simple-seo/migrate/ether --apply --carry-settings
  ```

  Every one becomes a normal per-entry value — visible in the CP, editable, removable. Nothing is hidden in a setting. The flag also carries ether's switched-off sitemap sections across as per-site exclusions. Run it without `--apply` first to see the counts.
- **The whole environment should be hidden** (staging, a pre-launch site): set `siteWideNoindex` in `config/simple-seo.php`, env-gated. It is a real lockdown — `X-Robots-Tag` on every response, forced meta, and a robots.txt that disallows everything — and there is no CP control that can poke a hole in it.
- **It was never intended**: do nothing. Those pages are now indexable, which is what you wanted.

`--carry-settings` is never implied by `--apply`. Stamping `noindex` onto pages is not something a migration should decide for you, so it stays a separate, deliberate word on the command line.

## After migrating

- Open **Settings → Plugins → Simple SEO** and set per-site title formats deliberately — ether's title templates have no clean equivalent, so the migration surfaces its settings instead of guessing.
- Add `{{ craft.simpleSeo.renderMeta(entry) }}` to your layout head, replacing ether's `{{ craft.seo.custom(...) }}` / hook calls.
- Check `/sitemap.xml?explain` signed in with **Access Simple SEO** — it tells you exactly what's in your sitemap and why.
- If your ether install relied on focus keywords, that data is gone by design; the dry-run report told you how much there was.
