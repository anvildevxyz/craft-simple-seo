# Headless & GraphQL

The same resolved meta that backs Twig rendering is available to headless consumers — one model, three surfaces (Twig tags, `resolveMeta()` array, GraphQL), no drift possible.

## `simpleSeo` — resolved meta on every entry and category

Lives on the **interface** (`EntryInterface`/`CategoryInterface`), so no inline fragment is needed:

```graphql
{
  entry(uri: "about") {
    simpleSeo {
      title          # "About us · Acme" — title format applied
      socialTitle    # "About us" — bare, for og/twitter
      description    # field value, or the site default
      canonical      # normalized: percent-encoded, params handled
      robots         # e.g. "noindex, nofollow, noarchive" — or null (= index,follow)
      ogType         # "website"
      ogSiteName
      ogImageUrl     # field image, or site default — absolute URL, or null
      twitterCard    # "summary_large_image" | "summary"
    }
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `title` | `String!` | Fallback chain + per-site format applied |
| `socialTitle` | `String!` | No site-name suffix |
| `description` | `String` | `null` when neither field nor site default set |
| `canonical` | `String` | See [canonicals](canonicals.md) |
| `robots` | `String` | `null` = default index,follow |
| `ogType` | `String!` | |
| `ogSiteName` | `String!` | |
| `ogImageUrl` | `String` | Absolute URL |
| `twitterCard` | `String!` | |

## The raw field value

The field itself is queryable like any custom field — which means **an inline fragment on the concrete type** (interface types don't include custom fields):

```graphql
{
  entry(uri: "about") {
    ... on page_Entry {
      seo {            # your field's handle
        title          # exactly what the editor typed (null if empty)
        description
        socialImageId  # Int
        socialImageUrl # resolved absolute URL
        noindex        # Boolean!
        nofollow       # Boolean!
        robotsDirectives # [String!]!  extra directives
        robots         # String    all of the above, comma-joined
        canonical      # as typed, un-normalized
      }
    }
  }
}
```

Rule of thumb: **use `simpleSeo` to render a head; use the raw field to build editing UIs.**

## Mutations

With a save-scoped schema, the field is writable as a **JSON string** (Craft's standard String argument for custom fields). It flows through the same junk-tolerant normalization as every other input path — unknown keys ignored, malformed JSON degrades to an empty value, never an error:

```graphql
mutation Save($id: ID, $seo: String) {
  save_pages_page_Entry(id: $id, seo: $seo) {
    seo { title noindex }
  }
}
```

```json
{ "id": 123, "seo": "{\"title\":\"New title\",\"noindex\":true,\"canonical\":\"https://example.com/x\"}" }
```

Accepted keys: `title`, `description`, `socialImageId`, `noindex`, `nofollow`, `canonical`, `robotsDirectives` — the same shape the field stores.

## Schema scoping

- `simpleSeo` and the raw field ride the **element's own visibility**: if your schema can read the entry, it can read its meta (meta is public-facing data by definition). There is no separate SEO scope to manage.
- Over the **HTTP endpoint**, schemas additionally need **`sites.<uid>:read`** for each queried site — you'll get a 403 "Schema doesn't have access to the site" without it. (Craft's CP schema editor handles this; hand-built schemas must include it.)
- Mutations need the section's save scope, as usual.

## Sitemap & robots.txt under headlessMode

`/sitemap.xml`, `/sitemaps/section-*.xml`, and `/robots.txt` are controller-backed routes with no templates involved — they keep working when Craft runs in `headlessMode` with a separate front end. Point search engines at the Craft domain's sitemap (or proxy those paths through your front end).

## Twig without tags

```twig
{% set meta = craft.simpleSeo.resolveMeta(entry) %}
```

The identical array — see the [Twig reference](twig-reference.md) for the full field list and override keys (overrides work headless too: `resolveMeta(entry, { ogType: 'article' })`).
