# Changelog

All notable changes to Eye are documented here.

## 5.0.0 — 2026-08-18

Initial release. Versioned 5.x to match the Craft major it targets, as the rest of this plugin
family is.

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
