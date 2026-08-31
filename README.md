# WordPress plugin

The fourth deployable. A WordPress plugin that connects a site to an AutomateFlow workspace
over the public `/api/v1/*` contract — the same contract third parties use, with no privileged
access of any kind.

```
wordpress/automateflow/     the plugin, as it ships
├── automateflow.php        header, PHP guard, container, cron cadence, HPOS declaration
├── readme.txt              wordpress.org metadata (see "Before submitting" below)
├── uninstall.php           option cleanup on delete
├── includes/
│   ├── class-automateflow-client.php        the only network call-site
│   ├── class-automateflow-settings.php      typed option access
│   ├── class-automateflow-contacts.php      users → contacts, queued
│   ├── class-automateflow-mailer.php        pre_wp_mail → /transactional/send
│   ├── class-automateflow-forms.php         shortcode + block + relay
│   ├── class-automateflow-webhooks.php      signed inbound REST endpoint
│   ├── class-automateflow-woocommerce.php   customer sync + order triggers
│   └── class-automateflow-logger.php        capped activity log
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
or copy `wordpress/automateflow` into a site's `wp-content/plugins/`.

```bash
php -l wordpress/automateflow/**/*.php     # every file must parse
node --check wordpress/automateflow/assets/js/block.js
```

If you have the WordPress coding standards available, the plugin is written to pass them:

```bash
phpcs --standard=WordPress wordpress/automateflow
```

## Before submitting to wordpress.org

Three things need a human with a running site:

1. **`Tested up to:`** in `readme.txt` is a claim about testing that has not happened. Install
   on the current WordPress release, exercise each feature, then set it honestly.
2. **Screenshots.** `readme.txt` describes three; the `assets/` directory of the SVN
   repository needs the actual `screenshot-1..3.png`.
3. **The external-services section** must stay accurate. It is a review requirement, and it is
   the section most likely to go stale as features are added — every new field the plugin
   transmits belongs in that list.
