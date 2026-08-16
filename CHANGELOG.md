# Release Notes for Simple SEO

## 1.0.2 - 2026-08-16

Fixes for the ether/seo migration, found by running it against a real ether install. No schema change — updating is a straight `composer update`. If you have already migrated from ether/seo, re-run the migration after updating.

### Fixed

- The ether migration no longer loses social images. Ether stores the asset under `social.<network>.imageId` and renames a legacy `image` key to it only when it loads a value, so the stored document carries either. Reading `image` alone dropped every social image while reporting a clean conversion. **If you already migrated, re-run `craft simple-seo/migrate/ether --apply` after updating: the run is idempotent, and it now picks up the images it skipped.** Found by migrating a real ether/seo 5.0.0 install
- An all-blank `titleRaw` array now falls back to ether's flat `title` key, the way a blank `titleRaw` string already did
- Robots directives stored as one string, rather than a list, are read correctly. Reading only the list shape turned a hidden page back into an indexable one
- Applying the migration now drops the sitemap cache. Content rows are rewritten with direct SQL, so no element-save event fires, and cached sitemap files kept listing entries the migration had just marked noindex
- Per-network social titles, descriptions, and a second differing image are counted in the report. Simple SEO renders one set for every network, and dropped data is never silent

## 1.0.1 - 2026-08-16

Correctness fixes found by an adversarial review of the 1.0.0 code. No new features, no schema change — updating is a straight `composer update`.

### Fixed

- hreflang alternates no longer advertise noindexed translations. An entry live on several sites correctly dropped out of its own site's sitemap when marked noindex, but every sibling site still linked to it as an `<xhtml:link rel="alternate">` — the same index signal through a side door ([#4](https://github.com/anvildevxyz/craft-simple-seo/issues/4))
- Canonical query strings keep their exact shape. Filtering ran through PHP's `parse_str()`, which renamed dotted params (`utm.id` became `utm_id`, so a dotted `canonicalAllowedQueryParams` entry could never match its own param), collapsed repeated params to the last value, and stamped `=` onto valueless ones ([#5](https://github.com/anvildevxyz/craft-simple-seo/issues/5))
- Protocol-relative canonical overrides (`//cdn.example.com/page`) keep their leading `//` instead of degrading into a relative path that crawlers resolve against the current page ([#8](https://github.com/anvildevxyz/craft-simple-seo/issues/8))
- The pagination suffix now applies only to the element the request actually resolved to. Rendering meta for a different element on `/blog/p2` — a featured entry, a GraphQL list item — gave it a page-two URL it does not have ([#7](https://github.com/anvildevxyz/craft-simple-seo/issues/7))
- `craft simple-seo/doctor --json --quiet` reports problems only. The JSON branch returned before the quiet filter ran, so a pipeline gating on `.findings | length` fired on every healthy run ([#9](https://github.com/anvildevxyz/craft-simple-seo/issues/9))
- The MCP tools' error responses can no longer carry a server path: an anonymous exception class embeds its defining file in its own name, which the reported exception type passed straight through

### Changed

- **An explicit `null` override now clears its value.** `{ description: null }` and `{ canonical: null }` were silently ignored while `{ robots: null }` and `{ ogImage: null }` cleared their tags — one overrides array, two opposite meanings for the same input. Every key now clears on `null`: description, canonical (tag, `og:url`, and the `Link` header), robots, and the social image render nothing at all, and `ogType`, `ogSiteName`, and `twitterCard` fall back to their defaults. `title` is the one deliberate exception — a page always has a title, so a `null` title runs the normal fallback chain ([#6](https://github.com/anvildevxyz/craft-simple-seo/issues/6))

### Internal

- Consolidation with no behavior change: one shared blank-to-null helper behind the field, the MCP tools, and the services; raw responses through Craft's own `asRaw()`; shared PHPStan type aliases; the last model-to-service dependency cycle removed
- MCP failure logs now carry the exception class, file, line, and stack trace. The response the client receives is unchanged — the details belong in the Craft logs, not on the wire
- Test suite grown to 74 unit and 89 integration tests, including a regression case for every fix above

## 1.0.0 - 2026-08-13

First release. Simple SEO does the SEO work every Craft site needs and deliberately nothing else.

### The SEO field

- Meta title, meta description, social image, canonical override, and per-entry robots, stored as a single JSON value ([#2](https://github.com/anvildevxyz/craft-simple-seo/issues/2))
- A live SERP and social-share preview, rendered entirely client-side from data embedded at render time. It makes no requests, so it cannot fail to load ([#3](https://github.com/anvildevxyz/craft-simple-seo/issues/3))
- Soft-limit character counters that announce limit crossings to screen readers via a polite live region — crossings only, never every keystroke ([#13](https://github.com/anvildevxyz/craft-simple-seo/issues/13)). The title counter measures the full formatted title — site title format applied, suffix included — because that is the string results truncate, not the raw input
- Per-entry robots on the field's own **Robots** tab beside the previews: noindex and nofollow switches, plus every extra directive (`noarchive`, `nosnippet`, `noimageindex`, `notranslate`, `max-image-preview`, and the rest) as its own switch. A field setting chooses which directives the field offers, and hiding one never erases saved values
- Each field chooses which of its seven controls editors actually see, so a landing-page section can expose just a title and description while the blog keeps the full set. Everything is on by default, and hiding a control never erases data — saved values round-trip through hidden inputs, so a hidden noindex stays noindexed

### Meta rendering

- `{{ craft.simpleSeo.renderMeta(entry) }}` outputs title, description, canonical, robots, Open Graph, and Twitter tags with the full fallback chain applied — field value, then per-site default, then entry title. Every value is overridable per call, and an unknown override key throws rather than silently doing nothing ([#5](https://github.com/anvildevxyz/craft-simple-seo/issues/5))
- `craft.simpleSeo.resolveMeta(entry)` returns the identical data as an array, so headless consumers get parity by construction
- Canonical URLs are hardened: idempotent UTF-8 encoding, query params stripped from element-derived canonicals (allowlist via `canonicalAllowedQueryParams`), paginated pages canonicalizing to themselves, and a `Link: …; rel="canonical"` header carrying the identical URL as the tag ([#6](https://github.com/anvildevxyz/craft-simple-seo/issues/6))

### Robots

- **The invariant:** with default settings this plugin cannot emit a site-wide noindex. No setting, save, or template call can cause it, and it is enforced by tests ([#7](https://github.com/anvildevxyz/craft-simple-seo/issues/7))
- Hiding a staging environment is one explicit `siteWideNoindex` flag in `config/simple-seo.php`. It forces noindex/nofollow in meta and `X-Robots-Tag`, disallows everything in robots.txt, and shows a persistent CP warning banner so nobody forgets it is on. There is deliberately no CP control for it
- Per-site, CP-editable `robots.txt`, served exactly as written and never rendered as Twig. It warns when a physical `web/robots.txt` shadows it, when the lockdown flag is overriding it, and when its content would block every crawler from the whole site
- Any site can opt out entirely with **Serve this site's robots.txt**. Switched off, the plugin registers no `/robots.txt` route at all rather than 404ing it, so the URL falls through to your own template or a file in the web root. Saved content is kept, so switching back on restores it. `siteWideNoindex` overrides the switch — a lockdown works through the meta tag, the `X-Robots-Tag` header *and* robots.txt, and a toggle able to remove one arm would not be a lockdown

### XML sitemap

- `/sitemap.xml` works with zero configuration: an index plus per-section files, every URL-having section included until excluded, noindexed entries excluded, and hreflang alternates on multi-site entries ([#8](https://github.com/anvildevxyz/craft-simple-seo/issues/8))
- Never silently empty — empty files carry a reason comment, and `/sitemap.xml?explain` gives the full per-section diagnosis
- Generation is hydration-free: a cold 1000-URL file costs roughly 200ms at half the memory of hydrating elements, which matters because every entry save invalidates the cache
- An optional per-section, per-site `<priority>`, **empty by default and emitting no element**. Google and Bing both document that they ignore the tag, and every other Craft SEO plugin defaults it to `0.5`, so the usual result is every URL carrying an identical value nothing reads. Opt in per section and it ships; leave it and nothing is claimed. `<changefreq>` is deliberately not offered
- Any site can opt out with **Serve this site's sitemap**, on the same terms as robots.txt: no routes registered, section choices preserved, and the shipped default robots.txt stops advertising a `Sitemap:` URL the plugin no longer answers

### Console commands

Every command exits non-zero on failure, so it gates a deploy rather than producing a report nobody reads. Full reference in [docs/console-commands.md](docs/console-commands.md).

- `simple-seo/doctor` — the pre-deploy check: a site-wide noindex left on, a robots.txt disallowing every crawler, a sitemap being served with nothing in it, a physical `web/robots.txt` shadowing the CP one, a title format with no `{title}`. Findings are levelled, and only problems fail — a deliberate staging lockdown is reported as a note, because a check that cries wolf about correct configuration gets deleted from the pipeline
- `simple-seo/sitemap/explain` — the terminal twin of `/sitemap.xml?explain`, with `--strict` to fail when an included section contributes no URLs. `simple-seo/sitemap/flush` drops cached files after writes that bypass element events, such as a SQL import; there is deliberately no `warm`
- `simple-seo/meta/show <id>` — the fully resolved meta for one entry, or `--tags` for the rendered HTML, from the same model the front end renders from. Each value names its source — field, site default, entry title, element URL — so "why is this page's description that" is answered directly
- `doctor` and `audit/meta` take `--json` for pipelines that parse as well as gate: full machine-readable reports, exit codes unchanged
- `simple-seo/audit/meta` — live pages whose meta is missing, duplicated, or over the soft limits, measured on the resolved values that actually ship. Entries with no description of their own are reported as exactly that rather than as duplicates of one another, and that stays advisory: every such page resolves to the same site default, so failing on it would punish a supported way to run a site. No score, grade, or verdict

### MCP

- With [craft-mcp](https://github.com/stimmtdigital/craft-mcp) installed, the plugin registers six MCP tools for AI agents driving Craft: the doctor, the meta audit, the sitemap diagnosis, per-entry meta resolution with provenance, and two careful writes (title/description, noindex) flagged dangerous so gating clients ask first. A soft dependency — without craft-mcp, no integration code loads ([docs/mcp.md](docs/mcp.md))

### GraphQL

- The raw field value is queryable with sub-selections, including a resolved `socialImageUrl`, and is mutable as a JSON string through the same tolerant normalization as every other input path ([#9](https://github.com/anvildevxyz/craft-simple-seo/issues/9))
- Every entry and category also exposes `simpleSeo` — the fully resolved meta, backed by the same model as the Twig output

### Settings and permissions

- Per-site title format (with `{title}` and `{siteName}` tokens), default meta description, and default social image ([#4](https://github.com/anvildevxyz/craft-simple-seo/issues/4))
- Four screens in the plugin's own CP section. General, Sitemap and Robots each edit one site at a time, picked with Craft's native site breadcrumb, so a twenty-site install stays navigable; **Fields** is install-wide and controls which SEO controls any field may offer at all, with each field then picking from that list
- The Sitemap screen is a table of every section with its live URL count, linking straight to that section's file — the same hydration-free diagnosis as `?explain`, so "how many URLs will actually ship" is answerable without leaving the CP. A section carrying no URLs says why, right in the table. Every section can be switched off, including singles and sections with no URLs yet, and choices are stored inverted so a section created later joins the sitemap on its own
- First-run guidance: until an SEO field exists, the General screen walks through the three setup steps and the Fields screen offers to create the field — and `doctor` reports whether one exists and sits in a field layout
- Access is permission-based, not admin-only. Craft's own **Access Simple SEO** opens every screen read-only; **Manage SEO settings** saves General, Sitemap and Fields; **Edit robots.txt** is nested separately, because it is the one screen where a wrong value stops search engines crawling the site at all. An SEO role can own the day-to-day settings without being handed the crawling switch — or admin rights
- Portable settings live in project config and deploy with your project; the default social image reference is stored in the database instead, so `allowAdminChanges: false` environments keep a fully working settings screen

### Migrating from ether/seo

- `craft simple-seo/migrate/ether` — dry run by default, `--apply` to write — converts every ether SEO field **in place**, keeping the same field ID, UID, and handle, with field layouts untouched. Titles, descriptions, social images, robots, and canonicals map per site, and the field's own configuration (translation method, searchability, instructions) is carried over ([#10](https://github.com/anvildevxyz/craft-simple-seo/issues/10))
- Redirects export as a Retour-importable CSV, ether's settings are surfaced for review, and dropped focus keywords are reported rather than silently lost
- It reads the database directly, so it works even when ether can no longer be installed on your Craft version

### Documentation and translations

- Documentation covering getting started, the field, Twig/GraphQL, canonicals, robots, sitemap, settings, console commands, and the Ether SEO migration ([#11](https://github.com/anvildevxyz/craft-simple-seo/issues/11))
- German, French, Spanish, and Italian translations (machine-translated, pending native review), kept in lockstep by a coverage test that fails when a new string lacks one
- Plugin icon and quality gate from the first commit ([#1](https://github.com/anvildevxyz/craft-simple-seo/issues/1), [#12](https://github.com/anvildevxyz/craft-simple-seo/issues/12))
