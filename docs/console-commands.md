# Console Commands

Every command exits non-zero when it finds something wrong, so they work as deploy or CI gates rather than as reports someone has to remember to read.

| Command | What it's for |
|---|---|
| `simple-seo/doctor` | Pre-deploy check: is anything about to damage this install's search visibility? |
| `simple-seo/sitemap/explain` | Why each section is or isn't in the sitemap, and with how many URLs |
| `simple-seo/sitemap/flush` | Drop the cached sitemap files |
| `simple-seo/meta/show <id>` | The fully resolved meta for one entry |
| `simple-seo/audit/meta` | Live pages whose meta is missing, duplicated, or over the soft limits |
| `simple-seo/migrate/ether` | Migrate from ether/seo — see [migrating](migrating-from-ether-seo.md) |

## doctor

```
craft simple-seo/doctor [--quiet] [--json]
```

Checks every site for the states that quietly cost you traffic: a site-wide noindex left on, a robots.txt disallowing every crawler, a sitemap being served with nothing in it, a physical `web/robots.txt` shadowing the one you're editing in the CP, a title format with no `{title}` token. It also states whether an SEO field exists and sits in a field layout — the most common reason a fresh install shows nothing on entries — as a note, never a problem, because sitemap and robots work without one.

Three levels, and only one of them fails:

- **✓ ok** — checked, healthy.
- **! note** — deliberate configuration, restated so it can't surprise you. A staging lockdown is a note, not a problem.
- **✗ problem** — SEO is broken. Exits `1`.

Notes never fail the run, on purpose. A check that cried wolf about a correct staging lockdown would be removed from the pipeline within a week.

`--quiet` prints problems only, which is usually what you want in CI. `--json` prints the findings as JSON instead of the table — the exit code is unchanged, so a pipeline can parse and gate in one call.

## sitemap/explain

```
craft simple-seo/sitemap/explain [--site=<handle>] [--strict]
```

The terminal twin of `/sitemap.xml?explain`. Lists every section with its URL count and the exact reason it is or isn't included.

`--strict` exits non-zero if any *included* section contributes no URLs — the state where the index links to a file that exists and is empty, which looks fine right up until traffic disappears.

## sitemap/flush

```
craft simple-seo/sitemap/flush
```

Entry and section changes already invalidate the cache on their own. This is for writes that bypass those events: a raw SQL import, or a restore from a database dump.

There is deliberately no `warm` command — a cold rebuild is fast by design, so there is nothing worth warming.

## meta/show

```
craft simple-seo/meta/show <entryId> [--site=<handle>] [--tags]
```

Prints the resolved meta for one entry, every fallback already applied — the same model the front end and GraphQL both render from, so what it prints is what ships. `--tags` prints the rendered HTML instead of the values.

Each value with a fallback chain also names its source — `← field`, `← site-default`, `← entry-title`, `← element-url`, or `← none` — so "why is this page's description that?" is answered directly instead of by elimination.

## audit/meta

```
craft simple-seo/audit/meta [--site=<handle>] [--section=<handle>] [--limit=20] [--tolerate] [--json]
```

Lists live, URL-having pages whose meta is missing, duplicated, or over the soft length limits. Drafts and URL-less entries are skipped: they aren't what search engines see, and including them would bury the real findings.

Everything is measured on the **resolved** value — what actually ships, with per-site defaults and the title format applied.

| Reported | Fails the run? |
|---|---|
| No description at all (no entry value, no site default) | Yes |
| Duplicate title | Yes |
| Duplicate description (authored) | Yes |
| Title or description over the soft limit | Yes |
| No description of its own, showing the site default | **No** — advisory |

The last row is the important one. Every entry without its own description resolves to the same site default, so treating those as duplicates of each other would report one row per page and bury everything else. It's reported as what it is — these pages have no description of their own — and it never fails a build, because leaning on a site default is a supported way to run a site.

`--tolerate` reports without failing. `--json` prints the full report as JSON — every issue row included, `--limit` only truncates the human output — with the exit code unchanged.

## What these commands deliberately don't do

**No SEO score, grade, or overall verdict.** `audit/meta` reports facts about the meta that will ship — never an opinion about the writing.

**No sitemap ping.** Google and Bing have both retired their sitemap ping endpoints; a command for it would do nothing.

**No IndexNow, link auditing, or redirect handling.** Those are [Beacon](https://plugins.craftcms.com/beacon)'s, and the split is deliberate.

**No bulk meta editing.** Writing meta across thousands of entries from a terminal is a support liability with no undo. Use the CP, or a migration you control.
