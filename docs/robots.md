# Robots

## The invariant

With default settings, **Simple SEO cannot emit a site-wide noindex.** There is no CP setting, save operation, or template call that can cause it. The robots meta tag renders only when an editor sets noindex/nofollow on a specific entry — absent means the default index,follow. This is enforced by tests, and it exists because the most-commented bug in the plugin we replace silently de-indexed entire live sites.

Per-entry robots render exactly what's set — never a directive you didn't ask for.

## Serving robots.txt at all

**Simple SEO → Robots** carries a **Serve this site's robots.txt** switch, per site. Turn it off and the plugin registers no `/robots.txt` route for that site, so the URL falls through to whatever else answers it — your own template, another plugin, or a physical file in the web root (which wins over the route regardless, switched on or off).

Your saved content is kept while it is off, so turning it back on restores what you wrote.

**`siteWideNoindex` overrides the switch.** The lockdown works through three arms — the robots meta tag, the `X-Robots-Tag` header, and robots.txt — and a settings toggle that could remove one of them would not be a lockdown. With the flag on, robots.txt is served and disallows everything no matter what the switch says; the screen tells you when that is happening.

## Per-entry directives

The SEO field carries two switches on its **Robots** tab, which is all most pages ever need:

- **noindex** — asks search engines not to list the page. It's also the sitemap exclusion switch.
- **nofollow** — asks them not to follow the page's links.

The rest of the directives Google documents sit on the same tab, each as its own switch. The field's **Robots directives shown** setting picks which of them the field offers:

| Directive | Effect |
|---|---|
| `noarchive` | No cached copy in results |
| `nosnippet` | No text snippet or video preview |
| `noimageindex` | Don't index images on this page |
| `notranslate` | Don't offer to translate the page |
| `nositelinkssearchbox` | No sitelinks search box |
| `indexifembedded` | Allow indexing when embedded elsewhere, despite noindex |
| `max-image-preview:large` / `:none` | Image preview size |
| `max-snippet:0` | No text snippet at all |
| `max-video-preview:0` | No video preview |

They combine into one tag, always in the order above regardless of the order you tick them, so the output is stable across saves:

```html
<meta name="robots" content="noindex, nofollow, noarchive, max-image-preview:large">
```

An element that asks for nothing unusual emits **no tag at all** — absent means `index, follow`, and a tag saying so is noise. Unrecognized directives are dropped rather than passed through: a directive search engines don't understand is worse than none, because it looks like it works.

## The staging lockdown

Hiding a staging environment is a real need — it's just something that must be **explicit**. In `config/simple-seo.php`:

```php
'siteWideNoindex' => App::env('CRAFT_ENVIRONMENT') !== 'production',
```

When (and only when) that flag is true, four things happen at once:

1. Every rendered page gets `<meta name="robots" content="noindex, nofollow">` — winning over template overrides (a lockdown that can be overridden isn't one)
2. Every front-end response gets an `X-Robots-Tag: noindex, nofollow` header (covers responses that don't use `renderMeta`)
3. `robots.txt` becomes `Disallow: /`
4. Every CP page shows a persistent warning banner, so nobody forgets the flag is on

There is deliberately no CP control for this — a clickable site-wide noindex is how sites get de-indexed by accident.

## robots.txt

`/robots.txt` works with zero configuration, per site:

```
User-agent: *
Disallow:

Sitemap: https://example.com/sitemap.xml
```

To change it, edit **Simple SEO → Robots** in the CP. Each site has its own content, and `{sitemapUrl}` expands to that site's sitemap index. Clearing the field restores the default above.

Content is served **exactly as written**. It is never rendered as Twig — a settings textarea that evaluates templates is a code-execution surface, and robots.txt has no need for one.

Three things to know:

- **A physical `web/robots.txt` always wins.** Web servers serve files before Craft routes ever run. The Robots screen detects this and tells you, rather than letting you edit a file nobody is being served.
- **The lockdown still wins over your content.** With `siteWideNoindex` on, robots.txt is `Disallow: /` regardless of what's saved here.
- **Blanket `Disallow: /` gets a warning.** Saving content that blocks every crawler from the whole site shows a prominent notice — correct for staging, catastrophic for production, and never something to do by accident.

### Why the default isn't environment-aware

Other SEO plugins ship a default robots.txt that disallows everything unless the environment is `live`. That reads as safe and isn't: it means one misconfigured environment variable in production silently de-indexes the site — the exact failure this plugin's invariant exists to prevent. Our default is open everywhere, and hiding an environment is the one explicit flag above.
