# Eye

**Iframes that behave.** A better embed experience for Craft CMS 5 content.

Four things go wrong with an iframe in a CMS, and Eye exists for all four:

1. **It's the wrong size.** Fixed heights that don't fit, video that doesn't stay 16:9, a frame
   with its own scrollbar in the middle of your page.
2. **It loads too early.** A third-party frame on a page nobody scrolled to is still a request,
   still a cookie, still 400 KB of someone else's JavaScript.
3. **It hands your reader to a third party before they asked.** Usually without telling them.
4. **Sometimes the other site refuses to be framed at all** — and says so only to the browser
   console, leaving a blank rectangle and an author who thinks the CMS is broken.

Free, no editions, no licence key.

```sh
composer require justinholtweb/craft-eye
php craft plugin/install eye
```

Craft 5.3+, PHP 8.2+. No build step; no runtime dependencies beyond Craft's own.

---

## How it works

Build an embed once in **Eye → Embeds**, then use it anywhere:

```twig
{{ craft.eye.render('promo-video') }}
```

…or in any rich-text field, with a reference tag the CKEditor and Redactor buttons write for you:

```
{eye:promo-video:render}
```

Reference tags are the load-bearing idea. Craft parses them over every rich-text value on its
own, so an embed renders inside CKEditor content, Redactor content, or a plain HTML field with no
template changes and no per-editor rendering code. **The editor buttons are editing affordances
only** — remove them, switch editors, or paste content between fields and the embeds keep working.

### Paste a URL

Eye recognises 27 services and knows what each one needs — the real embed URL, the natural aspect
ratio, the `allow` tokens, and the cookie-less host where there is one:

> YouTube · Vimeo · Loom · Wistia · Dailymotion · TikTok · Instagram · X · Spotify · SoundCloud ·
> Apple Podcasts · Apple Music · Google Maps · OpenStreetMap · Google Docs/Sheets/Slides/Forms ·
> Google Calendar · Calendly · Typeform · Airtable · Figma · Miro · Canva · CodePen · JSFiddle ·
> CodeSandbox · Descript · PDF

Anything it doesn't recognise is framed as-is. Pasting a URL on its own line in CKEditor turns it
into a library embed, and so does the picker. `craft.eye.url()` renders a URL with the same
provider rules but doesn't create a library entry.

A useful side effect: because a pasted URL becomes a library element, **every third-party frame on
your site ends up in one index**, where you can audit it, switch it off, or move it behind a
consent card.

---

## The five modes

An embed's mode decides how the frame gets its height, which is the whole problem.

| Mode | What it does | Height comes from |
| --- | --- | --- |
| **Aspect ratio** | Responsive box that keeps its shape. The default. | CSS `aspect-ratio` |
| **Fixed** | One explicit height. | you |
| **Auto** | The frame reports its own height. | same-origin DOM read, or a `postMessage` handshake |
| **Proxy** | Eye fetches the page server-side and serves it from **your** domain. | same-origin DOM read, or the child script Eye injects when the page keeps its scripts |
| **Inline** | Eye fetches the page and puts the content straight in your page. No iframe at all. | it's just content |

### Auto height across origins

A browser will not let your page measure a frame from another origin. If you own both ends,
include Eye's child script on the framed page:

```html
<script src="https://your-site.example/eye/child.js" defer></script>
```

It's about 1 KB, reads nothing, stores nothing, and sends nothing but a number.

### Proxy mode

Proxy mode fetches the page on the server and serves it from your own origin. That answers
`X-Frame-Options`, and it's the only way content extraction, CSS injection and reliable auto
height work on a site you don't control.

```twig
{{ craft.eye.render('supplier-price-list') }}
```

With **Keep only** set to `#price-table`, the reader gets the supplier's table and none of their
navigation, cookie banner or footer.

**It's off by default, and it needs an explicit host allowlist.** See [Security](#security).

A proxied page is served in a sandbox, set by its `Content-Security-Policy` header:

- **Scripts stripped** (the default): the page keeps your origin, so the parent measures it
  directly, but nothing in it may run — not its own scripts, not a `javascript:` link, not a
  `srcdoc` frame.
- **Scripts kept**: the page may run its scripts, but on an opaque origin, so they cannot read your
  cookies or act as whoever is viewing. Eye injects its child script, and the height arrives by
  `postMessage`. Anything on the page that needs cookies, storage or a service worker won't work
  in this mode; **Measure this element instead** is ignored, because the parent can no longer see inside.

Other plugins that add scripts to every HTML response (analytics, PWA and so on) add them to
proxied pages too. With scripts stripped the browser blocks them, and logs a "Blocked script
execution … the document's frame is sandboxed" line for each; that line is the sandbox working.

Proxied pages are cached for at least 60 seconds outside `devMode`, and each address may request
120 a minute.

---

## Loading and consent

- **Lazy** (default) — native `loading="lazy"`.
- **Eager** — immediately.
- **On click** — nothing is requested until the reader asks. Eye renders a consent card with the
  provider's name, a poster image where one exists, and a button.

The click-to-load card is the honest kind: the iframe lives inside a `<template>` element, so it
genuinely has not been requested. (A `hidden` iframe still loads — that's the mistake most consent
banners make.) There's a `<noscript>` link too, and the reader's choice can be remembered for
each host the embed loads from.

```js
Eye.forgetConsent(); // for a "privacy settings" link
```

**Consent managers.** A card can open by itself when the reader has already agreed in the site's
consent manager, and close again when they withdraw. Eye defers to [Toss](https://github.com/justinholtweb/craft-toss)
when its consent kit is on. Otherwise it reads Cookiebot, CookieYes, Klaro or Google Consent Mode.
For anything else, `Eye.setConsent('marketing', true)` does the same job. Each embed waits for one
category (`marketing` unless its provider or the embed says otherwise). An optional setting holds
*every* third-party embed behind a card.

**Self-hosted posters.** A card that hotlinks YouTube's thumbnail has already sent the reader's IP
address to YouTube. By default Eye downloads each poster once, into an asset volume you choose, and
the card shows that copy. Until the copy exists, the card has no poster. Downloads come only from
the providers' image hosts and go through the proxy's fetcher, with all of its rules, and only
raster images are accepted. Details are in `docs/consent.md`.

---

## Knowing when an embed won't work

A page that refuses to be framed doesn't tell the framing page so. The browser blocks it, logs a
line nobody reads, and leaves a blank rectangle.

Eye reads the target's `X-Frame-Options` and `Content-Security-Policy: frame-ancestors` when you
save an embed, and tells you in plain language:

> **Refuses framing** — This page sets frame-ancestors 'none', so no site may embed it.
> *Use proxy mode to serve this page from your own domain, or link to it instead of framing it.*

The index gets a status column, and there's a command for CI:

```sh
php craft eye/embeds/check --failOnProblem
```

It probes the URL that actually goes in the frame, not the one you pasted — YouTube's *watch* page
sends `X-Frame-Options: SAMEORIGIN` while its `/embed/` URL is built to be framed.

**What Eye cannot do:** detect a blocked frame at runtime. A cross-origin page blocked by
`X-Frame-Options` still fires `load` in most browsers and gives the parent page no signal at all.
That's why the check happens at save time. The per-embed timeout catches slow and dead frames, not
blocked ones.

---

## Security

Proxy and inline modes give content authors a server-side HTTP client, so they are **off by
default** and gated by an allowlist with no "allow everything" value.

Every fetch goes through one class, and all nine rules hold:

1. `http` and `https` only.
2. The host must be on the allowlist (`example.com`, or `*.example.com` which also covers the apex).
3. Every address the host resolves to must be public — *all* of them, not one of them. A host
   answering with one public and one private address is a rebinding attempt.
4. The connection is **pinned** to a validated address with `CURLOPT_RESOLVE`, closing the window
   between the check and the connect.
5. Redirects are followed by hand, capped, and re-validated at every hop.
6. Responses are capped by size, by time, and by content type.
7. Nothing of the reader's is forwarded — no cookies, no auth, no client IP, no referer.
8. Only Eye's own user agent goes out.
9. **The public route never takes a URL.** A stored embed travels as its uid and the URL is read
   from the database; a one-off travels as a payload signed with the site's security key. There is
   no third way in, which is what stops it being an open proxy wearing your server's IP address.

Refused address ranges include loopback, RFC1918, link-local (so **not** the cloud metadata
endpoint), carrier-grade NAT, the documentation ranges, multicast, IPv6 unique-local, 6to4, NAT64,
and IPv4-mapped IPv6 — `::ffff:127.0.0.1` reaches loopback and passes every IPv4 test, because it
is not an IPv4 address.

Inline mode splices remote HTML into your own document, so it always goes through HTML Purifier,
scripts are stripped by default, and injected CSS is scoped to the embed.

### What authors can and can't change

- **Fallback markup is always purified.** It's printed raw, so it goes through HTML Purifier
  whoever wrote it.
- **Only `http` and `https` URLs render**, from the library, an Embed field or `craft.eye.url()`.
  Anything else renders nothing.
- **Reference tags only change presentation.** Anyone who can write rich text can write one, so a
  tag may set the mode, size, alignment, loading, caption, title and consent text — never fallback
  markup, CSS, the sandbox, `allow` tokens, frame parameters or proxy rules — and it cannot switch
  a framed embed to proxy or inline.
- **The Embed field takes only the options you enabled for it.** The rest keep their defaults,
  even if somebody posts them.
- **Values that end up in CSS are checked**: a width must be a CSS length, a poster must be an
  `http(s)` URL, and injected CSS cannot close its `<style>` element or `@import` anything.

Proxied pages are served with `X-Frame-Options: SAMEORIGIN` and `frame-ancestors 'self'`, so
nobody else can point a frame at your proxy.

---

## Template API

```twig
{# From the library, by handle or id #}
{{ craft.eye.render('promo-video') }}
{{ craft.eye.render('promo-video', { loading: 'click', ratio: '4:3' }) }}

{# Any URL, with everything Eye knows applied #}
{{ craft.eye.url('https://youtu.be/dQw4w9WgXcQ') }}

{# The filter and function take a URL, a handle, an element or a field value #}
{{ 'promo-video'|eye }}
{{ entry.video|eye({ align: 'full' }) }}
{{ eye('https://vimeo.com/76979871') }}

{# The element itself #}
{% set embed = craft.eye.embed('promo-video') %}
{{ embed.title }} — {{ embed.providerName }} — {{ embed.embedUrl }}

{# A normal element query #}
{% for embed in craft.eye.all({ provider: 'youtube' }).all() %}…{% endfor %}

{# Inspect without rendering #}
{% set match = craft.eye.match('https://youtu.be/x') %}
{% set framing = craft.eye.framability('https://example.com') %}
```

Reference tags can carry per-embed options:

```
{eye:promo-video:render(click,height=600)}
{eye:map:render(full,no-showLoader)}
```

Only presentational ones — see [What authors can and can't change](#what-authors-can-and-cant-change).

### Overriding the markup

Put your own template at `_eye/embed.twig` and it wins, with the same variables Eye's own template
gets. The CSS hooks are `.eye`, `.eye-stage`, `.eye-consent`, `.eye-fallback` and `.eye-caption`,
and the colours are custom properties:

```css
.eye { --eye-accent: #b91c1c; --eye-radius: 0; --eye-bg: #111; }
```

---

## Fields

- **Embed** — paste a URL and configure it on the entry. You choose which options authors see, so
  a video field can be a URL box and nothing else; options you didn't enable can't be posted
  either. Authors need no access to the embed library to use it.
- **Embeds** — an ordinary relation field pointing at the library, with eager loading and
  everything else relation fields do.

```twig
{{ entry.video }}                      {# renders #}
{{ entry.video.url }}
{{ entry.video.render({ loading: 'click' }) }}
```

---

## Console

```sh
php craft eye/embeds/list                          # the library, with framing status
php craft eye/embeds/check [--force] [--failOnProblem]
php craft eye/embeds/inspect <url>                 # what Eye makes of a URL
php craft eye/embeds/create <url>
php craft eye/embeds/clear-caches
php craft eye/embeds/download-posters [--force]    # self-host every embed's poster
```

---

## Settings

Presentation settings (stylesheet, runtime, privacy mode, auto-embed) are safe for anyone who
administers the site. The proxy section is a separate decision and is documented as such on the
screen.

The consent section chooses the consent manager and whether every third-party embed is held. The
posters section chooses self-hosted, hotlinked or no posters, and the volume, folder and transform
used for them.

`privacyMode` is on by default: providers use their cookie-less option where they have one
(`youtube-nocookie.com`, Vimeo's `dnt=1`).

---

## Testing

Integration checks live in `tests/integration/checks.php` (140 checks),
`tests/integration/trust.php` (10, a limited CP user and the public proxy route over HTTP) and
`tests/integration/privacy.php` (45, consent categories and managers, poster fetching and
self-hosting). All three are idempotent and self-cleaning. Run them from the site root:

```sh
ddev exec php /var/www/craft-eye/tests/integration/checks.php
ddev exec php /var/www/craft-eye/tests/integration/trust.php
ddev exec php /var/www/craft-eye/tests/integration/privacy.php
node --test tests/js/runtime.test.mjs    # the runtime's consent-manager adapters, no dependencies
```

`tests/manual/` holds two scripts that need the internet: `live-probe.php` exercises the fetcher
against real hosts, and `seed-demo.php` creates demo embeds.

---

## Licence

Proprietary. See `LICENSE.md`.
