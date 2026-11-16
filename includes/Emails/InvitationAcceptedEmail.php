<?php
/**
 * Email sent to the holder when a guest accepts their invitation.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Emails;

use WCSubscriptionTeamAccess\Repository\Seat;

defined( 'ABSPATH' ) || exit;

/**
 * Notification to the subscription holder.
 */
class InvitationAcceptedEmail extends \WC_Email {

	/** Guest's address. */
	public string $guest_email = '';

	/** Subscription name. */
	public string $subscription_name = '';

	/** Link to guest management. */
	public string $manage_url = '';

	/** Constructor. */
	public function __construct() {
		$this->id             = 'wcsta_team_invitation_accepted';
		$this->customer_email = true;
		$this->title          = __( 'Team access invitation accepted', 'wc-subscription-team-access' );
		$this->description    = __( 'Sent to the subscription holder when a guest accepts an invitation.', 'wc-subscription-team-access' );
		$this->template_html  = 'emails/wcsta-team-invitation-accepted.php';
		$this->template_plain = 'emails/plain/wcsta-team-invitation-accepted.php';
		$this->template_base  = WCSTA_DIR . 'templates/';
		$this->placeholders   = array(
			'{guest_email}'       => '',
			'{subscription_name}' => '',
		);

		parent::__construct();
	}

	/** Default subject. */
	public function get_default_subject() {
		return __( '{guest_email} joined your subscription on {site_title}', 'wc-subscription-team-access' );
	}

	/** Default heading. */
	public function get_default_heading() {
		return __( 'A guest joined your subscription', 'wc-subscription-team-access' );
	}

	/**
	 * Triggers sending.
	 *
	 * @param Seat   $seat              Accepted seat.
	 * @param string $owner_email       Holder's address.
	 * @param string $subscription_name Subscription name.
	 * @param string $manage_url        Management link.
	 */
	public function trigger( Seat $seat, string $owner_email, string $subscription_name, string $manage_url ): bool {
		$this->setup_locale();

		$this->recipient                           = $owner_email;
		$this->guest_email                         = $seat->email;
		$this->subscription_name                   = $subscription_name;
		$this->manage_url                          = $manage_url;
		$this->placeholders['{guest_email}']       = $seat->email;
		$this->placeholders['{subscription_name}'] = $subscription_name;

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	/** HTML content. */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/** Plain-text content. */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Template variables.
	 *
	 * @param bool $plain_text Plain-text version.
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'guest_email'        => $this->guest_email,
			'subscription_name'  => $this->subscription_name,
			'manage_url'         => $this->manage_url,
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
