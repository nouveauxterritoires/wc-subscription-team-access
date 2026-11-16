# Exprime Team Access

> *Version française : [README.fr.md](README.fr.md).*

WordPress plugin that lets the holder of a WooCommerce Subscriptions subscription share the premium access granted by WooCommerce Memberships with guests, each using their own WordPress account. Guests are not billed.

Independent extension made for Exprime.media, not developed or endorsed by the publisher of WooCommerce. Until version 1.1.0 it was called "WC Subscription Team Access" (slug `wc-subscription-team-access`, prefix `wcsta_`).

- Slug and text domain: `exprime-team-access`
- Requirements: WordPress 6.4+, PHP 8.1+, WooCommerce 8+, WooCommerce Subscriptions, WooCommerce Memberships
- Tested with: WooCommerce 11.3, Subscriptions 9.3, Memberships 1.26.10, PHP 8.2 and 8.4
- Languages: English, French (`languages/`)
- HPOS compatible, no external dependency in production

Why two paid extensions: Subscriptions takes the payments and handles renewals, Memberships decides who can read what. This plugin only connects them, so it does not duplicate billing or content restriction. Without them it creates its tables on activation and stays idle, with an admin notice listing what is missing. The plugin itself has no paid version.

The documentation is also available in the WordPress admin, in the **Help** tab (top right) of WooCommerce > Team access, WooCommerce > Settings > Team access, and the product and subscription edit screens.

## Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Usage](#usage)
4. [Access rights model (Memberships)](#access-rights-model-memberships)
5. [Synchronization with subscriptions](#synchronization-with-subscriptions)
6. [Quotas](#quotas)
7. [Invitations and security](#invitations-and-security)
8. [Administration](#administration)
9. [WP-CLI](#wp-cli)
10. [Tests](#tests)
11. [Developer hooks](#developer-hooks)
12. [Known limitations](#known-limitations)

## Installation

1. Copy the `exprime-team-access` folder into `wp-content/plugins/` (or upload the zip built by `bin/build-zip.sh`, which leaves out tests and development files).
2. Activate the plugin. The tables `{prefix}exprime_team_seats`, `{prefix}exprime_team_grants` and `{prefix}exprime_team_log` are created, and rewrite rules are flushed on the next page load.
3. If WooCommerce, Subscriptions or Memberships is missing, the plugin stays inactive and shows a notice in the admin.

The `tests/` folder is not needed in production. Do not deploy it in a web-accessible folder: the integration suite creates and deletes accounts.

## Configuration

### On products

In the General tab of a subscription product, fill in **Maximum number of guests**:

| Value | Effect |
|---|---|
| empty or `0` | Feature disabled |
| `5` | The buyer can invite 5 people in addition to themselves (6 accesses in total) |

For a **variable subscription**, the product field is the default value and each variation has its own field. An empty variation field uses the product value; `0` explicitly disables the feature for that variation.

The product must also grant a WooCommerce Memberships plan (a "product purchase" plan containing this product). This plan, and only this plan, is shared with guests.

### Settings

**WooCommerce > Settings > Team access**:

| Setting | Default |
|---|---|
| Invitation validity (days) | 7 |
| Invitation emails per holder per hour | 20 |
| Minimum delay between two sends of an invitation (minutes) | 5 |
| Maximum number of resends per invitation | 5 |
| Notify the holder when a guest accepts | yes |
| When the quota decreases | suspend the most recent guests |
| Do not send the Memberships emails to guests | yes |
| Anonymize the email of ended invitations after (days) | 90 |
| Invitation page slug | `invitation-equipe` |
| My account endpoint slug | `mes-invites` |

The "Team access invitation" and "Team access invitation accepted" emails are configured in WooCommerce > Settings > Emails. Their templates can be overridden in the theme (`woocommerce/emails/exprime-team-invitation.php`, etc.).

## Usage

### Holder: My account > My guests

For each shareable subscription: name, status, maximum number of guests, used and available seats, list of guests with their status, and actions:

- **Invite a user** (by email);
- **Resend** an invitation (a new link is generated, the previous one stops working);
- **Cancel** a pending invitation;
- **Replace** a guest or an invitation (the seat is reassigned);
- **Remove** a guest (they immediately lose the shared access).

Displayed statuses: *Invitation pending*, *Invitation accepted*, *Access suspended* (with the reason), *Access revoked*. The history also lists cancelled and expired invitations.

### Guest: invitation page

The link received by email opens `/invitation-equipe/`. The token is immediately removed from the URL and kept in an `HttpOnly` cookie.

- **Without an account**: the guest chooses a password. The account is created with the invited address, the guest is logged in and the invitation is accepted. This works even if registration from My account is disabled on the site: the invitation is the authorization.
- **With an account**: the guest logs in (WooCommerce form). The account email must match the invitation, then they click "Accept the invitation".
- **Logged in with another account**: refused, with an invitation to log out.

No password is ever sent by email. An existing account can never receive a password through an invitation, so a forwarded link cannot be used to take over an account.

If the **Restricted Site Access** plugin is active, the invitation page stays reachable for logged-out visitors who present a token. Nothing else is opened.

## Access rights model (Memberships)

The plugin relies exclusively on the WooCommerce Memberships engine: it does not restrict any content itself.

### Memberships constraint

Memberships only knows **one "logical" membership per user and plan**: `wc_memberships_get_user_membership()` and `wc_memberships_is_user_active_member()` only read the most recent membership. A later purchase reuses (renews) that same membership. Several access sources therefore cannot be isolated by creating several memberships on the same plan.

### Chosen architecture

- A **grant registry** (`exprime_team_grants`) stores one row per accepted seat and shared plan, with the state it grants (active with an optional end date, or suspended).
- The plugin creates **at most one "managed" membership** per guest and plan, flagged with the `_exprime_team_managed` meta, without linked order or subscription. Its state aggregates all its sources: active wins over suspended; between two active sources, the latest end wins, and a source without an end makes the access unlimited.
- An independent membership (bought, granted by an administrator, imported) is **never** created, modified or deleted by the plugin. If the guest has an active one on the plan, no managed membership exists. If it stops (personal subscription on hold, for example), the managed membership takes over. When it becomes active again, the managed membership is removed.
- If a guest **buys** a subscription granting the plan, Memberships natively reuses the managed membership and links it to their purchase. The plugin "releases" it: it becomes a personal membership and is never touched again.
- Revoking an invitation only removes the matching source. The managed membership is deleted only when no source remains.

### Documented trade-offs

- **No native `_subscription_id` link** between the guest membership and the holder's subscription. Such a link would allow a single source only. It would also show the holder's subscription (price, actions) in the guest's members area. The link goes through the registry, and the `_exprime_team_sources` meta stores the source subscriptions for display.
- **Deletion rather than cancellation** of a managed membership that is no longer needed. A more recent cancelled membership would hide, in `wc_memberships_is_user_active_member()`, an older active personal membership. Before deletion, the membership is cancelled through `cancel_membership()` so that Memberships recalculates the member role ("assign roles to members" option). The history stays available in the plugin log.
- **Seniority**: the managed membership starts on the acceptance date. Dripped content is therefore scheduled from that date, not from the holder's purchase.
- **Roles**: if Memberships assigns a role to active members (for example `membre` for active members and `subscriber` for inactive ones), guests get and lose that role exactly like other members.
- The **native Memberships emails** (activated, ending soon, ended, renewal reminder) are disabled for managed memberships (setting). The "Cancel" and "Renew" actions are hidden from the guest's members area.

## Synchronization with subscriptions

The guests' access follows the status of the holder's subscription, read through the public Subscriptions API:

| Subscription status | Guest access |
|---|---|
| `active` | Active |
| `on-hold` (including a failed renewal) | Suspended (paused membership) |
| `pending-cancel` | Active until the end of the paid period (end date copied to the membership), removed once it is past |
| `cancelled` | Active while the real end date is in the future, removed afterwards |
| `expired`, `trash`, deleted | Removed |
| Reactivation | Restored for guests still within the quota |
| Plan switch | New quota and plans re-evaluated |

Each event (`woocommerce_subscription_status_updated`, end date change, `woocommerce_subscriptions_switch_completed`, trash or deletion, status change or deletion of an independent membership) triggers a **complete and idempotent synchronization** of the subscription. It recomputes the target state from the current state: replaying an event creates neither duplicates nor extra rights. A failed synchronization is retried in the background.

A daily **catch-up synchronization** (Action Scheduler, group `exprime-team-access`) processes all shared subscriptions in batches of 100. It expires outdated invitations, anonymizes old invitations, prunes the log, and fixes orphaned or manually modified managed memberships. The plugin is authoritative over managed memberships: use its screens to change them.

## Quotas

- The quota is **recorded in the subscription at purchase** (`_exprime_team_quota` meta). Changing the product later has no retroactive effect.
- Calculation: sum, for each line, of the product or variation quota multiplied by the quantity.
- It is **recalculated only on a plan switch**. An administrator can also force a quota per subscription. Every change is kept in a history.
- The holder does not use a seat. Pending invitations and accepted guests use one each. Cancelling, removing or letting an invitation expire frees the seat.
- **Atomicity**: inviting, replacing, resending an expired invitation and accepting run under a MySQL lock (`GET_LOCK`) per subscription. A unique key also prevents two live invitations for the same address. Tested with 8 simultaneous invitations for 1 seat: 1 accepted, 7 refused.

### When the quota decreases (deterministic rule)

Seats are ranked as follows:

1. accepted guests, from the oldest to the most recent acceptance;
2. then pending invitations, from the oldest to the most recent;
3. on a tie, the smallest identifier wins.

The first `quota` seats stay active. The others become **access suspended (over quota)**, without deletion, and their pending invitations can no longer be accepted. New invitations are blocked while the quota is exceeded. As soon as a seat is freed or the quota increases, suspended guests automatically regain access.

The "Keep all guests" setting suspends nobody: it only reports the overage and blocks new invitations.

## Invitations and security

- **Tokens**: 32 bytes from `random_bytes()`, only their SHA-256 is stored. They are single use (cleared on acceptance, replaced on each resend) and expire after 7 days by default.
- **Checks before sending**: ownership of the subscription (or `manage_woocommerce` capability), positive quota, active subscription, valid address different from the holder's, address not already invited nor member, available seat.
- **Rate limiting**: emails per holder per hour, minimum delay and maximum number of resends, invalid token attempts and account creations limited per (hashed) IP address. Behind a reverse proxy, use the `exprime_team_client_ip` filter.
- **Forms**: nonces everywhere, input validation and sanitization, output escaping, prepared SQL queries.
- **Privacy**: a non-existent subscription and someone else's subscription return the same message. The invited address is masked on the invitation page, except to prefill the account creation form for the link holder. The page is not indexable (`noindex`, `Referrer-Policy: no-referrer`).
- **GDPR**: WordPress exporter and eraser (*Tools > Export / Erase Personal Data*), suggested privacy policy text, automatic anonymization of the addresses of ended invitations. Only the invited address, the guest account and the dates are kept.

## Administration

**WooCommerce > Team access** (`manage_woocommerce` capability):

- shared subscriptions: holder, status, quota (forced or not), used and available seats, overage;
- subscription detail: effective rights, shared plans, guests and linked Memberships memberships, forced quota, invitation on behalf of the holder, revoke, resend, resynchronize, recent events;
- log: synchronization errors and events, with a banner when an error occurred recently;
- full background resynchronization;
- **Help** tab with the documentation.

A **Team access** box is also shown on each subscription edit screen.

## WP-CLI

```bash
wp exprime-team status <subscription_id>      # quota, rights, plans, seats
wp exprime-team invite <subscription_id> <email> [--as=<user_id>]
wp exprime-team revoke <seat_id> [--as=<user_id>]
wp exprime-team resend <seat_id> [--as=<user_id>]
wp exprime-team sync [<subscription_id>]      # every shared subscription when no id is given
wp exprime-team schedule                      # full background resynchronization
wp exprime-team maintenance                   # expirations, anonymization, log pruning
wp exprime-team snapshot <subscription_id>    # recompute the quota from the catalog (use with care)
```

Manual check example:

```bash
wp exprime-team invite 1234 jane@example.org --as=56
wp exprime-team status 1234
wp eval 'var_dump( wc_memberships_is_user_active_member( get_user_by( "email", "jane@example.org" )->ID, <plan_id> ) );'
wp exprime-team revoke 78
```

## Tests

### Unit tests (business logic, without WordPress)

```bash
composer install
composer test
```

46 tests cover the mapping of subscription statuses to rights, multi-source aggregation, the overage rule, quota calculation, tokens and email addresses.

### Integration suite (real WordPress, WooCommerce, Subscriptions and Memberships)

It runs on a staging site where the plugin is active. It creates a plan, products, accounts and test subscriptions (prefix `exprime-team-it-`), captures emails (none is sent), then cleans everything up.

```bash
# from the WordPress root of the staging site
WP="wp" bash /path/to/exprime-team-access/tests/integration/run.sh
# KEEP=1 to keep the test data
```

Covered scenarios: subscription without seats, 5-seat subscription and frozen quota, invitation of a new user and of an existing user, different address, expired invitation then resent, invitation already used or replaced by a resend, 5 guests then sixth refused, seat reserved by a pending invitation, revocation (with role removal), replacement, suspension and reactivation, failed then successful renewal, cancellation with remaining paid period, expiration, plan switch (quota decrease then increase, variation inheriting the parent), forced quota, pre-existing personal membership (preserved, takeover, no duplicate), guest later buying their own subscription, guest of several subscriptions, attempt on someone else's subscription, repeated execution of the same event, catch-up after a deleted or manually modified membership, rate limiting, address validation, My account screen, GDPR, Memberships emails disabled, and **concurrent invitations** (8 processes for 1 seat).

Last result on the staging site (2026-11-16, WooCommerce 11.3, Subscriptions 9.3, Memberships 1.26.10): 28 scenarios out of 28 and the concurrency test pass.

### Translations

Source strings are in English. The French catalogue is built with:

```bash
wp i18n make-pot . languages/exprime-team-access.pot --exclude=vendor,tests,node_modules,build,bin
python3 bin/build-fr.py   # fails if a string is not translated
msgfmt -o languages/exprime-team-access-fr_FR.mo languages/exprime-team-access-fr_FR.po
```

## Developer hooks

| Hook | Type | Usage |
|---|---|---|
| `exprime_team_computed_quota` | filter | Change the computed quota of a subscription (`$quota, $subscription`) |
| `exprime_team_subscription_plan_ids` | filter | Plans shared with guests (`$plan_ids, $subscription`) |
| `exprime_team_subscription_effective_access` | filter | Effective right granted by a subscription (`AccessState $state, $subscription`) |
| `exprime_team_after_accept_redirect` | filter | Destination page after acceptance (`$url, Seat $seat`) |
| `exprime_team_client_ip` | filter | Client IP address used for rate limiting, to provide behind a reverse proxy or CDN (`$ip`) |

Templates that can be overridden in the theme: `exprime-team-access/my-account/team-access.php`, `exprime-team-access/invitation/accept.php`, and the emails in `woocommerce/emails/`.

## Known limitations

- Plan switch: seats stay on the modified subscription. If Subscriptions creates a *new* subscription during a switch (multi-line subscription with different billing cycles), the guests of the former subscription are not transferred automatically. An administrator can invite them again.
- The plugin is authoritative over managed memberships: a manual change in the Memberships screen is reverted at the next synchronization.
- The invitation page uses the theme header and footer (classic and block themes).
