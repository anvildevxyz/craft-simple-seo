# Twig & PHP API Reference

## `craft.simpleSeo.renderMeta(element = null, overrides = [])`

Renders the full set of head tags. One line in your layout's `<head>`:

```twig
{{ craft.simpleSeo.renderMeta(entry) }}
```

- `element` — any element (entry, category, …) or `null` for site-level meta (e.g. custom routes).
- `overrides` — per-call value overrides (below).
- Returns raw markup (`Twig\Markup`) — values are already entity-encoded.
- Side effect: emits the `Link: <…>; rel="canonical"` header (front-end requests only; [disable via config](settings-reference.md#configsimple-seophp)).

## `craft.simpleSeo.resolveMeta(element = null, overrides = [])`

The identical resolved data as an array — for JSON endpoints, custom head handling, or anywhere you want values instead of tags. Tags and array are serializations of one model; they cannot disagree.

```twig
{% set meta = craft.simpleSeo.resolveMeta(entry) %}
{{ meta.title }} {{ meta.canonical }} {{ meta.robots }}
```

In PHP: `Plugin::getInstance()->getMeta()->resolve($element, $overrides)` returns the `ResolvedMeta` model; `->renderTags()` the markup.

## Override keys

Every value is overridable per call. **Unknown keys throw** with the allowed list — a typo'd override silently doing nothing is exactly the frustration this plugin exists to avoid.

| Key | Overrides | Notes |
|---|---|---|
| `title` | `<title>` **and** the social title | Bypasses the title format entirely |
| `description` | All description tags | |
| `canonical` | Canonical tag + header + `og:url` | Treated like an author canonical: params kept, encoding normalized |
| `robots` | The robots meta | Explicit `null` suppresses the tag. The [staging lockdown](robots.md) still wins — a lockdown that can be overridden isn't one |
| `ogType` | `og:type` | Default `website`; set `article` on post templates |
| `ogSiteName` | `og:site_name` | Default: the site name |
| `ogImage` | `og:image`/`twitter:image` URL | Explicit `null` removes the image (and flips `twitterCard` to `summary`) |
| `twitterCard` | `twitter:card` | Default: `summary_large_image` when an image resolved, else `summary` |

```twig
{{ craft.simpleSeo.renderMeta(entry, { ogType: 'article', ogSiteName: 'Acme Blog' }) }}
```

## The resolved model

| Field | Type | Resolution |
|---|---|---|
| `title` | `string` | field title → element title, then the per-site [title format](settings-reference.md#general) applied (never doubled, never forced) |
| `socialTitle` | `string` | The bare title, no site-name suffix (og/twitter convention); falls back to the site name |
| `description` | `?string` | field → per-site default → `null` |
| `canonical` | `?string` | See [canonicals](canonicals.md) — normalized, never wrong-encoded |
| `robots` | `?string` | Exactly what's set — the noindex/nofollow toggles plus any [extra directives](robots.md), comma-joined in documented order — or `null` (= default index,follow; no tag emitted) |
| `ogType` | `string` | `website` unless overridden |
| `ogSiteName` | `string` | Site name unless overridden |
| `ogImageUrl` | `?string` | field image → per-site default image → `null`; always an absolute URL |
| `twitterCard` | `string` | `summary_large_image` / `summary` |

## Exact tag inventory

What `renderMeta()` emits, in order — and when a tag is omitted:

```html
<title>…</title>                                    <!-- always -->
<meta name="description" content="…">               <!-- only when a description resolved -->
<link href="…" rel="canonical">                     <!-- only when a canonical resolved -->
<meta name="robots" content="…">                    <!-- only when robots is set — absent means index,follow -->
<meta property="og:site_name" content="…">          <!-- always -->
<meta property="og:type" content="…">               <!-- always -->
<meta property="og:title" content="…">              <!-- always (bare title) -->
<meta property="og:description" content="…">        <!-- with description -->
<meta property="og:url" content="…">                <!-- with canonical (same URL) -->
<meta property="og:image" content="…">              <!-- with image -->
<meta name="twitter:card" content="…">              <!-- always -->
<meta name="twitter:title" content="…">             <!-- always -->
<meta name="twitter:description" content="…">       <!-- with description -->
<meta name="twitter:image" content="…">             <!-- with image -->
```

Plus the `Link: <canonical>; rel="canonical"` HTTP header — always carrying the byte-identical URL as the tag.

Notes:

- The plugin owns the `<title>` tag — remove any hardcoded one from your layout.
- Nothing here emits a site-wide noindex; see [the robots invariant](robots.md).
- Every value passes through entity encoding; markup pasted into a meta title renders inert.

## Patterns

```twig
{# Blog post #}
{{ craft.simpleSeo.renderMeta(entry, { ogType: 'article' }) }}

{# Custom route without an element — site defaults + your own title #}
{{ craft.simpleSeo.renderMeta(null, { title: 'Search results', robots: 'noindex' }) }}

{# JSON endpoint for a JS front end #}
{% set meta = craft.simpleSeo.resolveMeta(entry) %}
{{ meta|json_encode|raw }}
```
