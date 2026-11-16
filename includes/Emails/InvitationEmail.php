<?php
/**
 * Invitation email sent to the invited person.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Emails;

use Exprime\TeamAccess\Repository\Seat;
use Exprime\TeamAccess\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contains a single-use acceptance link. No password is ever sent.
 */
class InvitationEmail extends \WC_Email {

	public string $accept_url = '';

	/** Holder name. */
	public string $inviter_name = '';

	public string $subscription_name = '';

	/** Expiry date (timestamp). */
	public int $expires_at = 0;

	public function __construct() {
		$this->id             = 'exprime_team_invitation';
		$this->customer_email = true;
		$this->title          = __( 'Team access invitation', 'exprime-team-access' );
		$this->description    = __( 'Sent to a person invited by a subscription holder to share their premium access.', 'exprime-team-access' );
		$this->template_html  = 'emails/exprime-team-invitation.php';
		$this->template_plain = 'emails/plain/exprime-team-invitation.php';
		$this->template_base  = EXPRIME_TEAM_ACCESS_DIR . 'templates/';
		$this->placeholders   = array(
			'{inviter_name}'      => '',
			'{subscription_name}' => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '{inviter_name} invites you to share their access on {site_title}', 'exprime-team-access' );
	}

	public function get_default_heading() {
		return __( 'You are invited', 'exprime-team-access' );
	}

	public function get_default_additional_content() {
		return __( 'If you were not expecting this invitation, you can ignore this email: nothing will happen without your action.', 'exprime-team-access' );
	}

	/**
	 * Triggers sending.
	 *
	 * @param Seat   $seat              Invited seat.
	 * @param string $accept_url        Acceptance link.
	 * @param string $inviter_name      Holder's name.
	 * @param string $subscription_name Subscription name.
	 */
	public function trigger( Seat $seat, string $accept_url, string $inviter_name, string $subscription_name ): bool {
		$this->setup_locale();

		$this->recipient                           = $seat->email;
		$this->accept_url                          = $accept_url;
		$this->inviter_name                        = $inviter_name;
		$this->subscription_name                   = $subscription_name;
		$this->expires_at                          = $seat->token_expires_at ?? ( time() + Settings::expiry_seconds() );
		$this->placeholders['{inviter_name}']      = $inviter_name;
		$this->placeholders['{subscription_name}'] = $subscription_name;

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

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
			'accept_url'         => $this->accept_url,
			'inviter_name'       => $this->inviter_name,
			'subscription_name'  => $this->subscription_name,
			'expires_at'         => $this->expires_at,
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
