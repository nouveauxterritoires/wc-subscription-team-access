<?php
/**
 * Registration and sending of the plugin's WooCommerce emails.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Emails\InvitationAcceptedEmail;
use WCSubscriptionTeamAccess\Emails\InvitationEmail;
use WCSubscriptionTeamAccess\Repository\Seat;

/**
 * The emails are WC_Email instances: they are configured under WooCommerce > Settings > Emails and
 * their templates can be overridden in the theme (woocommerce/emails/...).
 */
final class Emails {

	public const INVITATION = 'WCSTA_Invitation';
	public const ACCEPTED   = 'WCSTA_Invitation_Accepted';

	public function __construct( private readonly InvitationController $invitations ) {}

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_classes' ) );
	}

	/**
	 * Adds the email classes.
	 *
	 * @param array<string, \WC_Email> $classes Existing classes.
	 * @return array<string, \WC_Email>
	 */
	public function register_classes( $classes ) {
		$classes                     = is_array( $classes ) ? $classes : array();
		$classes[ self::INVITATION ] = new InvitationEmail();
		$classes[ self::ACCEPTED ]   = new InvitationAcceptedEmail();
		return $classes;
	}

	/**
	 * Sends the invitation.
	 *
	 * @param Seat             $seat         Seat.
	 * @param string           $token        Plain-text token (never stored).
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function send_invitation( Seat $seat, string $token, \WC_Subscription $subscription ): bool {
		$email = $this->get( self::INVITATION );
		if ( ! $email instanceof InvitationEmail ) {
			return false;
		}
		return $email->trigger(
			$seat,
			$this->invitations->accept_url( $token ),
			$this->inviter_name( $subscription ),
			$this->subscription_name( $subscription )
		);
	}

	/**
	 * Notifies the holder that an invitation has been accepted.
	 *
	 * @param Seat             $seat         Accepted seat.
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function send_accepted( Seat $seat, \WC_Subscription $subscription ): bool {
		$email = $this->get( self::ACCEPTED );
		$owner = get_userdata( (int) $subscription->get_customer_id() );
		if ( ! $email instanceof InvitationAcceptedEmail || ! $owner ) {
			return false;
		}
		return $email->trigger(
			$seat,
			(string) $owner->user_email,
			$this->subscription_name( $subscription ),
			AccountEndpoint::url()
		);
	}

	/**
	 * Displayed name of the holder (first and last name, otherwise public name).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function inviter_name( \WC_Subscription $subscription ): string {
		$name = trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() );
		if ( '' === $name ) {
			$owner = get_userdata( (int) $subscription->get_customer_id() );
			$name  = $owner ? (string) $owner->display_name : '';
		}
		// Name freely entered by the customer: strip tags, links and control characters
		// so it cannot be used to slip a message or an address into the invitation.
		$name = wp_strip_all_tags( $name );
		$name = (string) preg_replace( '#(?:https?://|www\.)\S*#i', '', $name );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = trim( mb_substr( $name, 0, 80 ) );

		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * Subscription name (products), without billing information.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private function subscription_name( \WC_Subscription $subscription ): string {
		$names = array();
		foreach ( $subscription->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		return implode( ' + ', array_filter( $names ) );
	}

	/**
	 * Instance of an email loaded by WooCommerce.
	 *
	 * @param string $id Class identifier.
	 */
	private function get( string $id ): ?\WC_Email {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$emails = WC()->mailer()->get_emails();
		return isset( $emails[ $id ] ) && $emails[ $id ] instanceof \WC_Email ? $emails[ $id ] : null;
	}
}
