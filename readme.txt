=== AutomateFlow ===
Contributors: netdevguru
Tags: email marketing, newsletter, automation, woocommerce, smtp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress and WooCommerce to your AutomateFlow workspace: sync contacts, route site email, embed forms, and trigger automations.

== Description ==

AutomateFlow is a self-hosted email marketing and automation platform. This plugin connects a WordPress site to an AutomateFlow workspace so the two stay in step without manual exports.

**What it does**

* **Contact sync** — WordPress users become AutomateFlow contacts as they register and update their profiles. Choose which roles are eligible and map any user meta field to an AutomateFlow custom field. Commenters can opt in with a checkbox under the comment form.
* **Site email through AutomateFlow** — route `wp_mail()` through the transactional API, so password resets, order receipts and plugin notifications go out over your configured sending infrastructure instead of PHP's `mail()`. Attachments are supported. If a send fails, the message falls back to the WordPress default rather than being dropped.
* **Embedded forms** — a shortcode and a block that render a subscription form from your workspace. Submissions are relayed server-side, so your API key is never exposed to visitors.
* **Campaign browser** — list campaigns, read delivery and engagement counts, and start a send from wp-admin.
* **WooCommerce** — sync customers with lifetime order count and spend, and fire automations on order placed, completed, refunded and cancelled. Marketing consent is collected at checkout and honoured separately from the transactional contact record.
* **Incoming webhooks** — a signed endpoint that turns platform events (bounces, complaints, campaign completion, automation outcomes, form submissions) into WordPress actions you can hook.

**Privacy and consent**

Every feature is off until you switch it on, and nothing leaves the site while a feature is disabled. For WooCommerce, "require opt-in" is on by default: customer data is only sent to the platform when the customer ticks the marketing box at checkout. The checkbox is never pre-ticked.

== External services ==

This plugin sends data to the AutomateFlow installation whose URL you configure on the settings screen. It is not a service operated by the plugin author unless you host it yourself — you supply the endpoint and the API key.

**What is sent, and when**

* *Contact sync (when enabled):* the email address, first and last name, WordPress user ID, username and role list of eligible users, plus any user meta you explicitly map. Sent when a user registers or updates their profile, and when you press "Queue all users for sync".
* *Outgoing mail (when enabled):* the recipient address, subject, message body and any attachments of email the site sends. Sent at the moment each message is dispatched.
* *Forms (when enabled):* the values a visitor submits in an embedded form, at submission time.
* *WooCommerce (when enabled):* the customer's billing name, email, city and country, order number, total, currency, item names and quantities, and lifetime order count and spend. Sent when an order changes status.
* *Campaign browser (when enabled):* no site data is sent; campaign information is read from the platform.

No data is transmitted before you enter a URL and an API key and enable a feature.

**Terms and privacy policy**

The plugin contacts no service operated by the plugin author. The only host it ever connects to is the AutomateFlow installation whose URL you enter on the settings screen, so the applicable terms and privacy policy are those of that installation — your own, if you self-host it, or your provider's if someone hosts it for you.

AutomateFlow itself is open-source software rather than a hosted product. Its source, licence and documentation are at https://github.com/netdevguru/AutomateFlow and the terms it is distributed under are at https://github.com/netdevguru/AutomateFlow/blob/main/LICENSE

== Installation ==

1. Upload the plugin to `/wp-content/plugins/automateflow/` and activate it.
2. In AutomateFlow, create an API key with both read and write scope. The key is shown once — copy it then.
3. Go to **AutomateFlow → Settings** in wp-admin, enter your AutomateFlow URL and the API key, and press **Test connection**.
4. Switch on the features you want. Each has its own options below the feature list.
5. For incoming webhooks, copy the endpoint URL shown on the settings screen into a webhook endpoint in your AutomateFlow workspace, then paste the secret it generates back into the settings screen.

== Frequently Asked Questions ==

= Does my API key end up in the page source? =

No. Forms post to WordPress, which relays the submission to the API server-side. The key is stored in the database and is never rendered, including on the settings screen — the field shows a placeholder and only writes when you type a new value.

= What happens if AutomateFlow is unreachable? =

Contact syncs are queued and retried on a five-minute schedule, so nothing is lost. Outgoing mail falls back to the WordPress default mailer, so site email still arrives. Add `add_filter( 'automateflow_mail_fallback', '__return_false' );` if you would rather a failed send report failure than fall back.

= Why is my bulk user sync taking a while? =

The API is rate limited per key. The plugin queues users and drains the queue in small batches so a large sync completes over several minutes instead of hitting the limit and losing records.

= Do WooCommerce customers get added to my list automatically? =

Only if they tick the marketing box at checkout. With "require opt-in" left on, customers who do not tick it are not sent to the platform at all. Turning it off lets order automations run for every customer, but list membership still requires the tick.

= Which trigger keys should my automations use? =

`woocommerce_order_placed`, `woocommerce_order_completed`, `woocommerce_order_refunded` and `woocommerce_order_cancelled`. They are listed on the settings screen when WooCommerce is active.

= Is WooCommerce HPOS supported? =

Yes. The integration reads orders through the WooCommerce CRUD API and declares compatibility with High-Performance Order Storage.

== Screenshots ==

1. Connection settings and the feature switches. Every feature is off until you turn it on.
2. Contact sync: which roles are eligible, and any user meta field mapped to an AutomateFlow custom field.
3. Outgoing mail, the incoming webhook endpoint, and the maintenance actions.
4. The activity log, which records outcomes only — never payloads.

== Changelog ==

= 1.0.0 =
* Initial release: contact sync, transactional mail routing, embedded forms, campaign browser, WooCommerce customer sync and order triggers, and a signed incoming-webhook endpoint.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
