# The SEO Field

The field type is the heart of the plugin: one field, seven controls, stored as a single JSON value on the element, per site.

## Inputs

| Input | Behavior |
|---|---|
| **Live preview** | Tabbed panel above the inputs — Search, Social, and Robots — rendered from data already on the page. |
| **Meta title** | Overrides the element title in search results and browser tabs. Soft 60-character counter — informational only, never blocking. The counter measures the full formatted title (site title format applied, suffix included), because that is the string results truncate. |
| **Meta description** | Soft 160-character counter. |
| **Social image** | Single asset (images only). Shown when the page is shared; also becomes `og:image`/`twitter:image`. |
| **Hide from search engines (noindex)** | Per-entry robots toggle, on the **Robots** tab. Also removes the entry from the sitemap — one switch, both effects, as the instructions say. |
| **Don't follow links (nofollow)** | Per-entry robots toggle, on the **Robots** tab. |
| **Additional robots directives** | The remaining Google directives (`noarchive`, `nosnippet`, `noimageindex`, `notranslate`, `max-image-preview:*`, …), each as its own switch on the **Robots** tab. The field's settings choose which of them it offers. Most pages need none — see [robots](robots.md). |
| **Canonical URL override** | Validated as a full URL (including scheme) at save — an invalid value blocks the save with a field error. Leave empty to use the page's own URL. |

Above the inputs sits the **live preview** — a tabbed panel rendered entirely from data already on the page. It updates as you type, has no network request that could fail, and follows the ARIA tabs pattern (arrow keys work). Its third tab, **Robots**, holds the per-entry robots controls, so the main input flow stays title, description, image, canonical. When the field renders without a preview (inline editing, the field defaults screen, or the preview control turned off) the robots controls render inline instead. The counters turn amber at 90% of the soft limit and red over it, announcing crossings to screen readers politely (crossings only — never every keystroke).

## Where it works

Anywhere field layouts work: **entries, categories, tags, Commerce products**. Sections where only *some* entry types carry the field are fine — elements without it simply fall back to site defaults everywhere. Elements that existed before you added the field render graceful defaults; nothing errors, nothing needs resaving.

## Behavior details

- **Storage**: one JSON value in Craft's content storage (`elements_sites.content`), per site. Untouched values store as SQL `NULL` — adding the field to a layout writes nothing until an editor does.
- **Translation**: standard Craft field translation methods are supported. With per-site translation, each site gets independent meta (and independent noindex — an entry can be hidden on one site and indexed on another).
- **Element indexes**: the field is previewable — its column shows the meta title plus a red `noindex` badge when set.
- **Search**: meta title and description feed Craft's search index (mark the field searchable in the layout).
- **Robustness contract**: `%`, quotes, emoji, multibyte, and pasted markup can never break the field, the preview, or the save — inputs are only ever read and re-emitted, never parsed. This is regression-tested at every layer.
- **Validation**: the canonical must be a full URL; titles/descriptions have a generous 1000-character hard cap protecting downstream consumers (the 60/160 limits stay soft).

## Choosing which controls editors see

Each SEO field has a **Fields shown on entries** setting listing its seven controls, grouped as **Preview** (the live preview), **Content** (meta title, meta description, social image), and **Indexing** (the noindex/nofollow switches, the additional robots directives, the canonical override). Everything is on by default, so existing fields are unaffected.

Turn things off where the content doesn't need them: a landing-page section might expose only a title and description, while the blog gets the full set. Because it's per field, one site can run both.

Two levels, because they answer different questions:

- **Simple SEO → Fields** (install-wide) — what SEO fields may offer *at all*. Unchecking something here removes it from every field.
- **Settings → Fields → your SEO field** — which of the available controls *this* field shows.

**Hiding a control never erases data.** Values already saved on entries round-trip through hidden inputs, so a hidden noindex stays noindexed and a hidden canonical keeps pointing where it did. Turn the control back on and the value is still there.

### What each control does

- **Meta title** — overrides the entry title in search results and browser tabs. Falls back to the entry title, then through the site's title format.
- **Meta description** — the summary shown under the title in results. Falls back to the per-site default description.
- **Social image** — used when the page is shared on social platforms. Falls back to the per-site default image.
- **noindex** — asks search engines not to list the page. It is *also* the sitemap exclusion switch: one toggle, both effects, so the two can never disagree.
- **nofollow** — asks them not to follow the page's links.
- **Additional robots directives** — the rest of Google's documented set, each as its own switch. Most pages need none of these; the two switches above cover the common cases. A second field setting, **Robots directives shown**, picks which directives the field offers — hiding one never erases saved values.
- **Canonical URL override** — leave empty to use the page's own URL. Only set it when the content lives canonically at another URL. Author-entered canonicals keep their query parameters verbatim, unlike derived ones.

## The value object (`SeoData`)

`entry.seoField` in Twig (or `getFieldValue()` in PHP) returns a `SeoData` model:

| Property/method | Type | Meaning |
|---|---|---|
| `title` | `?string` | Author meta title, `null` = fall back to element title |
| `description` | `?string` | `null` = fall back to site default |
| `socialImageId` | `?int` | Asset ID |
| `getSocialImage()` | `?Asset` | Resolved asset (memoized; `null` if unset or deleted) |
| `noindex` / `nofollow` | `bool` | Robots toggles |
| `robotsDirectives` | `string[]` | Extra robots directives; unknown values are dropped |
| `robots()` | `?string` | All of the above as one directive string, or `null` |
| `canonical` | `?string` | Author canonical override, as typed |
| `isEmpty()` | `bool` | True when nothing was ever entered |

You rarely need the raw value — [`craft.simpleSeo.renderMeta()`](twig-reference.md) applies the whole fallback chain for you. The raw value exists for custom logic and [GraphQL](headless.md).

## Deliberately absent

No focus keywords, no content scoring, no per-field content analysis. Which controls a field shows is configurable; what the field *does* with a stored value is not.
