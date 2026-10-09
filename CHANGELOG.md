# Changelog

All notable changes to Eye are documented here.

## Unreleased

> {warning} Consent cards no longer hotlink the provider's poster. Choose a **Poster volume** under
> **Settings → Plugins → Eye → Posters** so Eye can download a copy there. Until you do, cards show
> no poster. To keep the old behaviour, set **Posters** to **Hotlinked**.

### Added

- Consent-manager integration. A click-to-load card opens by itself when the reader has granted the
  embed's consent category, and goes back when they withdraw it. Eye defers to Toss, through its
  consent API, when Toss's consent kit is on. Otherwise it reads Cookiebot, CookieYes, Klaro or
  Google Consent Mode.
- `Eye.setConsent(category, granted)` and `Eye.consent(category)`, so any other consent manager can
  be connected. Also `Eye.unload(el)`.
- A **Consent category** on every embed (`marketing`, `analytics`, `preferences` or `necessary`),
  with provider defaults. Reference tags and Embed fields can't change it.
- **Hold every third-party embed for consent**: renders every cross-origin or proxied embed as
  click-to-load.
- Self-hosted posters. Eye downloads each poster once into an asset volume, using a queue job when
  an embed is saved or when a card first renders, and the card shows that copy. Downloads go
  through the SSRF-guarded fetcher. They are limited to the provider registry's poster hosts
  (plus the proxy's allowlist while the proxy is on) and to raster images up to 5 MB. SVGs are
  refused.
- New settings: **Posters** (self-hosted, hotlinked or none), **Poster volume**, **Poster folder**
  and **Poster transform**.
- `php craft eye/embeds/download-posters [--force]`.
- The embed edit screen says where the poster comes from, and why when it isn't shown.

### Changed

- The click-to-load card shows no poster until a self-hosted copy exists. It used to hotlink the
  provider's image.

## 5.0.0 — 2026-08-18

Initial release. Versioned 5.x to match the Craft major it targets, as the rest of this plugin
family is.

### Security

Hardened before release, after a security review:

- Fallback markup is purified whoever wrote it, and only `http(s)` URLs render — from the library,
  an Embed field or `craft.eye.url()`.
- Reference tags may only change presentational options, and cannot switch a framed embed to proxy
  or inline. The Embed field only accepts the options it is configured to show.
- Widths, poster URLs, root margins, class names and ids are checked before they reach a `style`
  attribute or CSS `url()`; injected CSS cannot close its `<style>` or `@import` a stylesheet.
- Proxied pages are served in a CSP sandbox: with scripts stripped they keep the site's origin but
  may run nothing; with scripts kept they run on an opaque origin and report their height through
  the injected child script. Stripping also removes `srcdoc`, plugin elements and
  `javascript:`/`vbscript:`/`data:` URLs from every attribute.
- The public proxy route is rate limited per address and caches for at least 60 seconds outside
  `devMode`; stored embeds' proxy URLs are versioned so a changed embed is not served stale.
- Outbound fetches always go through curl, so the connection is pinned to the address that was
  validated. Guzzle's default stack could hand a request to PHP's stream wrapper, which ignores the
  pin and resolves the host again. A configured outbound proxy is never used, and the size cap is
  enforced as the bytes arrive.
- The rate limit charges the connecting address unless the site has configured `trustedHosts`, so
  a forged `X-Forwarded-For` no longer buys a fresh budget. IPv6 addresses are charged per /64, and
  there is a site-wide cap as well.
- Framing checks and previews are rate limited for users without the manage permission, and
  previewing a proxy or inline embed needs that permission.
- A JSON string posted to an Embed field is read as a URL, so it can't carry options the field
  doesn't allow.
- CSS injected into an inline embed may not contain at-rules or escapes, so no selector escapes
  its scope. An at-rule whose name contains an escape is removed everywhere.
- Signed proxy payloads use a key derived for Eye alone, so nothing else Craft signs is accepted.

### Fixed

- Opening an existing embed for editing no longer fails with a Twig error.
- CodePen team pens (`codepen.io/team/…/pen/…`) are recognised as CodePen.
- A page sending the non-standard `X-Frame-Options: ALLOWALL` (Calendly does) is reported as
  embeddable rather than "not understood".
- The Embed field's URL lookup, framing check and preview now work for authors with no access to
  the embed library.

### Added

- **Embed element** with reference-tag rendering (`{eye:handle:render}`), so an embed renders in
  CKEditor, Redactor and any other HTML field with no template changes.
- **Provider registry** covering 27 services — YouTube, Vimeo, Loom, Wistia, Dailymotion, TikTok,
  Instagram, X, Spotify, SoundCloud, Apple Podcasts, Apple Music, Google Maps, OpenStreetMap,
  Google Docs/Sheets/Slides/Forms, Google Calendar, Calendly, Typeform, Airtable, Figma, Miro,
  Canva, CodePen, JSFiddle, CodeSandbox, Descript and PDFs — each with its real embed URL, natural
  aspect ratio, required `allow` tokens and privacy-friendly host.
- **Five render modes**: aspect ratio, fixed, auto height, proxy and inline.
- **Auto height** by same-origin DOM reading with a `ResizeObserver`, or by a `postMessage`
  handshake with the bundled child script (`/eye/child.js`) for pages you control.
- **Click-to-load consent cards** with poster images, per-provider memory, and the iframe parked in
  a `<template>` so nothing is requested until the reader asks.
- **Proxy and inline modes** with CSS-selector extraction and removal, base-URL rewriting, CSS
  injection, link retargeting, script stripping and TTL caching.
- **Framability checks** reading `X-Frame-Options` and `Content-Security-Policy: frame-ancestors`,
  surfaced on the edit screen, in the element index, and as a console command with a CI exit code.
- **SSRF-hardened fetcher**: scheme and host allowlists, public-address validation of every
  resolved IP, connection pinning with `CURLOPT_RESOLVE`, hand-followed and re-validated redirects,
  size/time/content-type caps, and no forwarding of anything belonging to the reader.
- **CKEditor 5 and Redactor integrations** sharing one picker, plus paste-a-URL auto-embedding.
- **Two field types**: `Embed` (inline configuration) and `Embeds` (a relation to the library).
- **Twig API**: `craft.eye.*`, plus an `eye` filter and function that accept a URL, a handle, an
  element or a field value.
- **Zero-dependency front-end runtime** handling lazy and click loading, auto height, scroll-to-top
  on in-frame navigation, and a fallback card when a frame never arrives.
- Optional server-side auto-embedding of bare URLs on save, for content arriving from imports,
  feeds or the Element API.
- Console commands, permissions, an overridable render template (`_eye/embed.twig`), and 114
  integration checks.
