---
title: Configuration
slug: configuration
order: 40
summary: Every setting and its default, config/eye.php, embed options, permissions and console commands.
---

## Settings

**Settings → Plugins → Eye**, admins only. The presentation settings are safe for anyone who
administers the site; the proxy section is a separate decision and the screen says so.

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Register stylesheet | `registerCss` | `true` | Loads Eye's small stylesheet when an embed renders. Off to style embeds entirely yourself |
| Register runtime | `registerJs` | `true` | Loads the front-end script. Without it, click-to-load, auto height and the load timeout stop working; ratio and fixed embeds are unaffected |
| — | `defaultOptions` | `[]` | [Embed options](#embed-options) every new embed starts from. Config file only |
| Privacy mode | `privacyMode` | `true` | Uses a provider's cookie-less option where it has one — `youtube-nocookie.com`, Vimeo's `dnt=1` |
| Auto-embed URLs on save | `autoembed` | `false` | Turns a bare URL on its own line in a rich-text field into an embed when the element is saved. For imports, feeds and the Element API; the editors already do this at paste time |
| Check whether URLs allow framing | `checkFramability` | `true` | Reads `X-Frame-Options` and `frame-ancestors` when an embed is saved |
| Framability cache duration | `framabilityCacheDuration` | `86400` | Seconds a verdict is kept. An *Unreachable* verdict is kept for 5 minutes at most |
| Enable the proxy | `proxyEnabled` | `false` | Lets proxy and inline modes fetch anything at all. See [Proxy & inline modes](proxy) |
| Allowed hosts | `allowedHosts` | `[]` | The only hosts Eye will fetch: `example.com`, or `*.example.com` for its subdomains and the apex |
| Strip scripts from proxied HTML | `proxyStripScripts` | `true` | The default for an embed's **Scripts** option |
| Proxy cache duration | `proxyCacheDuration` | `900` | Seconds a fetched page is reused. Embeds can override it. The proxy route never caches for less than 60 outside `devMode` |
| Proxy timeout | `proxyTimeout` | `10` | Seconds to wait for a page, 1–120 |
| Proxy response limit | `proxyMaxBytes` | `2097152` | The largest page Eye will read, in bytes (2 MB). Minimum 1024 |
| Proxy redirect limit | `proxyMaxRedirects` | `3` | Redirects to follow, 0–10. Every hop is re-checked |
| Proxy user agent | `proxyUserAgent` | `Mozilla/5.0 (compatible; CraftEye/5.0; +https://github.com/justinholtweb/craft-eye)` | How Eye identifies itself. Never the reader's |
| HTML Purifier config | `purifierConfig` | `null` | A file in `config/htmlpurifier/`, without `.json`, used to clean inline content. Its options are layered over Eye's own |

No setting is required. A blank allowlist with the proxy on is allowed, and does nothing.

## config/eye.php

Like any Craft plugin's settings, these can be set in a config file, which wins over the settings
screen. Create `config/eye.php`:

```php
<?php

use craft\helpers\App;

return [
    'defaultOptions' => [
        'loading' => 'click',
        'rememberConsent' => true,
    ],
    'proxyEnabled' => App::parseBooleanEnv('$EYE_PROXY') ?? false,
    'allowedHosts' => [
        'prices.supplier.example',
        '*.docs.example.com',
    ],
];
```

This is also the only place to set `defaultOptions`. When an embed is made from a recognised URL,
the provider's own mode, ratio, `allow` tokens and consent text still apply on top of them.

## Embed options

The same options are used by library embeds, Embed field values, reference tags, Twig and
`defaultOptions`. A reference tag may set only the ones marked **Tag**.

| Key | Default | | Notes |
|---|---|---|---|
| `mode` | `ratio` | Tag | `ratio`, `fixed`, `auto`, `proxy`, `inline`. A tag cannot switch to `proxy` or `inline` |
| `ratio` | `16:9` | Tag | `w:h`; `16x9`, `16/9` and `1.7778` also work |
| `height` | `480` | Tag | Pixels, for `fixed` |
| `minHeight` | `240` | Tag | Starting height for `auto` and `proxy` |
| `maxHeight` | `0` | Tag | Ceiling for auto height, past which the frame scrolls. `0` is none |
| `width` | `100%` | Tag | One CSS length. A bare number means pixels |
| `align` | `center` | Tag | `left`, `center`, `right`, `wide`, `full` |
| `className` | | Tag | Extra classes on the wrapper |
| `id` | | | The wrapper's DOM id. Generated when blank |
| `caption` | | Tag | Shown under the frame |
| `title` | | Tag | The iframe's accessible name. Derived when blank |
| `loading` | `lazy` | Tag | `lazy`, `eager`, `click` |
| `rootMargin` | `200px` | | How far ahead of the viewport a lazy embed is prepared |
| `showLoader` | `true` | Tag | A spinner while the frame loads |
| `timeout` | `8000` | Tag | Milliseconds to wait for `load` before showing the fallback. `0` waits forever |
| `fallback` | | | Markup shown when the frame fails. Always purified. Blank gives a link card |
| `showFallbackLink` | `true` | Tag | Whether the fallback offers to open the URL |
| `consentTitle` | | Tag | Click-to-load card heading |
| `consentText` | | Tag | Click-to-load card text. Providers supply one |
| `consentButtonLabel` | | Tag | Click-to-load button |
| `posterUrl` | | | Image behind the consent card. `http(s)` only |
| `rememberConsent` | `false` | Tag | Remember the reader's choice in `localStorage` |
| `sandbox` | `null` | | Array of `allow-*` tokens. `null` omits the attribute; `[]` is the strictest sandbox |
| `allow` | `[]` | | Permissions-Policy features, such as `autoplay`, `fullscreen`, `clipboard-write` |
| `allowFullscreen` | `true` | | Adds `fullscreen` to `allow` |
| `referrerPolicy` | `strict-origin-when-cross-origin` | | Any standard referrer policy |
| `scrolling` | `auto` | | `auto`, `yes`, `no` |
| `scrollToTop` | `false` | Tag | Scroll back to the frame's top when in-frame navigation changes its height |
| `autoHeightSelector` | | | Measure this selector instead of the document. Same-origin only |
| `params` | `[]` | | Extra query parameters on the frame's `src` |
| `extract` | | | Proxy and inline: keep only what this selector matches |
| `remove` | | | Proxy and inline: drop what these selectors match |
| `injectCss` | | | Proxy and inline: CSS added to the page |
| `stripScripts` | `null` | | `null` uses `proxyStripScripts` |
| `linkTarget` | `blank` | | Proxy: `blank`, `self`, `parent` |
| `cacheDuration` | `null` | | Proxy and inline: seconds. `null` uses `proxyCacheDuration` |

Unknown `sandbox` and `allow` tokens are dropped rather than passed through: a typo'd token is
silently ignored by the browser, and you would never find out your sandbox had a hole in it.

## Permissions

- **View embeds** — the **Eye → Embeds** section
  - **Create and edit embeds** — saving, and creating an embed by pasting a URL in CKEditor
  - **Delete embeds**

## Console

```sh
php craft eye/embeds/list                          # the library, with framing status
php craft eye/embeds/check                         # re-probe embeds not checked in the last day
php craft eye/embeds/check --force                 # re-probe all of them
php craft eye/embeds/check --failOnProblem         # exit non-zero if any refuses framing or is unreachable
php craft eye/embeds/inspect <url>                 # what Eye makes of a URL; saves nothing
php craft eye/embeds/create <url>                  # add a URL to the library, print its tag
php craft eye/embeds/clear-caches                  # forget cached proxy pages and framing verdicts
```

`check` skips proxy and inline embeds: the browser never frames them, so a framing header says
nothing about whether they work.
