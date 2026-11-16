# Changelog

## Unreleased

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
