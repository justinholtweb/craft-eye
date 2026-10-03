# Plugin Store promo images

Seven 1920×1080 JPEGs for the Eye listing, in the theme of the plugin's page at
[justinholt.com/plugins/craft-eye](https://justinholt.com/plugins/craft-eye).

```bash
./build.sh          # all slides
./build.sh "1 5"    # just slides 1 and 5
```

Output lands in `out/` as `eye-promo-N.jpg`: rendered at 2× in headless Chrome, then downsampled
and converted to JPEG in one `sips` pass. Promos are never PNG.

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover: name, tagline, icon, Free badge | — |
| 2 | Four ways an iframe goes wrong, each with its fix | the whole pitch |
| 3 | A pasted YouTube URL and what Eye makes of it | output of `eye/embeds/inspect` |
| 4 | Click-to-load card | `shots/eye-consent-card.png`, from `/eye-test` |
| 5 | An embed that refuses framing | `shots/eye-framing.png`, the edit screen |
| 6 | The proxy's nine rules | `Fetcher`'s docblock |
| 7 | Closing grid | — |

Slide 3 quotes real output: the `allow` tokens and the `start` translation come from
`Providers.php`. Change the provider and change the slide.

## Assets

- `assets/icon.svg` is a straight copy of `../src/icon.svg`. The icon has no full-canvas frame
  path, so none of the cropping other decks needed applies.
- `assets/watermark.svg` is `../src/icon-mask.svg` with the fill switched to white: the eye with
  no tile, because a rounded square at watermark size reads as a grey box.
- `assets/icon-source.jpg` is the original raster art the icon was traced from (potrace at 4×,
  iris redrawn as circles first). It is not used by the deck; it lives here so it doesn't ship in
  `src/`.

The screenshots come from the plugin-testing harness via `~/Sites/plugin-shots/specs/eye.json`
and `eye-edit.json`.
