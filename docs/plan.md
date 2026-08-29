# Eye — plan

**Iframes that behave.** An embed experience for Craft CMS content that fixes the four things
that actually go wrong with an iframe in a CMS: it is the wrong size, it loads too early, it
leaks the reader to a third party before they asked, and sometimes the other site simply refuses
to be framed at all.

Package `justinholtweb/craft-eye`, namespace `justinholtweb\eye`, handle `eye`.
**Free — no editions, no licensing code.** Craft 5.3+, PHP 8.2+, no build step.

Reference point: the WordPress *Advanced iFrame* plugin. Eye covers the same ground (auto height,
content extraction, click-to-load, sandboxing, caching, an X-Frame-Options workaround) but is
built the Craft way — an element with a reference tag rather than a shortcode.

## The load-bearing idea: reference tags

Same as `[[project_craft_legs]]`. `craft\htmlfield\HtmlFieldData::__construct()` runs
`Elements::parseRefs()` over every rich-text value and splices the result in **raw**, so an
`Embed` element with `refHandle() = 'eye'` and a `getRender()` returning `Markup` renders
`{eye:pricing-calc:render}` inside CKEditor, Redactor and plain HTML fields — no template
changes, no per-editor rendering code, and a bad handle leaves the tag visible instead of the
embed silently vanishing.

**The editor integrations are editing affordances only.** They write
`<div class="eye-embed" data-eye-handle="x">{eye:x:render}</div>`. Nothing about rendering
depends on them.

## The five render modes

An embed's `mode` decides how the frame gets its height, which is the whole problem:

| mode | what it does | height from |
| --- | --- | --- |
| `ratio` | responsive aspect-ratio box (the default; providers set it) | CSS `aspect-ratio` |
| `fixed` | an explicit height | the author |
| `auto` | the frame reports its own height | same-origin DOM read, or a `postMessage` handshake with `eye-child.js` on the remote page |
| `proxy` | Eye fetches the page server-side and serves it from **its own origin** into the frame | same-origin DOM read — always works |
| `inline` | Eye fetches the page and splices the extracted fragment straight into the document — no iframe at all | it is just content |

`proxy` is the interesting one: by putting the remote content on your own origin it answers
X-Frame-Options *and* makes auto-height, CSS injection and content extraction work without any
cooperation from the other site.

## Proxy safety (this is the part that has to be right)

An HTTP fetcher that takes a URL is an SSRF hole unless every one of these holds. `services\Fetcher`:

1. **Scheme allowlist** — `http`/`https` only.
2. **Host allowlist, required.** Nothing is fetched from a host that is not in
   `Settings::$allowedHosts` (exact or `*.example.com`). An empty allowlist fetches nothing.
3. **IP validation on every hop.** Resolve the host, reject loopback / private / link-local /
   reserved / CGNAT for both v4 and v6, and reject a host that resolves to nothing.
4. **The connection is pinned to the validated IP** with `CURLOPT_RESOLVE`, so a DNS rebind
   between the check and the connect cannot land on 169.254.169.254.
5. **Redirects are followed by hand**, capped, and re-validated (host allowlist + IP) at each hop.
6. **Response caps** — size, time, and `Content-Type` must be HTML/text.
7. **Nothing is forwarded** — no cookies, no auth headers, no client IP; Eye's own user agent.
8. **The public route takes an embed, not a URL.** `/eye/proxy/<uid>` resolves a *stored* embed,
   so it is not an open proxy. One-off (field/inline) configs travel as an HMAC-signed payload.
9. Extracted HTML is run through **HTML Purifier** before it reaches the page in `inline` mode.

`Settings::$proxyEnabled` defaults to **false**. Turning it on with an empty allowlist is a
no-op, and the settings screen says so.

## Providers

`services\Providers` — paste a URL, get the right embed. Each provider knows its URL patterns,
the embed URL to build, the natural aspect ratio, the `allow` tokens it needs, a privacy-friendly
host where one exists (`youtube-nocookie.com`), and how to find a poster image for click-to-load.
YouTube, Vimeo, Loom, Wistia, Google Maps/Docs/Sheets/Slides/Forms/Calendar, Calendly, Spotify,
SoundCloud, Bandcamp, Apple Podcasts, Figma, CodePen, JSFiddle, CodeSandbox, Airtable, Typeform,
Miro, Canva, OpenStreetMap, Descript, plus a generic fallback that just frames the URL.

This is what makes "paste a URL into rich text" work, and it is why an author never types an
`<iframe>`.

## Data model

- `elements\Embed` — the library element. **Localized with `getSupportedSites()` = every site**
  so an embed is findable from any site, but not translatable: one embed, one URL, everywhere.
  (`[[project_craft_legs]]`'s note applies — an element that only exists on the primary site
  cannot be referenced from a second one.)
- `{{%eye_embeds}}` — `id` PK/FK→elements CASCADE, `handle` (unique), `url`, `provider`,
  `mode`, `config` (JSON: everything presentational), `framable`, `checkStatus`, `checkMessage`,
  `checkedAt`.
- `models\EmbedOptions` — the presentational config, shared verbatim by the element, by inline
  field values, by the editor JS and by the renderer. One format everywhere.

## Surfaces

1. **Rich text** — CKEditor 5 plugin and Redactor plugin, both writing the same ref-tag block and
   sharing one picker. Paste a provider URL on its own line and the CKEditor plugin converts it.
2. **Field type** — `fields\EmbedField`, in either *inline* (configure here) or *library*
   (reference an embed) mode.
3. **Twig** — `craft.eye.embed('handle')`, `craft.eye.url('https://…')`, an `eye` filter and
   function, and `craft.eye.embeds()` for a normal element query.
4. **Server-side autoembed** — optional: bare URLs on their own line in a rich-text field become
   embeds on save.

## Front end

`eye.js` — a zero-dependency ES module, registered only when an embed renders:

- lazy loading (native `loading="lazy"` plus an IntersectionObserver for `click`/`auto` work)
- click-to-load consent cards, with per-provider consent remembered in `localStorage` when asked
- auto height: same-origin DOM read with a `ResizeObserver`, or the `postMessage` protocol
- scroll the frame's top back into view when in-frame navigation makes it grow
- a load-failure fallback card (the frame that never fires `load`)
- `window.Eye` for programmatic control

`eye-child.js` — ~1 KB, served from the plugin, for the remote page to include when you control
both ends. Posts height on `ResizeObserver` and answers Eye's handshake.

## Build order

1. skeleton, settings, options model
2. providers
3. fetcher (SSRF), framability probe, proxy + extraction
4. element, record, query, install migration, embeds service
5. renderer + templates
6. front-end runtime + child script
7. controllers (CP + site proxy route)
8. field type
9. CKEditor / Redactor / purifier / autoembed
10. Twig
11. CP screens
12. console commands, GC, translations
13. integration checks, README, CHANGELOG
