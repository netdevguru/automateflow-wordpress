# WordPress.org listing assets

Nothing in this directory ships to users. WordPress.org serves the plugin listing's images
from the `assets/` directory at the **root of the SVN repository** — a sibling of `trunk/`
and `tags/`, not a folder inside the plugin — so these files are staged here and copied to
`svn/assets/` at deploy time. `.distignore` keeps the whole directory out of the plugin ZIP.

## Current set

Four screenshots, matching the four numbered captions in `readme.txt`. The listing pairs
them by number, so the count here and the caption count there must stay equal.

| File               | Caption |
|--------------------|---------|
| `screenshot-1.png` | Connection settings and the feature switches. |
| `screenshot-2.png` | Contact sync: roles and custom field mapping. |
| `screenshot-3.png` | Outgoing mail, webhook endpoint, maintenance actions. |
| `screenshot-4.png` | The activity log. |

All four are cropped 48px shorter than the captures in `source/`. That trim removes the
browser's rounded window corner, which showed as a black wedge against the page, and on one
of them a macOS screenshot-preview thumbnail that had drifted into frame.

`source/` holds the six original captures untouched, so any of this can be redone without
recapturing.

## Not used, and why

* `source/screenshot-3.png` — near-duplicate of the outgoing-mail view already used.
* `source/screenshot-6.png` — the campaign browser showing only "Could not reach
  AutomateFlow: cURL error 6". Accurate for a site with no connection, but on a listing page
  it reads as a broken plugin.

## Still worth adding

**The campaign browser.** It is a headline feature with no screenshot, and it is the one
screen that cannot be captured without a reachable AutomateFlow install holding real
campaigns. Capture it with a live connection, add it as `screenshot-5.png`, and add a fifth
caption to `readme.txt` describing status and delivery counts.

The activity log shot currently shows a single WARNING row from a failed sync against an
unreachable host. It is honest, but a log with a mix of info and warning rows would represent
normal operation better.

## Optional but recommended

| File                  | Size        | Where it appears                        |
|-----------------------|-------------|-----------------------------------------|
| `icon-256x256.png`    | 256×256     | Search results and the plugin card      |
| `icon-128x128.png`    | 128×128     | Fallback for the above                  |
| `banner-772x250.png`  | 772×250     | Header of the plugin page               |
| `banner-1544x500.png` | 1544×500    | Retina version of the banner            |

Without an icon the directory shows a generic grey placeholder, which is the single most
noticeable difference between a listing that looks maintained and one that does not.
