# Changelog

## 1.1.0 — 2026-11-16

- Contextual **Help** tabs (WordPress help panel) on WooCommerce > Team access, WooCommerce > Settings > Team access, product and subscription edit screens, with a help sidebar and a "Documentation" link in the plugins list.
- The whole plugin is now in English (code comments, WP-CLI help, documentation, tests); the French translation ships in `languages/`. The French README moves to `README.fr.md`.
- Security review fixes: failed synchronizations are retried in the background, guest/plan pairs are isolated, per-IP limits no longer lock out guests behind a shared IP (new `wcsta_client_ip` filter), token and subscription re-validated under lock on acceptance, `pending-cancel` access ends once the paid period is over, resending to an anonymized address is refused, holder name sanitized in emails.

## 1.0.0 — 2026-11-16

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
