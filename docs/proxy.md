---
title: Proxy & inline modes
slug: proxy
order: 30
summary: Serving another site's page from your own domain, and the rules that keep that safe.
---

## What they do

Both modes fetch the page on your server instead of letting the reader's browser fetch it.

- **Proxy** serves the fetched page from your own domain, in a frame. That answers
  `X-Frame-Options`, and it is the only way content extraction, CSS injection and reliable auto
  height work on a site you don't control.
- **Inline** takes the fetched page's content and puts it straight into yours. No iframe at all.

```twig
{{ craft.eye.render('supplier-price-list') }}
```

With **Keep only** set to `#price-table`, the reader gets the supplier's table and none of their
navigation, cookie banner or footer.

## Turning them on

Proxy and inline modes give content authors a server-side HTTP client, so they are **off by
default** and the switch is on the settings screen, not on the embed. Two things are needed:

1. **Settings → Plugins → Eye → Enable the proxy.**
2. At least one host in **Allowed hosts**.

With the proxy on and the list empty, Eye fetches nothing and says so on the settings screen. An
embed set to proxy or inline won't save until both are in place and its host is on the list — the
error names the host to add. The Embed field only offers the two modes once the proxy is usable.

## The allowlist

One host per row:

| Entry | Matches |
|---|---|
| `example.com` | `example.com` only |
| `*.example.com` | any subdomain of `example.com`, and `example.com` itself |

There is no "allow everything" value, on purpose. The list is the only thing that decides where
your server can be sent.

## Shaping the page

On a proxy or inline embed:

| Option | Does |
|---|---|
| **Keep only** | Keeps what this CSS selector matches and drops the rest of the body. If it matches nothing, the whole page is kept. |
| **Remove** | Drops everything matching these selectors, comma-separated. |
| **Inject CSS** | Added to the proxied document; scoped to the embed when inline. |
| **Links inside the page** | Open in a new tab (default), stay inside the frame, or replace the whole page. |
| **Scripts** | Strip them, keep them, or use the plugin setting (strip, by default). |
| **Cache duration** | Overrides the plugin's proxy cache duration for this embed. |

Whatever you choose, Eye makes every URL in the page absolute against the remote site, removes
`<meta http-equiv="refresh">`, and adds a little CSS so images, video and tables can't overflow the
frame.

"Stay inside the frame" means a click loads the remote site's next page directly, not through the
proxy — and that page may refuse to be framed.

## The sandbox

A proxied page is served from your origin, so it is sandboxed by its `Content-Security-Policy`
header. Which sandbox depends on what happens to its scripts:

- **Scripts stripped** (the default): the page keeps your origin, so the parent measures it
  directly, but nothing in it may run — not its own scripts, not inline event handlers, not a
  `javascript:` link, not a `srcdoc` frame. `script-src 'none'` is set as well.
- **Scripts kept**: the page may run its scripts, but on an opaque origin, so they cannot read your
  cookies or act as whoever is viewing. Eye injects its child script, and the height arrives by
  `postMessage`. Anything on the page that needs cookies, storage or a service worker won't work
  in this mode, and **Measure this element instead** is ignored, because the parent can no longer
  see inside.

Every proxied response is also sent with `X-Frame-Options: SAMEORIGIN`,
`frame-ancestors 'self'` and `Referrer-Policy: no-referrer`, so nobody else can point a frame at
your proxy.

Other plugins that add scripts to every HTML response — analytics, PWA and so on — add them to
proxied pages too. With scripts stripped the browser blocks them and logs a "Blocked script
execution … the document's frame is sandboxed" line for each. That line is the sandbox working.

## Inline mode

Inline mode splices remote HTML into your own document, so it is held to a stricter standard:

- The fragment always goes through HTML Purifier, whatever the script setting says. Choose a
  config from `config/htmlpurifier/` with the **HTML Purifier config** setting.
- Injected CSS is scoped to the embed, so a `body { … }` copied from the remote site restyles the
  embed and not your page.
- The fetch happens while your page renders. A cache miss on a slow remote site is a slow page;
  a failure shows the embed's fallback, with the reason.

## Caching

Fetched pages are kept in Craft's data cache for **Proxy cache duration** — 900 seconds by default
— or the embed's own **Cache duration**. Pages served through the proxy route are cached for at
least 60 seconds outside `devMode` whatever you set, because with no cache every page view would be
a request to the remote site.

The browser caches them too, for the same duration. A library embed's frame URL carries a short
`eyev` hash of everything that shapes the response, so changing **Keep only**, **Scripts** and the
like gives readers a new URL rather than a stale page. A change on the *remote* site, or to the embed's URL,
shows up when the cache expires, or sooner if you clear it:

```sh
php craft eye/embeds/clear-caches
```

That clears library embeds. Proxied Embed field values and `craft.eye.url()` calls are cleared with
the rest of Craft's data caches.

## The public route and its rate limit

A proxy frame points at a route on your site. **The route never takes a URL**:

- A library embed travels as its uid — `/eye/proxy/<uid>` — and the URL is read from the database.
  Only an enabled embed that was saved in proxy or inline mode can be fetched this way, so a uid
  harvested from a page can't turn any other embed into a fetch.
- An Embed field value or a `craft.eye.url()` call travels as a payload signed with your site's
  security key, in the `eyeref` parameter. Change the key and old signed links stop working; pages
  regenerate them on their next render.

There is no third way in. That is what stops it being an open proxy wearing your server's IP
address.

Each IP address may make 120 requests a minute to the route. Past that it answers `429 Too Many
Requests` with `Retry-After: 60`. Inline embeds are fetched while your page renders and never hit
the route.

## The fetch rules

Every outbound request Eye makes goes through one class, and all nine rules hold:

1. `http` and `https` only.
2. The host must be on the allowlist.
3. Every address the host resolves to must be public — *all* of them, not one of them. A host
   answering with one public and one private address is a rebinding attempt.
4. The connection is **pinned** to a validated address with `CURLOPT_RESOLVE`, closing the window
   between the check and the connect.
5. Redirects are followed by hand, capped (**Proxy redirect limit**, 3), and re-validated at every
   hop against the allowlist and the address rules.
6. Responses are capped by size (2 MB), by time (10 seconds), and by content type (`text/html`,
   `application/xhtml+xml`, `text/plain`). Only a `200` is used.
7. Nothing of the reader's is forwarded — no cookies, no auth, no client IP, no referer.
8. Only Eye's own user agent goes out.
9. The public route never takes a URL.

Eye also refuses URLs with credentials in them (`https://user:pass@…`) and ports other than 80,
443, 8080 and 8443.

The framing check uses the same address rules, pinning and caps. It relaxes only the allowlist —
it exists to tell you about a URL before you commit to it — and it keeps the headers and discards
the body.

### Refused addresses

| IPv4 | |
|---|---|
| `0.0.0.0/8` | "this network" |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | private |
| `100.64.0.0/10` | carrier-grade NAT |
| `127.0.0.0/8` | loopback |
| `169.254.0.0/16` | link-local, including the cloud metadata endpoint |
| `192.0.0.0/24` | IETF protocol assignments |
| `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` | documentation |
| `192.88.99.0/24` | 6to4 relay |
| `198.18.0.0/15` | benchmarking |
| `224.0.0.0/4` | multicast |
| `240.0.0.0/4` | reserved, including broadcast |

| IPv6 | |
|---|---|
| `::`, `::1` | unspecified, loopback |
| `64:ff9b::/96` | NAT64 |
| `100::/64` | discard-only |
| `2001::/32` | Teredo |
| `2001:db8::/32` | documentation |
| `2002::/16` | 6to4 |
| `fc00::/7` | unique local |
| `fe80::/10` | link-local |
| `ff00::/8` | multicast |

IPv4-mapped and IPv4-compatible IPv6 addresses are unwrapped and the IPv4 address inside is checked
again. `::ffff:127.0.0.1` reaches loopback and passes every IPv4 test, because it is not an IPv4
address. Anything Eye can't parse is refused.

## What authors can and can't change

- **Only admins turn the proxy on and choose the hosts.** An author can pick proxy or inline mode
  only for a URL already on the list.
- **Reference tags only change presentation.** A tag cannot switch a framed embed to proxy or
  inline, and cannot set **Keep only**, **Remove**, CSS, scripts or caching.
- **The Embed field takes only the options you enabled for it.** Of the proxy options, that can
  include **Keep only**; everything else keeps the field's defaults, even if somebody posts it.
- **Injected CSS cannot close its `<style>` element or `@import` anything.**
- **Fallback markup is always purified**, whoever wrote it.
