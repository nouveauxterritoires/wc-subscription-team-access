=== Exprime Team Access ===
Contributors: mat_
Tags: woocommerce, subscriptions, memberships, team, invitations
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Team access for WooCommerce Subscriptions: the subscriber invites guests, who get the Memberships access on their own account.

== Description ==

You sell a subscription that unlocks members-only content, and some customers buy it for a small team. This plugin lets the subscriber share that access with a set number of guests. Each guest signs in with their own WordPress account and is not billed.

**Requires two commercial extensions:** WooCommerce Subscriptions and WooCommerce Memberships. They are not available on WordPress.org and must be installed and active. Without them the plugin does nothing except display a notice in the admin.

Exprime Team Access is an independent extension made for Exprime.media. It is not developed or endorsed by the publisher of WooCommerce.

= How it works =

* On each subscription product (and each variation), set the maximum number of guests. The quota is saved in the subscription at purchase, so editing the product later does not change existing subscriptions.
* The subscriber manages their guests in My account > My guests: invite by email, resend, cancel, replace or remove.
* The invitation link opens a page where the guest creates an account or logs in with the invited address, then accepts.
* The guest receives a WooCommerce Memberships membership for the plan granted by the product. The plugin does not restrict content itself; Memberships does.

= Follows the subscription =

Guest access is recalculated on every subscription event:

* active: access granted;
* on hold or failed renewal: access paused;
* pending cancellation or cancelled: access kept until the end of the paid period;
* expired or deleted: access removed;
* reactivation, renewal and plan switch are handled too.

Synchronization is idempotent, failed runs are retried in the background, and a daily job catches up with anything missed.

= Memberships the plugin does not own =

A membership the guest bought or was given by an administrator is never modified. If the guest later buys their own subscription, the shared membership becomes theirs and the plugin lets go of it.

= For store managers =

* WooCommerce > Team access: shared subscriptions, guests, forced quotas, invitations on behalf of the subscriber, revocation, resynchronization and an event log.
* Settings under WooCommerce > Settings > Team access.
* Two WooCommerce emails (invitation, invitation accepted) with overridable templates.
* Documentation in the Help tab of each screen.
* WP-CLI commands: `wp exprime-team status|invite|revoke|resend|sync|schedule|maintenance|snapshot`.

= Security and privacy =

* Single-use tokens, only their SHA-256 hash is stored, 7-day validity by default.
* Seat allocation runs under a database lock, so two simultaneous invitations cannot take the same last seat.
* Sending and account creation are rate limited.
* Personal data exporter and eraser, suggested privacy policy text, and automatic anonymization of old invitation addresses.
* No external requests, no tracking.

= Translations =

English and French are included.

== Installation ==

1. Install and activate WooCommerce, WooCommerce Subscriptions and WooCommerce Memberships.
2. Upload the plugin to `wp-content/plugins/` or install the zip from Plugins > Add New, then activate it.
3. Create a Memberships plan with the "product purchase" access method and add your subscription product to it.
4. Edit the subscription product and fill in "Maximum number of guests" in the General tab (or on each variation).
5. Check the options in WooCommerce > Settings > Team access.

== Frequently Asked Questions ==

= Does it work without WooCommerce Subscriptions or WooCommerce Memberships? =

No. Both are required. The plugin stays inactive and shows a notice until they are active.

= Does the subscriber use a seat? =

No. A quota of 5 means the subscriber plus 5 guests. Pending invitations and accepted guests each use a seat.

= What happens when the quota goes down? =

By default the most recent guests are suspended (not deleted) until a seat frees up or the quota goes back up. You can choose instead to keep everyone and only block new invitations.

= Can a guest belong to several teams? =

Yes. Access is computed from every source, and the most favourable one wins.

= Can I edit a shared membership in the Memberships screens? =

Use the plugin screens instead. The plugin manages those memberships and reverts manual changes on the next synchronization.

= Are passwords ever sent by email? =

No. The guest chooses their password on the invitation page, and an invitation link can never set the password of an existing account.

= Where is the full documentation? =

In the Help tab of the plugin screens, and in the README of the GitHub repository: https://github.com/nouveauxterritoires/wc-subscription-team-access

== Screenshots ==

1. My account > My guests: the subscriber's seats and invitations.
2. The invitation page seen by a guest who has no account yet.
3. WooCommerce > Team access: shared subscriptions.
4. Subscription detail in the admin, with guests and their memberships.
5. Maximum number of guests on a subscription product.

== Changelog ==

= 1.2.2 =
* Bundled French translation is used only when no language pack is installed.
* Template variables are prefixed.
* Screenshots added.

= 1.2.1 =
* Fix: the login and registration columns of the invitation page now sit side by side with WooCommerce styles.

= 1.2.0 =
* Renamed to Exprime Team Access: new slug and text domain (`exprime-team-access`), `exprime_team_` prefix for options, tables, meta keys and hooks, `Exprime\TeamAccess` namespace, WP-CLI command `wp exprime-team`.
* Stricter SQL placeholders.
* Help texts reworded.

= 1.1.0 =
* Help tabs on the plugin screens, the settings tab and the product and subscription edit screens.
* English source strings, French translation included.
* Failed synchronizations are retried in the background.
* Per-IP limits no longer lock out guests sharing an IP address (new `exprime_team_client_ip` filter).
* Token and subscription are checked again under lock when an invitation is accepted.
* Pending-cancel access ends with the paid period.
* Resending to an anonymized address is refused.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.2.1 =
Fixes the layout of the invitation page.

= 1.2.0 =
New slug, prefixes and hook names. Settings and data from 1.1.0 are not migrated: reinstall and reconfigure.

= 1.1.0 =
Security fixes and background retry of failed synchronizations. Recommended for all sites.
