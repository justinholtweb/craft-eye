---
title: Installation
slug: installation
order: 10
summary: Requirements, install, your first embed, and the editor buttons.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later, with the `dom`, `json` and `mbstring` extensions

No build step, and no runtime dependencies beyond Craft's own. CKEditor and Redactor are both
optional: Eye adds a button to whichever one you use, and works without either.

## Install

```sh
composer require justinholtweb/craft-eye
php craft plugin/install eye
```

Or find **Eye** in the Craft Plugin Store and install it from there.

## Free, no editions

Eye is free. There are no editions, no Pro tier and no licence key to enter. Everything on these
pages is in the one version.

## Your first embed

1. Go to **Eye → Embeds** and press **New embed**.
2. Paste a URL. If Eye recognises the service — YouTube, Vimeo, Google Maps and 24 others — it
   fills in the real embed URL, the natural aspect ratio and the `allow` tokens for you. See
   [Usage](usage#paste-a-url).
3. Choose a **Mode** (aspect ratio is the default, and right for video) and a **Loading** strategy.
4. Give it a **Handle** — letters, numbers, dashes and underscores, starting with a letter.
5. Save. Eye reads the target's framing headers and tells you, in a sentence, if the other site
   refuses to be framed.

Then use it anywhere:

```twig
{{ craft.eye.render('promo-video') }}
```

…or in any rich-text field, with its reference tag:

```
{eye:promo-video:render}
```

Craft resolves reference tags in every rich-text value on its own, so that tag renders the embed
in CKEditor content, Redactor content and plain HTML fields with no template changes.

## Editor buttons

The buttons are editing affordances only. They write the reference tag for you; they have nothing
to do with rendering. Remove them, switch editors, or paste content between fields and the embeds
keep working.

### CKEditor

Eye registers an **Embed** toolbar item. CKEditor does not add new items to existing toolbars by
itself, so open the CKEditor config you use under **Settings → CKEditor** and drag **Embed** into
the toolbar.

The button opens a picker over the library. Pasting a bare URL onto an empty line does the same
job without the picker: Eye creates a library embed for it and inserts the tag.

The integration needs the current, import-map-based version of the CKEditor plugin. On older
versions Eye adds no button rather than risk breaking the editor, and existing embeds still render.

### Redactor

Usually nothing to do: Eye adds its **Insert an embed** button to every Redactor field. A Redactor
config that declares its own `plugins` list is left alone, so add `eye` to that list yourself.

## Permissions

| Permission | Allows |
|---|---|
| **View embeds** | The **Eye → Embeds** section |
| **Create and edit embeds** | Saving embeds, and creating one by pasting a URL in CKEditor |
| **Delete embeds** | Deleting them |

The settings screen is for admins only. Authors using an [Embed field](usage#fields) need none of
these: the field works without access to the library.
