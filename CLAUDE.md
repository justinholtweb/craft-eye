# Eye — Craft CMS 5 Plugin

## Project Overview

Eye is an iframe/embed plugin for Craft CMS 5 — a provider-aware embed library with responsive and
auto height, lazy and click-to-load, and a guarded server-side proxy for pages that refuse to be
framed. Distributed as `justinholtweb/craft-eye`. **Free — no editions, no licensing code.**

Reference point: the WordPress *Advanced iFrame* plugin. Same ground, done the Craft way — an
element with a reference tag rather than a shortcode.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step anywhere: the CP editor and the front-end runtime are plain scripts, and the
  CKEditor plugin is an ES module written against the `ckeditor5` import map.
- No runtime dependencies beyond Craft's own. `symfony/css-selector`, `symfony/dom-crawler`,
  Guzzle and HTML Purifier are all direct `craftcms/cms` requirements — verified, not assumed.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\eye`
- Package: `justinholtweb/craft-eye`
- Handle: `eye`

### The load-bearing idea: reference tags

Same as `[[project_craft_legs]]`. `craft\htmlfield\HtmlFieldData::__construct()` runs
`Elements::parseRefs()` over every rich-text value and splices the result in **raw**, so an
`Embed` element with `refHandle() = 'eye'` and a `getRender()` returning `Markup` renders
`{eye:handle:render}` in CKEditor, Redactor and plain HTML fields with no template changes and no
per-editor rendering code.

**The editor integrations are editing affordances only.** They write
`<div class="eye-embed" data-eye-handle="x">{eye:x:render}</div>`. Never make rendering depend on
an editor.

Consequence: pasting a URL in an editor **creates a library embed** and inserts its tag, because a
reference tag needs an element. That is deliberate — it means every third-party frame on the site
ends up in one auditable index — and quick-create dedupes by URL so ten pastes make one entry.

### The five modes

`ratio` (CSS aspect-ratio) · `fixed` · `auto` (same-origin DOM read, or postMessage with
`eye-child.js`) · `proxy` (fetched server-side, served from this origin, so auto height and CSS
injection always work) · `inline` (fetched, extracted, purified, spliced in with no iframe).

### Services

- `providers` — the URL registry; 27 services, each with patterns, a builder, ratio, `allow`
  tokens and a privacy host. Order matters; `generic` is last and matched by falling off the end.
- `embeds` — the library, and the authority on handles
- `renderer` — one path for every surface, rendering `eye/_render/embed` or a site's
  `_eye/embed.twig`
- `fetcher` — **the only thing in Eye that opens an outbound connection.** See below.
- `framability` — X-Frame-Options / frame-ancestors verdicts, cached
- `proxy` — fetch → extract → rewrite → purify → cache
- `proxyRoutes` — builds and verifies the signed URLs proxy mode points frames at

### Proxy safety

All of this has to hold, and it is all in `Fetcher` plus `helpers\Ip`:

scheme allowlist · host allowlist with no wildcard-everything · **every** resolved address must be
public · connection pinned with `CURLOPT_RESOLVE` · redirects followed by hand, capped, and
re-validated per hop · size/time/content-type caps · nothing of the reader's forwarded · **the
public route never takes a URL** (a uid, or an HMAC-signed payload).

`helpers\Ip` deliberately does not lean on `filter_var`'s `NO_RES_RANGE`, which misses CGNAT, the
documentation ranges, 6to4, NAT64 — and IPv4-mapped IPv6, which is the interesting one:
`::ffff:127.0.0.1` reaches loopback and passes every IPv4 check because it is not an IPv4 address.

## Traps found while building this

- **`p` is Craft's `pathParam`.** `?p=…` is how Craft is told which path was requested, so a signed
  payload sent as `p` is read as a request path and the route 404s *before any controller runs* —
  while the same route with no query string reaches the controller fine, which makes it look like
  anything but routing. Eye uses `eyeref`; `ProxyRoutes::PARAM` and a check guard it. Same family
  as the reserved `token` param already in `[[craft-plugin-gotchas]]`.
- **Framability must probe the embed URL, not the pasted one.** YouTube's *watch* page sends
  `X-Frame-Options: SAMEORIGIN` while `/embed/` is built to be framed, so checking what the author
  pasted puts a red "refuses framing" badge on the single most common embed there is.
  `Framability::check()` resolves through the provider registry itself so no caller has to
  remember; resolution is idempotent, which is what makes the cache key stable.
- **Every column an element query selects needs a property or a setter.** `eye_embeds.config` had
  neither, and the result was `UnknownPropertyException: Setting unknown property … ::config`
  thrown from `ElementQuery::createElement()` — i.e. nowhere near the cause, and only when an
  element is loaded *from the database*, so anything that re-read an element it had just saved
  (Craft's identity map) passed happily. A `DateTime`-typed column also needs naming in
  `datetimeAttributes()`.
- **A blocked cross-origin frame cannot be detected at runtime.** It still fires `load` in most
  browsers and tells the parent nothing. This is why the framing check happens at save time; the
  per-embed timeout is for slow and dead frames, not blocked ones. Say so in the docs rather than
  implying otherwise.
- **A `hidden` iframe still loads.** Click-to-load parks the frame in a `<template>`, which is the
  only markup that genuinely defers the request. There is a check asserting no `<iframe>` appears
  before the `<template>`.
- **Handles and the trash** (as in `[[project_craft_legs]]`): a soft-deleted embed keeps its row
  and its handle, so `afterDelete()` parks it as `handle--trashed-<id>` and `afterRestore()` claims
  it back; validation asks an element query, which excludes trashed rows.
- **Craft's editable table posts rows, not strings** — `allowedHosts` normalises `['host' => …]`
  in its validator rather than in a setter, so the property stays a plain array for readers.
- **Yii skips inline validators on empty attributes**, and an empty array is empty — so
  `validateAllowedHosts` needs `skipOnEmpty => false` or the one case it exists to explain (proxy
  on, allowlist blank) is the one it never sees.
- **`ddev exec` runs with `set -u`**, so an unquoted `$f` in a `for` loop kills the command *and*
  can leave the project stopped. `docker exec ddev-plugin-testing-web …` is more reliable for
  scripted work, and the Bash tool's working directory persists between calls — `cd` to the repo
  explicitly.

- **Craft's ref-tag pattern accepts almost anything in the attribute** (`[^\}\| ]+`), so
  `{eye:x:render(fallback=<img/src=x/onerror=…>)}` is writable by anyone with rich text. Ref tags
  are filtered to `EmbedOptions::REF_TAG_KEYS`, and every raw sink is sanitised at render time
  anyway (fallback purified, URLs http(s)-only, CSS values checked) — filter inputs *and* sinks.
- **A field's `enabledOptions` is UI, not policy** unless `normalizeValueFromRequest()` enforces
  it. It does now, keyed by `EmbedOptions::FIELD_OPTION_KEYS`.
- **libxml's HTML 4 parser doesn't know `<embed>` is void** and nests the rest of the page inside
  it — removing the node deletes everything after it. Unwrap, don't remove.
- **The proxy sandbox is a CSP header, chosen by script handling.** Stripped: `sandbox
  allow-same-origin …` + `script-src 'none'` (parent can still measure). Kept: `sandbox
  allow-scripts …` without same-origin, plus the injected child script, and the renderer tells the
  runtime `sameOrigin: false`. Both sides call `Proxy::stripsScripts()`; keep it that way.
- **Proxy responses are browser-cached**, so a stored embed's proxy URL carries `eyev`, a hash of
  what shapes the response. Without it, changing script handling serves the old document (and
  sandbox) for up to the cache duration.
- **Other plugins inject into proxied pages** (Tape, PWA, Schedulr, Leads add scripts to every
  `text/html` site response). The sandbox neutralises them; the console noise is expected.
- **`TooManyRequestsHttpException`'s first argument is the message**, not a retry-after — passing
  an int was a 500. Set `Retry-After` on the response yourself.

- **Guzzle's default stack ignores `CURLOPT_RESOLVE`.** With `'stream' => true` (or whenever
  `allow_url_fopen` is on) it hands requests to PHP's stream wrapper, which drops every `curl`
  option, pin included, and resolves the host again: DNS rebinding with every check passing.
  `Fetcher` builds its own `Client` on `CurlHandler` (not `Craft::createGuzzleClient()`, which
  also applies `httpProxy`), and enforces the size cap with `CURLOPT_XFERINFOFUNCTION`. A check
  substitutes `127.0.0.1` for example.com's address and asserts the harness answered.
- **`CURLOPT_RESOLVE` takes one entry per host:port**, with the addresses comma-joined and IPv6 in
  brackets. One entry per address means each replaces the last, so only the final address
  survives. That went unnoticed until the pin actually took effect, and then most providers
  failed in 0 ms on an IPv6 address the container can't route.
- **Craft 5's `_layouts/cp` has no `details` block**, so `{{ parent() }}` inside one is a Twig
  error. It only showed when editing an *existing* embed, because a new one skipped that branch.
  `trust.php` now opens a saved embed over HTTP.
- **Craft trusts every host by default** (`trustedHosts = ['any']`), so `getUserIP()` is whatever
  `X-Forwarded-For` says. `RateLimit` charges `getRemoteIP()` until a site configures
  `trustedHosts`.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eye/tests/integration/checks.php   # 140 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eye/tests/integration/trust.php    # 9, limited CP user + proxy route over HTTP
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-eye/src -name "*.php" -print0 | xargs -0 -n1 php -l'
docker exec -w /sites/craft-eye ddev-phpstan-runner-web bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'   # level 4, clean
```

`craftcms/ckeditor` and `craftcms/redactor` are dev requirements only so PHPStan can see the
optional editor integrations; Eye does not depend on either at runtime.

The checks are idempotent and self-cleaning, and they sweep up strays from a run that died
mid-way. `tests/manual/` holds two scripts that need the internet: `live-probe.php` (real fetches,
real redirects, real framing headers) and `seed-demo.php` (demo embeds + proxy settings).
Front-end demo page: `/eye-test` in the harness.

`ddev exec php craft clear-caches/cp-resources` after editing anything under `web/assets/*/dist`,
or Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('eye', '…')` for user-facing strings; `src/translations/en/eye.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Provider defaults sit *under* an author's options, never over them
