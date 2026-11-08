# MCP Tools

On Craft 5.12 or newer, Simple SEO registers its tools with Craft's built-in MCP server. Nothing else to install: enable the server under Settings → MCP (or run `php craft mcp/serve --user=…` locally) and the `simple_seo_*` tools are available to that user, within their permissions. Read tools are marked read-only, so a read-only token can call them. The two writes require write access, and every tool requires permission to open Simple SEO.

On older Craft, the same tools register with the [craft-mcp](https://github.com/stimmtdigital/craft-mcp) plugin when it is installed. Without either server, nothing changes; both integrations are soft dependencies and no code from them loads.

This makes Simple SEO usable by AI agents driving Craft over MCP: an agent can diagnose the install, explain the sitemap, audit the meta that ships, resolve one page's meta with provenance, and make targeted edits — through the same services the console commands and the front end use.

## Read tools

| Tool | What it returns |
|---|---|
| `simple_seo_doctor` | The [doctor](console-commands.md#doctor) findings: levelled checks with details and fixes. |
| `simple_seo_audit_meta` | The [meta audit](console-commands.md#auditmeta): live pages with missing, duplicated, or over-limit meta. Can be slow on large sites; narrow with `sectionHandle`. |
| `simple_seo_explain_sitemap` | The per-section sitemap diagnosis — the same one as `/sitemap.xml?explain`. |
| `simple_seo_resolve_meta` | One entry's fully resolved meta, each value naming its source (`field`, `site-default`, `entry-title`, `element-url`, `none`). |

## Write tools

Both writes go through the normal element save, so field validation, events, and drafts/revisions behavior all apply. Both are flagged **dangerous** for craft-mcp, and marked destructive (not read-only) for Craft's server, so a read-only token cannot call them and clients that gate dangerous tools will ask first.

| Tool | What it does |
|---|---|
| `simple_seo_set_entry_meta` | Sets the meta title and/or description on one entry. An empty string clears a value back to its fallback; an omitted argument leaves it unchanged. Returns the re-resolved meta. |
| `simple_seo_set_entry_noindex` | Switches noindex for one entry — which also removes it from the sitemap. Returns the re-resolved meta. |

There are deliberately no tools for the settings screens, robots.txt content, or the site-wide lockdown. Those change what search engines may crawl across the whole site; they stay behind the CP's permissions and the config file.

## Scope

The tools report facts about the meta that ships — never a score, grade, or content advice.
