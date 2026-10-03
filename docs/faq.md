---
title: FAQ
slug: faq
order: 60
summary: Common questions about embeds, iframes and the proxy in Craft CMS.
---

## Is Eye free?

Yes. No editions, no Pro tier, no licence key, and nothing on these pages is held back.

## Which Craft and PHP versions are supported?

Craft CMS 5.3+ and PHP 8.2+.

## Do I need CKEditor or Redactor?

No. Reference tags render in any rich-text field, and in a plain HTML field, because Craft parses
them itself. The CKEditor and Redactor buttons only write the tag for you. Without either, use
`{{ craft.eye.render('handle') }}` in templates or type the tag.

## Do I have to change my templates?

Not for rich text. A tag in a CKEditor or Redactor field renders wherever that field is already
output. Templates only need changing where you want an embed outside rich text — `craft.eye.render()`,
the `eye` filter, or an Embed field.

## Why does pasting a URL create an element?

Because a reference tag needs something to refer to. It also means every third-party frame on the
site ends up in one index, where you can see its framing status, switch it off everywhere at once,
or move it behind a consent card. Pastes are matched by URL, so the same video pasted ten times is
one embed.

## Does Eye call oEmbed or the provider's API?

No. It recognises the 27 services from the shape of the URL and builds the embed URL itself, so
recognising one makes no outbound request. The only connections Eye opens are the
framing check (on save, from `eye/embeds/check`, or `craft.eye.framability()`) and proxy and
inline fetches.

## What if Eye doesn't recognise my URL?

It frames it as-is, at 4:3, as a *Web page*. Every option is still yours to set.

## Can Eye make a site that refuses framing embeddable?

Proxy mode can, for hosts you add to the allowlist: the page is fetched by your server and served
from your domain, so the other site's `X-Frame-Options` never applies. Whether you *should* is
between you and that site. For anything else, Eye tells you at save time that the frame will be
blank, and suggests a link instead.

## Why can't Eye just detect a blocked frame in the browser?

Browsers don't say. A cross-origin frame blocked by `X-Frame-Options` still fires `load` in most
browsers, and the parent page gets no signal at all. That's why Eye checks the headers at save
time, and why `php craft eye/embeds/check` exists for headers that change later.

## Is click-to-load good enough for GDPR consent?

It does the technical part properly: nothing is requested from the third party until the reader
clicks, because the iframe sits in a `<template>` rather than a hidden frame. The wording, and
whether a click on an embed counts as consent for your purposes, are yours to decide.

## Does privacy mode stop all tracking?

No. It uses a provider's reduced-cookie option where one exists — `youtube-nocookie.com`, Vimeo's
`dnt=1` — and the provider still sees the request when the embed loads. For nothing to reach a
third party until the reader agrees, use **On click** loading.

## Is the proxy safe to turn on?

It is off by default because it gives anyone who can create an embed a way to make your server
fetch URLs. Eye refuses private and reserved addresses, pins each connection, re-checks every
redirect, caps every response, forwards nothing of the reader's, and never takes a URL on its
public route. The allowlist decides where it can go, and has no "allow everything" value. See
[Proxy & inline modes](proxy).

## Can someone use my site as an open proxy?

No. The public route takes a library embed's uid or a payload signed with your security key —
never a URL — and is rate limited to 120 requests a minute per address. Proxied pages also refuse
to be framed by any site but yours.

## Can authors change things they shouldn't through a reference tag?

No. Tags can change how an embed sits on the page — size, alignment, loading, caption, consent
text — but not fallback markup, CSS, the sandbox, `allow` tokens, proxy rules or the URL, and
they can't switch a framed embed to proxy or inline.

## Can I change the markup?

Yes. Put a template at `_eye/embed.twig` in your site templates and it replaces Eye's, with the
same variables. For smaller changes, the colours and radius are CSS custom properties on `.eye`.
See [Usage](usage#overriding-the-markup).

## Does Eye slow down my pages?

The stylesheet and script are small, and only load on pages that have an embed. Lazy loading is
the default, so frames below the fold cost nothing until the reader gets near them. Inline mode is
the exception worth knowing: it fetches while your page renders, so a cache miss on a slow remote
site is a slow page.
