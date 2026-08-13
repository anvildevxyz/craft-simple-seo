# MCP Tools

If the [craft-mcp](https://github.com/stimmtdigital/craft-mcp) plugin is installed, Simple SEO registers a set of MCP tools automatically — nothing to configure. Without craft-mcp, nothing changes; the integration is a soft dependency and no code from it loads.

This makes Simple SEO usable by AI agents driving Craft over MCP: an agent can diagnose the install, explain the sitemap, audit the meta that ships, resolve one page's meta with provenance, and make targeted edits — through the same services the console commands and the front end use.

## Read tools

| Tool | What it returns |
|---|---|
| `simple_seo_doctor` | The [doctor](console-commands.md#doctor) findings: levelled checks with details and fixes. |
| `simple_seo_audit_meta` | The [meta audit](console-commands.md#auditmeta): live pages with missing, duplicated, or over-limit meta. Can be slow on large sites; narrow with `sectionHandle`. |
| `simple_seo_explain_sitemap` | The per-section sitemap diagnosis — the same one as `/sitemap.xml?explain`. |
| `simple_seo_resolve_meta` | One entry's fully resolved meta, each value naming its source (`field`, `site-default`, `entry-title`, `element-url`, `none`). |

## Write tools

Both writes go through the normal element save, so field validation, events, and drafts/revisions behavior all apply. Both are flagged **dangerous** in their tool metadata, so MCP clients that gate dangerous tools will ask first.

| Tool | What it does |
|---|---|
| `simple_seo_set_entry_meta` | Sets the meta title and/or description on one entry. An empty string clears a value back to its fallback; an omitted argument leaves it unchanged. Returns the re-resolved meta. |
| `simple_seo_set_entry_noindex` | Switches noindex for one entry — which also removes it from the sitemap. Returns the re-resolved meta. |

There are deliberately no tools for the settings screens, robots.txt content, or the site-wide lockdown. Those change what search engines may crawl across the whole site; they stay behind the CP's permissions and the config file.

## Scope

The tools report facts about the meta that ships — never a score, grade, or content advice.
