<?php
/**
 * WordPress privacy tools: export and erasure of personal data.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess;

use Exprime\TeamAccess\Domain\EmailAddress;
use Exprime\TeamAccess\Repository\Seat;
use Exprime\TeamAccess\Repository\SeatRepository;
use Exprime\TeamAccess\Support\Dates;

/**
 * Data processed: invited email address, guest account, invitation and acceptance dates.
 * Addresses of ended seats are anonymized automatically (the "anonymize_after_days" setting).
 */
final class Privacy {

	public function __construct(
		private readonly SeatRepository $seats,
		private readonly TeamManager $team
	) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters['exprime-team-access'] = array(
			'exporter_friendly_name' => __( 'Shared subscription access', 'exprime-team-access' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ) {
		$erasers['exprime-team-access'] = array(
			'eraser_friendly_name' => __( 'Shared subscription access', 'exprime-team-access' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the invitations received by an address.
	 *
	 * @param string $email Address.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email ): array {
		$data = array();
		foreach ( $this->seats_for( (string) $email ) as $seat ) {
			$data[] = array(
				'group_id'    => 'exprime-team-seats',
				'group_label' => __( 'Shared subscription access', 'exprime-team-access' ),
				'item_id'     => 'exprime-team-seat-' . $seat->id,
				'data'        => array(
					array(
						'name'  => __( 'Invited email address', 'exprime-team-access' ),
						'value' => $seat->email,
					),
					array(
						'name'  => __( 'Status', 'exprime-team-access' ),
						'value' => Labels::status( $seat ),
					),
					array(
						'name'  => __( 'Invited on', 'exprime-team-access' ),
						'value' => Dates::display( $seat->created_at, true ),
					),
					array(
						'name'  => __( 'Accepted on', 'exprime-team-access' ),
						'value' => Dates::display( $seat->accepted_at, true ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erases: revokes live access and invitations, then anonymizes the address.
	 *
	 * @param string $email Address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$removed = false;

		foreach ( $this->seats_for( (string) $email ) as $seat ) {
			if ( $seat->is_live() ) {
				$this->team->remove_as_system( $seat->id );
			}
			$this->seats->update( $seat->id, array( 'email' => 'anonymized-' . $seat->id . '@invalid' ) );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** Suggested text for the privacy policy. */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Exprime Team Access',
			wp_kses_post(
				wpautop(
					__( 'When a subscription holder invites someone to share their access, we store the invited email address, the date of the invitation and, once accepted, the account of the guest. This data is used only to send the invitation and to grant access to the content covered by the subscription. The subscription holder can see the email addresses they invited. The email addresses of ended invitations are anonymized automatically.', 'exprime-team-access' )
				)
			)
		);
	}

	/**
	 * Seats linked to an address (as a guest, by address or by account).
	 *
	 * @param string $email Address.
	 * @return Seat[]
	 */
	private function seats_for( string $email ): array {
		$user = get_user_by( 'email', $email );
		return $this->seats->for_user_or_email( $user ? (int) $user->ID : 0, EmailAddress::normalize( $email ) );
	}
}
