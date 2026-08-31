# WordPress.org listing assets

Nothing in this directory ships to users. WordPress.org serves the plugin listing's
images from the `assets/` directory at the **root of the SVN repository** — a sibling of
`trunk/` and `tags/`, not a folder inside the plugin — so these files are staged here and
copied to `svn/assets/` at deploy time. `.distignore` keeps the whole directory out of the
plugin ZIP.

## Screenshots — required for the three entries in `readme.txt`

`readme.txt` has a `== Screenshots ==` section listing three images. The captions are
matched to files by number, so a missing file leaves a numbered caption with nothing under
it on the listing page. Capture these at a 2:1-ish aspect ratio, 1280px wide or more, and
crop to the panel rather than the whole browser:

| File               | Caption in readme.txt                                          |
|--------------------|----------------------------------------------------------------|
| `screenshot-1.png` | The settings screen, with per-feature switches and connection testing. |
| `screenshot-2.png` | The campaign browser, showing status and delivery counts.      |
| `screenshot-3.png` | The activity log.                                              |

Use a workspace with placeholder data. A real API key must not be visible — the settings
screen renders the key field as an empty placeholder, so it is safe to shoot as-is.

## Optional but recommended

| File                  | Size        | Where it appears                        |
|-----------------------|-------------|-----------------------------------------|
| `icon-256x256.png`    | 256×256     | Search results and the plugin card      |
| `icon-128x128.png`    | 128×128     | Fallback for the above                  |
| `banner-772x250.png`  | 772×250     | Header of the plugin page               |
| `banner-1544x500.png` | 1544×500    | Retina version of the banner            |

Without an icon the directory shows a generic grey placeholder, which is the single most
noticeable difference between a listing that looks maintained and one that does not.
