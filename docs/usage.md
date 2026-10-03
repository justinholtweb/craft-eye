---
title: Usage
slug: usage
order: 20
summary: The library, reference tags, providers, the five modes, consent, fields, Twig and markup.
---

## The library

Every embed lives in **Eye → Embeds**, as an element with a title, a URL, a handle and its
options. Build one once and use it anywhere.

The library is also the audit trail. The index has a framing-status column, and switching an
embed off takes it off every page that uses it at once — a disabled embed renders nothing,
wherever it is referenced.

## Reference tags

Each embed has a tag. The editor buttons write it for you; you can also type it:

```
{eye:promo-video:render}
```

A tag can carry per-use options in brackets. A bare word is a mode, a loading strategy, an
alignment, a ratio or a flag; `no-` switches a flag off; anything else is `key=value`:

```
{eye:promo-video:render(click,height=600)}
{eye:map:render(full,no-showLoader)}
{eye:slides:render(4:3,width=640px)}
```

Craft's tag syntax allows no spaces, so a tag with one is left in the page as text. Longer text —
a caption, consent wording — belongs on the library embed or in Twig.

Tags only change presentation. Anyone who can write rich text can write one, so a tag may set
`mode`, `ratio`, `height`, `minHeight`, `maxHeight`, `width`, `align`, `className`, `caption`,
`title`, `loading`, `timeout`, `showLoader`, `showFallbackLink`, `consentTitle`, `consentText`,
`consentButtonLabel`, `rememberConsent` and `scrollToTop` — and nothing else. Other keys are
ignored, and a tag cannot switch a framed embed to proxy or inline.

If a handle doesn't exist, Craft leaves the tag in the page as text, so a typo is visible rather
than silently missing. Renaming an embed's handle breaks the tags that use the old one.

## Paste a URL

Eye recognises 27 services and knows what each one needs — the real embed URL, the natural aspect
ratio, the `allow` tokens, and the cookie-less host where there is one:

> YouTube · Vimeo · Loom · Wistia · Dailymotion · TikTok · Instagram · X · Spotify · SoundCloud ·
> Apple Podcasts · Apple Music · Google Maps · OpenStreetMap · Google Docs/Sheets/Slides/Forms ·
> Google Calendar · Calendly · Typeform · Airtable · Figma · Miro · Canva · CodePen · JSFiddle ·
> CodeSandbox · Descript · PDF

Anything else is framed as-is. Recognition is pattern matching on the URL — no oEmbed call, no
request to the provider.

Pasting a URL creates a library embed, in the editor, in the picker, or on save with
**Auto-embed URLs on save** turned on. That's deliberate: a reference tag needs an element, and it
means every third-party frame on your site ends up in one index. Pastes are matched by URL, so
pasting the same video ten times makes one embed.

To see what Eye makes of a URL without saving anything:

```sh
php craft eye/embeds/inspect https://youtu.be/dQw4w9WgXcQ
```

## The five modes

An embed's mode decides how the frame gets its height, which is the whole problem.

| Mode | What it does | Height comes from |
|---|---|---|
| **Aspect ratio** | A responsive box that keeps its shape. The default. | CSS `aspect-ratio` |
| **Fixed** | One explicit height, in pixels. | you |
| **Auto** | The frame reports its own height. | a same-origin DOM read, or the child script |
| **Proxy** | Eye fetches the page server-side and serves it from your domain. | a same-origin DOM read, or the child script Eye injects |
| **Inline** | Eye fetches the page and puts the content straight into yours. No iframe. | it's just content |

Ratio takes anything `w:h` — `16:9`, `4:3`, `16x9` and `1.7778` all work. Auto and proxy frames
start at `minHeight` (240px) and grow; set `maxHeight` in a tag or in Twig to make a tall page
scroll inside the frame instead. **Measure this element instead** measures one selector rather
than the whole document, where the parent can read the DOM.

Proxy and inline are off until an admin turns them on. See [Proxy & inline modes](proxy).

## Auto height on sites you control

A browser will not let your page measure a frame from another origin. If you own both ends,
include Eye's child script on the framed page, then set the embed's mode to **Auto**:

```html
<script src="https://your-site.example/eye/child.js" defer></script>
```

It's about 1 KB, reads nothing, stores nothing, and sends nothing but a number. The URL is stable
across deploys, so it is safe to paste into another site's templates.

## Loading and consent

- **Lazy** (default) — native `loading="lazy"`, prepared 200px before it scrolls into view.
- **Eager** — immediately.
- **On click** — nothing is requested until the reader asks.

On click renders a consent card: the provider's name, a line of text, a poster image where there
is one (YouTube), and a button. The heading, text and button label are all editable. The iframe
sits inside a `<template>` element, so it genuinely has not been requested — a `hidden` iframe
still loads, which is the mistake most consent banners make. There is a `<noscript>` link too.

**Remember the reader's choice** stores consent in `localStorage` per embed host, so one click
loads every later embed from the same place. For a "privacy settings" link:

```js
Eye.forgetConsent();
```

**Privacy mode** (on by default) uses a provider's cookie-less option where it has one —
`youtube-nocookie.com`, Vimeo's `dnt=1`.

## Knowing when an embed won't work

A page that refuses to be framed doesn't tell the framing page. The browser blocks it, logs a line
nobody reads, and leaves a blank rectangle.

So Eye reads the target's `X-Frame-Options` and `Content-Security-Policy: frame-ancestors` when you
save, and the index shows the result: **Embeddable**, **Refuses framing**, **Not from this site**
(it allows other origins, not yours), **Unreachable** or **Not checked**. It probes the URL that
actually goes in the frame, not the one you pasted.

For CI, or a weekly cron:

```sh
php craft eye/embeds/check --failOnProblem
```

**Give up after** (8 seconds by default) shows the fallback when a frame never finishes loading.
It catches slow and dead frames, not blocked ones — see
[Troubleshooting](troubleshooting#a-blank-rectangle-where-the-embed-should-be).

## Fields

- **Embed** — paste a URL and configure it on the entry. In the field settings you choose which
  options authors see: mode, aspect ratio, height, loading, caption, accessible title, alignment,
  consent text, extract selector. A video field can be a URL box and nothing else. Options you
  didn't enable can't be posted either; they keep the field's defaults. Authors need no access to
  the library.
- **Embeds** — an ordinary relation field pointing at the library, with eager loading and
  everything else relation fields do.

```twig
{{ entry.video }}                               {# renders #}
{{ entry.video.url }}
{{ entry.video.render({ loading: 'click' }) }}

{% for embed in entry.relatedEmbeds.all() %}
    {{ embed.render() }}
{% endfor %}
```

## Twig

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
{{ craft.eye.embedCode('promo-video') }}        {# the reference tag #}
{{ craft.eye.childScriptUrl() }}
```

Options passed in Twig can be any of the [embed options](configuration#embed-options), not just
the presentational ones a reference tag allows. An unknown handle renders nothing rather than an
error, and only `http` and `https` URLs render at all.

`craft.eye.framability()` is a network call. It is cached, but don't put it on a hot page.

## Overriding the markup

Put your own template at `templates/_eye/embed.twig` and it wins, with the same variables Eye's
own template gets — `options`, `src`, `classes`, `consent`, `fallback`, `provider`, `embed` and the
rest. Copy `vendor/justinholtweb/craft-eye/src/templates/_render/embed.twig` as a starting point;
keep the `<template data-eye-template>` around the click-to-load iframe, or the consent card stops
deferring anything.

The wrapper carries `.eye` plus `.eye--{mode}`, `.eye--{loading}`, `.eye--align-{align}` and
`.eye--provider-{handle}`. The other hooks are `.eye-stage`, `.eye-consent`, `.eye-fallback` and
`.eye-caption`. The colours are custom properties:

```css
.eye { --eye-accent: #b91c1c; --eye-radius: 0; --eye-bg: #111; }
```

Also available: `--eye-fg`, `--eye-muted`, `--eye-accent-fg`, `--eye-border`. Turn off **Register
stylesheet** to style embeds entirely from your own CSS.

## The front-end runtime

Eye's script is registered the first time an embed renders, and not at all on a page with none. It
picks up embeds added later — live preview, an AJAX tab, infinite scroll — on its own. By hand:

```js
Eye.init(container);   // initialise embeds under an element
Eye.load(el);          // load a click-to-load embed
Eye.refresh(el);       // re-measure an auto-height embed
Eye.forgetConsent();
```

`el` is the `.eye` wrapper.
