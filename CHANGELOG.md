# Changelog

## 1.2.3 - 2026-11-16

- Invitation page: the title and the two column headings get their size, weight and spacing from the plugin stylesheet. Themes that style headings only inside their own content wrapper (Marcelle, for one) showed them as plain text.

## 1.2.2 - 2026-11-16

- The bundled French catalogue now loads with `load_textdomain()`, and only when no translate.wordpress.org language pack is installed for the locale. `load_plugin_textdomain()` is gone.
- Variables of the My guests template are prefixed with `$exprime_team_`. Themes that override `my-account/team-access.php` must rename them too.
- `readme.txt`: title aligned on the plugin name (Exprime Team Access), contributor `mat_`, screenshots in `.wordpress-org/`.
- The `exprime-team-team` CSS class and the `exprime-team-team-access` meta box id left over from the rename become `exprime-team` and `exprime-team-access`.

## 1.2.1 - 2026-11-16

- Fix: the login and registration columns of the invitation page now sit side by side. WooCommerce's `.col2-set` clearfix and column widths overrode the grid.

## 1.2.0 - 2026-11-16

- Renamed to **Exprime Team Access** (Exprime Team Access for WooCommerce Subscriptions on WordPress.org), so the name and slug follow the WordPress.org trademark rules:
  - slug, folder, main file and text domain: `exprime-team-access` (was `wc-subscription-team-access`);
  - prefix `exprime_team_` / `exprime-team-` / `EXPRIME_TEAM_ACCESS_` (was `wcsta_` / `wcsta-` / `WCSTA_`) for options, tables, meta keys, hooks and filters, nonces, cookie, CSS classes and handles, constants;
  - PHP namespace `Exprime\TeamAccess` (was `WCSubscriptionTeamAccess`);
  - WP-CLI command `wp exprime-team` (was `wp team-access`);
  - Action Scheduler group `exprime-team-access`;
  - email templates `exprime-team-invitation.php` and `exprime-team-invitation-accepted.php`.
  No migration: data and settings from 1.1.0 are not carried over.
- WordPress.org packaging: `readme.txt`, `.distignore`, `bin/build-zip.sh`, banner and icon in `.wordpress-org/`.
- The log query and the anonymization query use stricter placeholders.
- Help texts reworded.

## 1.1.0 - 2026-11-16

- **Help** tabs on WooCommerce > Team access, WooCommerce > Settings > Team access and the product and subscription edit screens, with a sidebar and a "Documentation" link in the plugins list.
- Source strings, comments, WP-CLI help, documentation and tests are now in English. The French translation ships in `languages/` and the French README moved to `README.fr.md`.
- Failed synchronizations are retried in the background.
- Guest/plan pairs are isolated from each other.
- Per-IP limits no longer lock out guests behind a shared IP address. Behind a proxy, use the new `wcsta_client_ip` filter.
- Token and subscription are checked again under lock when an invitation is accepted.
- `pending-cancel` access ends once the paid period is over.
- Resending to an anonymized address is refused.
- The holder name is sanitized in emails.

## 1.0.0 - 2026-11-16

First release.

- Maximum number of guests per subscription product (simple, variable and per variation), frozen in the subscription at purchase.
- My account > My guests: invite, cancel, resend, replace, remove.
- Public invitation page: log in or create an account (address imposed by the invitation), then accept.
- Managed WooCommerce Memberships memberships for guests, synchronized with the holder's subscription status (active, on hold, pending cancellation, cancelled, expired, reactivation, renewal, plan switch).
- Multi-source rights (several invitations, personal memberships preserved, takeover by the guest's own purchase).
- Atomic quotas (MySQL lock), deterministic overage rule, quota forced by an administrator.
- Administration: shared subscriptions, detail, actions, log; settings in WooCommerce > Settings > Team access.
- Daily catch-up synchronization (Action Scheduler), `wp team-access` WP-CLI commands.
- WooCommerce emails (invitation, acceptance), French translation, GDPR export and erasure, automatic anonymization.
