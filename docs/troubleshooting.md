---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: Blank frames, framing verdicts, proxy errors, stale pages, and what to check first.
---

## A blank rectangle where the embed should be

Almost always the other site refusing to be framed. Check the embed's status in **Eye → Embeds**,
or run `php craft eye/embeds/check --force`. **Refuses framing** means `X-Frame-Options` or
`frame-ancestors` forbids every other site; the fix is proxy mode, if the host is one you can
allow, or a link instead of a frame.

Eye cannot detect this in the browser. A cross-origin page blocked by `X-Frame-Options` still
fires `load` in most browsers and gives the parent page no signal at all, which is why the check
happens at save time. The **Give up after** timeout catches slow and dead frames, not blocked ones,
so a blocked frame does not get the fallback card.

If the status is **Embeddable** and the frame is still blank, open the browser console. Third-party
cookie blocking, a content blocker, or your own site's CSP `frame-src` are the usual causes.

## A YouTube link says "Refuses framing"

It shouldn't: Eye probes the URL that goes in the frame, not the one you pasted, and YouTube's
`/embed/` URL is built to be framed even though its *watch* page sends
`X-Frame-Options: SAMEORIGIN`. If you see it, Eye didn't recognise the URL and is framing the page
as-is. Run `php craft eye/embeds/inspect <url>` — if the provider comes back as *Web page*, paste
the share link or the embed URL instead.

## "Not from this site" locally, fine in production

`frame-ancestors` can name specific origins. Eye judges it against the current site's origin, so a
service that allows `https://www.example.com` will be **Not from this site** on
`https://example.ddev.site`. Run the check again on production before acting on it.

## "Unreachable" for a URL that opens fine in a browser

The framing check obeys the same address rules as the proxy, so an intranet host, `localhost`, or
anything that resolves to a private address is refused before it is asked. The reader's browser
may frame it perfectly well; the verdict is about what Eye was allowed to check. An *Unreachable*
verdict is only cached for five minutes, so a site that was briefly down recovers on its own.

## A proxy or inline embed won't save

The error says which:

1. **"Proxy and inline modes need Eye's proxy turned on"** — **Settings → Plugins → Eye → Enable
   the proxy**.
2. **"…only work for hosts on Eye's allowed list"** — add the named host to **Allowed hosts**, or
   `*.` the domain to cover its subdomains too.

If `config/eye.php` sets either, it wins over the settings screen.

## A proxied frame says "This content could not be loaded"

The fetch failed. With `devMode` on, the reason is printed underneath; otherwise it's in the logs
under the `eye` category. The common ones:

- **does not resolve to a public address** — you are proxying a local or internal site, which Eye
  will never do. On a DDEV or Docker setup, that includes your own dev domain.
- **will not fetch from port …** — only 80, 443, 8080 and 8443.
- **Eye only proxies HTML** — the URL returns a PDF, an image or JSON. Frame it normally.
- **is larger than Eye's … limit** — raise **Proxy response limit**, or use **Keep only** on a
  smaller page.
- **Too many redirects** — raise **Proxy redirect limit**, or point the embed at the final URL.
- **answered 403** (or another status) — the remote site turned Eye away. Some sites block
  unknown user agents; **Proxy user agent** is configurable.

## The console is full of "Blocked script execution"

On a proxied page with scripts stripped, that line is the sandbox working. Other plugins that add
scripts to every HTML response — analytics, PWA, consent banners — add them to proxied pages too,
and the browser refuses each one. Nothing to fix.

## Readers see an old version of a proxied page

Fetched pages are cached on the server for **Proxy cache duration** (15 minutes by default) and in
the reader's browser for the same time. Changing how the embed shapes the page — **Keep only**,
**Remove**, **Inject CSS**, **Scripts**, links, cache duration — gives it a new frame URL, so that
part is immediate. A change on the remote site, or to the embed's URL, waits for the cache:

```sh
php craft eye/embeds/clear-caches
```

The browser's copy still runs out on its own; a shorter **Cache duration** on that embed shortens
it next time.

## 429 Too Many Requests from /eye/proxy

The proxy route allows 120 requests a minute per IP address. A page with a handful of proxied
embeds never gets close; a load test, a crawler, or many readers behind one office NAT can. If
that is your normal traffic, an inline embed renders server-side and doesn't touch the route.

## Auto height doesn't grow

- **Cross-origin `auto`**: the framed page must include `/eye/child.js`. Without it, the browser
  won't let your page measure the frame and it stays at `minHeight`.
- **`maxHeight`** caps the frame and scrolls the rest — check it isn't set.
- **Proxy with scripts kept**: the height arrives from the injected child script, and **Measure
  this element instead** is ignored.
- **Register runtime** must be on.

## Click-to-load, the spinner or auto height do nothing

The runtime isn't on the page. Check **Register runtime**. If the embed's markup arrives some other
way than a normal page render — an Element API response, HTML fetched by your own JavaScript — the
page that shows it needs Eye's assets registered in its layout:

```twig
{% do view.registerAssetBundle('justinholtweb\\eye\\web\\assets\\runtime\\RuntimeAsset') %}
```

The runtime picks up embeds added to the page later by itself.

## Pasting a URL in CKEditor leaves plain text

Paste-to-embed needs the **Create and edit embeds** permission, a bare URL with nothing else, and
an empty line. If creating the embed fails, Eye pastes the text rather than lose it.

## No Embed button in CKEditor

Drag **Embed** into the toolbar of the CKEditor config under **Settings → CKEditor** — CKEditor
doesn't add new items by itself. On older, pre-import-map versions of the CKEditor plugin, Eye adds
no button at all; update CKEditor.

## A reference tag shows as text

Craft leaves a tag it can't resolve in place. Usually:

- The handle was renamed, or never existed.
- The embed is in the trash. Its handle is parked as `handle--trashed-<id>` so a new embed can take
  the name; restoring it claims the handle back.
- The tag has a space in it. Craft's tag syntax allows none.

A **disabled** embed is different: it renders nothing, with no tag left behind.

## Signed proxy links stopped working

Proxied Embed field values and `craft.eye.url()` calls are signed with your security key. After
changing `CRAFT_SECURITY_KEY`, the next render produces new links. Pages served from a static cache
keep the old ones until that cache is cleared.

## Getting help

Email [justin@justinholt.com](mailto:justin@justinholt.com) with the output of
`php craft eye/embeds/inspect <url>` for the embed in question, and the `eye` lines from your logs.
