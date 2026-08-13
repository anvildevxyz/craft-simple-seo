# Simple SEO Documentation

Simple SEO does exactly six things: an SEO field, a live preview, one-line meta rendering, correct canonicals, safe robots handling, and an XML sitemap. The product charter lives in the [README](../README.md#scope-charter).

## Using it

- [Getting started](getting-started.md) — install, add the field, one line of Twig
- [The SEO field](the-field.md) — every input, the preview, the value object
- [Twig & PHP reference](twig-reference.md) — `renderMeta`/`resolveMeta`, override keys, the tag inventory
- [Canonical URLs](canonicals.md) — precedence, normalization, pagination, the Link header
- [Robots](robots.md) — the invariant, the staging lockdown, robots.txt
- [Sitemap](sitemap.md) — zero-config behavior, caching, the `?explain` diagnosis
- [Headless & GraphQL](headless.md) — both types field-by-field, mutations, scoping

## Configuring it

- [Settings reference](settings-reference.md) — every setting, its default, and where it's stored

## Commands and agents

- [Console commands](console-commands.md) — `doctor`, sitemap diagnosis, meta audit
- [MCP tools](mcp.md) — the same diagnostics and careful meta edits for AI agents, via craft-mcp

## Migrating

- [Migrating from Ether SEO](migrating-from-ether-seo.md) — the one-command migration
