---
title: Consent managers & posters
slug: consent
order: 25
summary: Opening click-to-load cards from Toss, Cookiebot, CookieYes, Klaro or Google Consent Mode, the Eye.setConsent() adapter, and self-hosted posters that keep the card from contacting anyone.
---

A click-to-load card exists so the third party hears nothing until the reader agrees. Two things
used to get in the way of that. A reader who had already said yes in the site's cookie banner was
asked a second time on every video. And the card's poster was hotlinked from the provider, so the
provider received the reader's IP address before the reader agreed to anything. Eye now handles
both.

## Consent managers

With a consent manager connected, a card opens by itself when the reader has granted the embed's
**consent category**. When they withdraw it, the frame is removed and the card goes back. A reader
who never decides keeps the card and can still click it.

Choose the manager under **Settings → Plugins → Eye → Consent manager**:

| Setting | What Eye listens to |
|---|---|
| Automatic (default) | Toss, when it is installed with its consent kit on. Otherwise whichever of the four below the page turns out to have |
| Toss | `window.Toss.onConsent()` and the `toss:consent` event, from Toss's published consent API |
| Cookiebot | `window.Cookiebot.consent`, re-read on `CookiebotOnConsentReady`, `CookiebotOnAccept` and `CookiebotOnDecline` |
| CookieYes | `getCkyConsent()`, re-read on `cookieyes_consent_update` |
| Klaro | `klaro.getManager()`, watched for updates |
| Google Consent Mode | `gtag('consent', …)` calls as they pass through `dataLayer` |
| None | Nothing. Every card waits for its own click |

Eye only reads the manager. It never writes to one, and it never records consent itself.

### Categories

Every embed waits for one of the four categories Toss and the rest of the family use:

| Eye | Toss | Cookiebot | CookieYes | Klaro purpose | Consent Mode |
|---|---|---|---|---|---|
| `marketing` | `marketing` | `marketing` | `advertisement` | `marketing`, `advertising` | `ad_storage` |
| `analytics` | `analytics` | `statistics` | `analytics` | `analytics`, `statistics`, `performance` | `analytics_storage` |
| `preferences` | `preferences` | `preferences` | `functional` | `preferences`, `functional` | `functionality_storage` |
| `necessary` | always granted | | | | |

By default an embed waits for **marketing**. Video players, maps and social posts set cookies, and
a consent banner counts that as tracking. Tools like CodePen, Figma, Calendly, Typeform and
OpenStreetMap wait for **preferences**. To change the category for one embed, use its **Consent
category** field. A `necessary` embed opens as soon as any consent manager has answered.

Reference tags and Embed fields can't change the category. If they could, an author could mark an
embed `necessary` and load it without asking.

**Klaro** gives consent per service, not per category. A Klaro service named after the embed's
provider (`youtube`, `vimeo`, `googlemaps`) decides for that provider. If there isn't one, a
service named after the category decides. If there isn't one of those either, the category is
granted only when every service with a matching purpose has been allowed.

**Google Consent Mode** has no public read API, so Eye reads the `consent` commands as they pass
through `dataLayer`. A `default` that denies counts as *not decided*. Only an `update` is the
reader's answer.

### Who opened the card

Withdrawing consent closes only the cards that the consent manager opened. A card the reader
clicked stays open, because they asked for that one embed themselves.

### Any other consent manager

```js
Eye.setConsent('marketing', true);                    // open every card waiting for marketing
Eye.setConsent({ marketing: false, analytics: true }); // several at once
Eye.consent('marketing');                             // true, false, or null if nothing has said
Eye.unload(el);                                       // put one card back by hand
```

This works whatever the setting says. Call it from your banner's own callback.

### Holding every embed

Turn on **Hold every third-party embed for consent** to render every embed that would contact
another site as click-to-load, whatever its own **Loading** setting says. Proxied embeds are held
too: the frame comes from your own site, but the page inside it still links to the other one.
Frames from your own site are not held. Inline embeds have no frame to hold.

With a consent manager connected, a reader who has already agreed sees no card at all.

### Static caching

The page Eye renders is the same for every visitor. It contains the category and which manager to
listen to, and nothing about the reader. The reader's answer is read in their browser. So a page
cached by Blitz or a CDN is correct for everyone who receives it.

## Self-hosted posters

**Settings → Posters** has three choices:

- **Self-hosted** (default). Eye downloads the poster once, on the server, into an asset volume,
  and the card shows that copy. There is no poster until the copy exists. A plain card costs less
  than a broken promise.
- **Hotlinked**. The reader's browser loads the provider's image, as Eye 5.0 did.
- **None**. Plain cards.

Self-hosted posters need a **Poster volume**, and that volume needs public URLs. You can also set a
**Poster folder** inside the volume (default `eye-posters`) and a **Poster transform**.

Downloads happen in a queue job when an embed is saved. A page that renders a card with no copy yet
queues one too, so posters from Embed fields and `craft.eye.url()` get the same treatment. A
download that fails is retried after a day. The embed's edit screen says where its poster comes
from, and why if it isn't shown.

To fetch posters for embeds that already exist:

```sh
php craft eye/embeds/download-posters            # skips posters already downloaded
php craft eye/embeds/download-posters --force    # downloads them again
```

### What Eye will download

Posters go through the same fetcher as the proxy, with every one of its rules: public addresses
only, the connection pinned to the address that was checked, redirects followed by hand, and
limits on size and time. On top of those:

- **Hosts.** Only the hosts the provider registry lists for posters (`i.ytimg.com`,
  `img.youtube.com`). While the proxy is on, the proxy's allowed hosts count too. Eye doesn't
  download a poster URL an author typed on any other host, and it doesn't show one either. The
  check runs again after every redirect.
- **Raster images only.** JPEG, PNG, WebP, GIF or AVIF, up to 5 MB. The `Content-Type` has to say
  so, and so do the bytes themselves. An SVG is refused, because an SVG can carry script.
- **Not the proxy switch.** Posters don't need the proxy turned on.

A poster URL on your own site is shown as it is, because it isn't a third party.
