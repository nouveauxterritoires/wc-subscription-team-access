<?php
/**
 * Contextual help tabs ("Help" button at the top right of admin screens), as WordPress does.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Admin;

/**
 * Adds the plugin documentation to the Help panel of the screens where it is useful:
 * WooCommerce > Team access, WooCommerce > Settings > Team access, product and subscription edit screens.
 */
final class Help {

	public const DOCUMENTATION_URL = 'https://github.com/nouveauxterritoires/wc-subscription-team-access#readme';

	public function register(): void {
		add_action( 'current_screen', array( $this, 'add_help' ) );
	}

	/**
	 * Adds the help tabs matching the current screen.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	public function add_help( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || ! current_user_can( AdminPage::CAPABILITY ) ) {
			return;
		}

		if ( self::is_plugin_screen( $screen ) ) {
			$this->add_full_help( $screen );
			return;
		}

		if ( 'product' === $screen->id ) {
			$screen->add_help_tab(
				array(
					'id'       => 'wcsta-help-product',
					'title'    => __( 'Team access', 'wc-subscription-team-access' ),
					'content'  => $this->product_help(),
					'priority' => 60,
				)
			);
			return;
		}

		if ( in_array( $screen->id, self::subscription_screen_ids(), true ) ) {
			$screen->add_help_tab(
				array(
					'id'       => 'wcsta-help-subscription',
					'title'    => __( 'Team access', 'wc-subscription-team-access' ),
					'content'  => $this->subscription_help(),
					'priority' => 60,
				)
			);
		}
	}

	/**
	 * The screen is the plugin page or its settings tab.
	 *
	 * @param \WP_Screen $screen Screen.
	 */
	public static function is_plugin_screen( \WP_Screen $screen ): bool {
		if ( str_ends_with( $screen->id, '_page_' . AdminPage::SLUG ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return 'woocommerce_page_wc-settings' === $screen->id && SettingsPage::ID === $tab;
	}

	/**
	 * Every documentation tab, with the sidebar.
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	public function tabs(): array {
		return array(
			'wcsta-help-overview'      => array(
				'title'   => __( 'Overview', 'wc-subscription-team-access' ),
				'content' => $this->overview(),
			),
			'wcsta-help-setup'         => array(
				'title'   => __( 'Setting up', 'wc-subscription-team-access' ),
				'content' => $this->setup(),
			),
			'wcsta-help-invitations'   => array(
				'title'   => __( 'Invitations', 'wc-subscription-team-access' ),
				'content' => $this->invitations(),
			),
			'wcsta-help-quotas'        => array(
				'title'   => __( 'Quotas', 'wc-subscription-team-access' ),
				'content' => $this->quotas(),
			),
			'wcsta-help-statuses'      => array(
				'title'   => __( 'Subscription statuses', 'wc-subscription-team-access' ),
				'content' => $this->statuses(),
			),
			'wcsta-help-memberships'   => array(
				'title'   => __( 'Access rights', 'wc-subscription-team-access' ),
				'content' => $this->memberships(),
			),
			'wcsta-help-tools'         => array(
				'title'   => __( 'Tools and troubleshooting', 'wc-subscription-team-access' ),
				'content' => $this->tools(),
			),
		);
	}

	/**
	 * Adds all the documentation tabs and the sidebar.
	 *
	 * @param \WP_Screen $screen Screen.
	 */
	private function add_full_help( \WP_Screen $screen ): void {
		$priority = 10;
		foreach ( $this->tabs() as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'       => $id,
					'title'    => $tab['title'],
					'content'  => $tab['content'],
					'priority' => $priority,
				)
			);
			$priority += 10;
		}
		$screen->set_help_sidebar( $this->sidebar() );
	}

	private function overview(): string {
		return self::section(
			__( 'Team access', 'wc-subscription-team-access' ),
			array(
				__( 'Team access lets the holder of a WooCommerce subscription share the premium access of their subscription with guests. Each guest uses their own WordPress account and is not billed.', 'wc-subscription-team-access' ),
				__( 'The holder invites guests by email from My account > My guests. A guest accepts the invitation, logs in or creates an account, and immediately gets the same access to premium content as the holder, through WooCommerce Memberships.', 'wc-subscription-team-access' ),
				__( 'Guest access follows the holder\'s subscription: suspended while it is on hold, kept until the end of the paid period when it is cancelled, removed when it expires.', 'wc-subscription-team-access' ),
			)
		) . self::items(
			__( 'Where to find what', 'wc-subscription-team-access' ),
			array(
				__( '<strong>WooCommerce > Team access</strong>: shared subscriptions, their guests, actions and log.', 'wc-subscription-team-access' ),
				__( '<strong>WooCommerce > Settings > Team access</strong>: invitation validity, sending limits, quota policy, emails, addresses.', 'wc-subscription-team-access' ),
				__( '<strong>Product edit screen</strong>: maximum number of guests for each subscription product.', 'wc-subscription-team-access' ),
				__( '<strong>Subscription edit screen</strong>: "Team access" box with the seats of the subscription.', 'wc-subscription-team-access' ),
			)
		);
	}

	private function setup(): string {
		return self::items(
			__( 'Setting up a team subscription', 'wc-subscription-team-access' ),
			array(
				__( 'Edit a simple or variable subscription product and fill in <strong>Maximum number of guests</strong> in the General tab. 0 or empty disables the feature. For variable subscriptions, each variation has its own field; an empty variation field uses the product value.', 'wc-subscription-team-access' ),
				__( 'Make sure the product grants a WooCommerce Memberships plan (plan with "product purchase" access that contains this product). Guests share exactly the plans granted by the subscription.', 'wc-subscription-team-access' ),
				__( 'Optionally, adjust the settings in WooCommerce > Settings > Team access and the two emails in WooCommerce > Settings > Emails ("Team access invitation" and "Team access invitation accepted").', 'wc-subscription-team-access' ),
			),
			true
		) . self::section(
			'',
			array(
				__( 'The quota is recorded in the subscription at purchase: changing the product later does not affect existing subscriptions.', 'wc-subscription-team-access' ),
			)
		);
	}

	private function invitations(): string {
		return self::items(
			__( 'For the holder (My account > My guests)', 'wc-subscription-team-access' ),
			array(
				__( '<strong>Invite a user</strong>: an email with a personal link is sent. A pending invitation reserves a seat until it is accepted, cancelled or expired.', 'wc-subscription-team-access' ),
				__( '<strong>Resend</strong>: a new link is sent and the previous one stops working.', 'wc-subscription-team-access' ),
				__( '<strong>Replace</strong>: the guest (or invitation) is removed and the seat is given to a new email address.', 'wc-subscription-team-access' ),
				__( '<strong>Cancel / Remove</strong>: the seat is freed and the guest immediately loses the access shared by the subscription. Their other rights (own subscription, other invitations) are kept.', 'wc-subscription-team-access' ),
			)
		) . self::items(
			__( 'For the guest', 'wc-subscription-team-access' ),
			array(
				__( 'Without an account: the guest chooses a password and the account is created with the invited email address. This works even if registration is disabled on My account.', 'wc-subscription-team-access' ),
				__( 'With an account: the guest logs in with the account that uses the invited email address, then accepts. An invitation sent to another address cannot be accepted.', 'wc-subscription-team-access' ),
				__( 'No password is ever sent by email, and an invitation can never set the password of an existing account.', 'wc-subscription-team-access' ),
			)
		) . self::items(
			__( 'Statuses', 'wc-subscription-team-access' ),
			array(
				__( '<strong>Invitation pending</strong>: sent, not accepted yet.', 'wc-subscription-team-access' ),
				__( '<strong>Invitation accepted</strong>: the guest has access.', 'wc-subscription-team-access' ),
				__( '<strong>Access suspended</strong>: the subscription is on hold, or the guest is over a reduced quota.', 'wc-subscription-team-access' ),
				__( '<strong>Access revoked</strong>: removed by the holder or an administrator.', 'wc-subscription-team-access' ),
			)
		);
	}

	private function quotas(): string {
		return self::section(
			__( 'Counting seats', 'wc-subscription-team-access' ),
			array(
				__( 'The holder does not use a seat. Pending invitations and accepted guests each use one. Cancelling, removing or letting an invitation expire frees the seat.', 'wc-subscription-team-access' ),
				__( 'The quota is the value of the product multiplied by the quantity, recorded at purchase. It is recalculated only when the customer switches plans. An administrator can force the quota of a subscription on its detail page in WooCommerce > Team access.', 'wc-subscription-team-access' ),
				__( 'Simultaneous invitations cannot exceed the quota: seats are reserved under a database lock.', 'wc-subscription-team-access' ),
			)
		) . self::items(
			__( 'When the quota decreases', 'wc-subscription-team-access' ),
			array(
				__( 'Seats are ranked: accepted guests from the oldest acceptance to the most recent, then pending invitations from the oldest to the most recent.', 'wc-subscription-team-access' ),
				__( 'With the default policy, the seats beyond the quota are suspended. Nobody is deleted, and suspended guests regain access automatically when a seat becomes available.', 'wc-subscription-team-access' ),
				__( 'With the "keep all guests" policy, nobody is suspended: the overage is only reported.', 'wc-subscription-team-access' ),
				__( 'In both cases, new invitations are blocked while the quota is exceeded, and the overage is shown to the holder and in the administration.', 'wc-subscription-team-access' ),
			),
			true
		);
	}

	private function statuses(): string {
		$rows = array(
			array( __( 'Active', 'wc-subscription-team-access' ), __( 'Guests have access.', 'wc-subscription-team-access' ) ),
			array( __( 'On hold (including a failed renewal)', 'wc-subscription-team-access' ), __( 'Access suspended; restored when the subscription is reactivated.', 'wc-subscription-team-access' ) ),
			array( __( 'Pending cancellation', 'wc-subscription-team-access' ), __( 'Access kept until the end of the paid period, then removed.', 'wc-subscription-team-access' ) ),
			array( __( 'Cancelled', 'wc-subscription-team-access' ), __( 'Access kept while the real end date is in the future, then removed.', 'wc-subscription-team-access' ) ),
			array( __( 'Expired, trashed or deleted', 'wc-subscription-team-access' ), __( 'Access removed.', 'wc-subscription-team-access' ) ),
			array( __( 'Plan switch', 'wc-subscription-team-access' ), __( 'New quota and new plans applied.', 'wc-subscription-team-access' ) ),
		);

		$html  = '<p>' . esc_html__( 'Guest access follows the status of the holder\'s subscription:', 'wc-subscription-team-access' ) . '</p>';
		$html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Subscription status', 'wc-subscription-team-access' ) . '</th><th>' . esc_html__( 'Guest access', 'wc-subscription-team-access' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as [ $status, $effect ] ) {
			$html .= '<tr><td>' . esc_html( $status ) . '</td><td>' . esc_html( $effect ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html . self::section(
			'',
			array(
				__( 'Every change is applied immediately, and a catch-up synchronization runs every day in case an event was missed. Running the same synchronization several times never creates duplicates.', 'wc-subscription-team-access' ),
			)
		);
	}

	private function memberships(): string {
		return self::items(
			__( 'How guests get access', 'wc-subscription-team-access' ),
			array(
				__( 'WooCommerce Memberships still handles all content restriction. The plugin only creates a "guest membership" on the shared plan for each guest.', 'wc-subscription-team-access' ),
				__( 'A guest has at most one guest membership per plan, even when invited by several subscriptions: it stays active as long as one of them grants access.', 'wc-subscription-team-access' ),
				__( 'Memberships bought or granted independently are never modified or deleted. If the guest already has an active membership on the plan, no guest membership is created; if it stops, the invitation takes over.', 'wc-subscription-team-access' ),
				__( 'If a guest later buys their own subscription, WooCommerce Memberships reuses the guest membership for the purchase: it then becomes a regular membership.', 'wc-subscription-team-access' ),
				__( 'If WooCommerce Memberships assigns roles to members, guests get and lose the active member role like any other member.', 'wc-subscription-team-access' ),
			)
		) . self::section(
			__( 'Good to know', 'wc-subscription-team-access' ),
			array(
				__( 'Guest memberships are managed by the plugin: change them from WooCommerce > Team access, not from the Memberships screens, otherwise the next synchronization restores them.', 'wc-subscription-team-access' ),
				__( 'By default, the WooCommerce Memberships emails (activated, ending soon, ended, renewal reminder) are not sent to guests, and the Cancel and Renew actions are hidden from their members area.', 'wc-subscription-team-access' ),
			)
		);
	}

	private function tools(): string {
		return self::items(
			__( 'Administration', 'wc-subscription-team-access' ),
			array(
				__( '<strong>Resynchronize</strong> a subscription from its detail page, or all of them from the list (runs in the background).', 'wc-subscription-team-access' ),
				__( '<strong>Log</strong>: synchronization errors and warnings, with a banner when an error occurred in the last 7 days.', 'wc-subscription-team-access' ),
				__( '<strong>Invite on behalf of the holder</strong>, revoke or resend from the detail page of a subscription.', 'wc-subscription-team-access' ),
			)
		) . self::items(
			__( 'Frequent questions', 'wc-subscription-team-access' ),
			array(
				__( 'A guest has no access: check that the subscription is active, that the product grants a Memberships plan, and that the guest is within the quota; then resynchronize the subscription.', 'wc-subscription-team-access' ),
				__( 'The invitation link does not work: it may have been used, cancelled, replaced by a newer invitation or have expired. Resend the invitation.', 'wc-subscription-team-access' ),
				__( 'The guest cannot accept: they must be logged in with the account that uses the invited email address, or create an account with it.', 'wc-subscription-team-access' ),
			)
		) . '<p><strong>' . esc_html__( 'WP-CLI commands', 'wc-subscription-team-access' ) . '</strong></p><p><code>wp team-access status &lt;subscription_id&gt;</code><br><code>wp team-access sync [&lt;subscription_id&gt;]</code><br><code>wp team-access invite &lt;subscription_id&gt; &lt;email&gt;</code><br><code>wp team-access revoke &lt;seat_id&gt;</code><br><code>wp team-access resend &lt;seat_id&gt;</code><br><code>wp team-access maintenance</code></p>';
	}

	/** Sidebar with useful links. */
	private function sidebar(): string {
		return '<p><strong>' . esc_html__( 'For more information:', 'wc-subscription-team-access' ) . '</strong></p>'
			. '<p><a href="' . esc_url( AdminPage::url() ) . '">' . esc_html__( 'Shared subscriptions', 'wc-subscription-team-access' ) . '</a></p>'
			. '<p><a href="' . esc_url( SettingsPage::url() ) . '">' . esc_html__( 'Settings', 'wc-subscription-team-access' ) . '</a></p>'
			. '<p><a href="' . esc_url( AdminPage::url( array( 'tab' => 'logs' ) ) ) . '">' . esc_html__( 'Log', 'wc-subscription-team-access' ) . '</a></p>'
			. '<p><a href="' . esc_url( self::DOCUMENTATION_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Full documentation', 'wc-subscription-team-access' ) . '</a></p>';
	}

	private function product_help(): string {
		return self::section(
			__( 'Maximum number of guests', 'wc-subscription-team-access' ),
			array(
				__( 'For subscription products, the "Maximum number of guests" field (General tab, and on each variation of a variable subscription) sets how many people the buyer can invite to share the premium access, in addition to themselves. 0 or empty disables the feature; an empty variation field uses the product value.', 'wc-subscription-team-access' ),
				__( 'The value is recorded in each subscription at purchase: changing it does not affect existing subscriptions. The product must grant a WooCommerce Memberships plan.', 'wc-subscription-team-access' ),
			)
		) . '<p><a href="' . esc_url( AdminPage::url() ) . '">' . esc_html__( 'Shared subscriptions', 'wc-subscription-team-access' ) . '</a></p>';
	}

	private function subscription_help(): string {
		return self::section(
			__( 'Team access', 'wc-subscription-team-access' ),
			array(
				__( 'The "Team access" box shows the guest seats used by this subscription. Use "Manage guests" to see the guests, force the quota, revoke or resend invitations, or resynchronize.', 'wc-subscription-team-access' ),
				__( 'Changing the subscription status updates its guests\' access: on hold suspends it, reactivation restores it, cancellation keeps it until the end of the paid period, expiration removes it.', 'wc-subscription-team-access' ),
			)
		);
	}

	/**
	 * Section: title and paragraphs.
	 *
	 * @param string   $title      Title ('' for none).
	 * @param string[] $paragraphs Paragraphs (may contain <strong>).
	 */
	private static function section( string $title, array $paragraphs ): string {
		$html = '' !== $title ? '<p><strong>' . esc_html( $title ) . '</strong></p>' : '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . wp_kses( $paragraph, array( 'strong' => array() ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * Section: title and list.
	 *
	 * @param string   $title   Title.
	 * @param string[] $items   Items (may contain <strong>).
	 * @param bool     $ordered Numbered list.
	 */
	private static function items( string $title, array $items, bool $ordered = false ): string {
		$tag  = $ordered ? 'ol' : 'ul';
		$html = '<p><strong>' . esc_html( $title ) . '</strong></p><' . $tag . '>';
		foreach ( $items as $item ) {
			$html .= '<li>' . wp_kses( $item, array( 'strong' => array() ) ) . '</li>';
		}
		return $html . '</' . $tag . '>';
	}

	/**
	 * Subscription edit screen ids (posts and HPOS).
	 *
	 * @return string[]
	 */
	private static function subscription_screen_ids(): array {
		$ids = array( 'shop_subscription' );
		if ( function_exists( 'wcs_get_page_screen_id' ) ) {
			$ids[] = (string) wcs_get_page_screen_id( 'shop_subscription' );
		}
		return $ids;
	}
}
