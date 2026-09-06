# netdevguru Bridge for AutomateFlow

A WordPress plugin that connects a site to an AutomateFlow workspace over the public
`/api/v1/*` contract — the same contract third parties use, with no privileged access of any
kind.

**Naming.** The display name and slug are `netdevguru Bridge for AutomateFlow` /
`netdevguru-bridge-for-automateflow`. The plugin was submitted to wordpress.org as
"AutomateFlow" and pended: the reviewer flagged that name as conflicting with unrelated
automation services and as too close to an existing directory plugin, and separately could not
tie an `Author: AutomateFlow` to a personal account. Leading with the wordpress.org username
and putting the platform name after "for" is the pattern the directory prescribes for
referencing a name in a way that denotes no affiliation. Every PHP prefix follows from it:
`Netdevguru_Bridge_*` classes, `NETDEVGURU_BRIDGE_*` constants, `netdevguru_bridge_*` hooks,
options and functions.

```
netdevguru-bridge-for-automateflow/            the plugin, as it ships
├── netdevguru-bridge-for-automateflow.php     header, PHP guard, container, cron cadence, HPOS declaration
├── readme.txt              wordpress.org metadata (see "Before submitting" below)
├── uninstall.php           option cleanup on delete
├── includes/
│   ├── class-netdevguru-bridge-client.php        the only network call-site
│   ├── class-netdevguru-bridge-settings.php      typed option access
│   ├── class-netdevguru-bridge-contacts.php      users → contacts, queued
│   ├── class-netdevguru-bridge-mailer.php        pre_wp_mail → /transactional/send
│   ├── class-netdevguru-bridge-forms.php         shortcode + block + relay
│   ├── class-netdevguru-bridge-webhooks.php      signed inbound REST endpoint
│   ├── class-netdevguru-bridge-woocommerce.php   customer sync + order triggers
│   └── class-netdevguru-bridge-logger.php        capped activity log
├── admin/                  menu, settings/campaigns/log screens
└── assets/                 admin CSS, buildless editor script
```

## Why it only uses `/api/v1`

`routes/api/v1.php` says a path or response-shape change there is a breaking change for third
parties. This plugin is now one of those third parties, which is deliberate: it means the
plugin cannot be broken by dashboard-API churn, and it exercises the public contract the way a
customer would.

The consequences are worth knowing before extending it. A key carries **workspace scope but no
role**, and scope is derived from the HTTP method — safe methods need `read`, everything else
`write` — so one key covers the whole plugin and there is no finer permission to request. The
key is also rate limited (60 req/min by default, `api_rate_limit_per_minute`), which is the
single biggest constraint on the design: bulk work is queued and drained in batches rather
than looped.

## Contract details that are easy to get wrong

- **Webhook signatures are the bare hex digest.** `DeliverWebhookJob` sends
  `hash_hmac('sha256', $body, $secret)` in `X-Webhook-Signature` with **no `sha256=` prefix**,
  notwithstanding CLAUDE.md §7. Verify against the raw body — re-encoding the decoded array
  reorders keys and every legitimate request then fails.
- **`POST /contacts` upserts** on `(workspace_id, email)`, so sync is "send current state"
  rather than a create-or-update decision.
- **`POST /campaigns/{id}/send` only accepts `draft` or `scheduled`**, and 422s otherwise. The
  admin table renders the button only for those two, mirroring `campaign-actions.tsx`.
- **`/transactional/send` takes one recipient.** A message to N people costs N requests
  against the per-minute budget.

## Local development

There is no build step and no Composer dependency — what is in the tree is what runs. Symlink
or copy this directory into a site's `wp-content/plugins/` as
`netdevguru-bridge-for-automateflow`. The directory name matters: it must match the slug and
the main file's basename, or WordPress will not find the plugin.

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;   # every file must parse
node --check assets/js/block.js
./build.sh                                                       # dist/<slug>-<version>.zip
```

If you have the WordPress coding standards available, the plugin is written to pass them:

```bash
phpcs --standard=phpcs.xml.dist .
```

## Before submitting to wordpress.org

Four things need a human with a running site:

1. **`Tested up to:`** in `readme.txt` claims 7.1, which is the current release but not
   something this repository can attest to. Install on it, exercise each feature, then confirm
   or lower the claim.
2. **Regenerate `languages/*.pot`.** It was last generated under the old slug and old strings;
   its headers have been corrected by hand, but the catalogue itself is stale. Run
   `wp i18n make-pot . languages/netdevguru-bridge-for-automateflow.pot` before packaging.
3. **Screenshots.** `readme.txt` describes four; the `assets/` directory of the SVN repository
   needs the actual `screenshot-1..4.png`.
4. **The external-services section** must stay accurate. It is a review requirement, and it is
   the section most likely to go stale as features are added — every new field the plugin
   transmits belongs in that list.
