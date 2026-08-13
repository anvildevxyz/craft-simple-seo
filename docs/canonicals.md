# Canonical URLs

Canonicals are where SEO plugins quietly go wrong — wrong encoding, tracking params, paginated pages claiming to be page one, tag and header disagreeing. Simple SEO treats the canonical as a specified behavior.

## Precedence

1. **Template override** — `renderMeta(entry, { canonical: '…' })`
2. **Field override** — the entry's Canonical URL field
3. **The element's own URL** — with pagination applied
4. Nothing → no canonical tag, no `og:url`, no header

Author-entered canonicals (1 and 2) are **author intent**: their query params are kept verbatim; only the encoding is normalized. Element-derived canonicals (3) treat params as request noise and strip them.

## Normalization (applies to every canonical)

- **UTF-8 path segments are percent-encoded**, idempotently: `über-uns` and an already-encoded `%C3%BCber-uns` both come out as `%C3%BCber-uns` — never double-encoded, never raw.
- **Fragments are always dropped** (`#section` doesn't belong on a canonical).
- **Query values keep readable slashes** — `?p=some/path` is not mangled into `%2F`, so the canonical stays byte-identical to the URL it describes.

## Query params on element canonicals

Stripped by default — `?utm_source=…&fbclid=…` never reaches your canonical. Two exceptions:

- Params you allowlist via `canonicalAllowedQueryParams` in [`config/simple-seo.php`](settings-reference.md#configsimple-seophp).
- **Craft's path param** (`?p=…`, the `pathParam` general setting) is *always* implicitly allowed — on sites without `omitScriptNameInUrls` it *is* the URL, and stripping it would destroy the canonical.
- On query-style pagination (`pageTrigger: '?page'`), the page param is implicitly allowed too.

## Pagination

Paginated pages canonicalize **to themselves** — page two of a listing never claims to canonically be page one. Both of Craft's `pageTrigger` styles are honored: path segments (`/blog/p3`) and query strings (`?page=3`). This only applies to element-derived canonicals; an explicit author canonical is used verbatim on every page.

## The Link header

Alongside the tag, front-end responses get:

```
Link: <https://example.com/%C3%BCber-uns>; rel="canonical"
```

Tag and header are built from the **same resolved value** — agreement holds by construction, not by discipline. Disable the header with `canonicalLinkHeader => false` in `config/simple-seo.php`; the tag is unaffected. The header covers consumers that read headers before parsing HTML, and it's the layer search engines check on non-HTML responses.
