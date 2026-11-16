<?php
/**
 * Onglet « Accès équipe » dans WooCommerce > Réglages.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Admin;

use WCSubscriptionTeamAccess\Domain\SeatAllocator;
use WCSubscriptionTeamAccess\Installer;
use WCSubscriptionTeamAccess\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Les valeurs sont enregistrées par WooCommerce dans l'option tableau « wcsta_settings »
 * (champs « wcsta_settings[clé] »), puis normalisées et bornées par Settings::sanitize().
 */
class SettingsPage extends \WC_Settings_Page {

	public const ID = 'wcsta';

	/** Constructeur. */
	public function __construct() {
		$this->id    = self::ID;
		$this->label = __( 'Team access', 'wc-subscription-team-access' );
		parent::__construct();
	}

	/** URL de l'onglet. */
	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::ID );
	}

	/**
	 * Champs de l'onglet.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section() {
		$defaults = Settings::defaults();

		return array(
			array(
				'id'    => 'wcsta_invitations',
				'type'  => 'title',
				'title' => __( 'Invitations', 'wc-subscription-team-access' ),
				'desc'  => sprintf(
					/* translators: %s: link to the shared subscriptions page. */
					__( 'The maximum number of guests is set on each subscription product (General tab, or per variation). Shared subscriptions and the log are available in %s.', 'wc-subscription-team-access' ),
					'<a href="' . esc_url( AdminPage::url() ) . '">' . esc_html__( 'WooCommerce > Team access', 'wc-subscription-team-access' ) . '</a>'
				),
			),
			$this->number( 'invitation_expiry_days', __( 'Invitation validity (days)', 'wc-subscription-team-access' ), 1, 60, $defaults ),
			$this->number( 'max_invites_per_hour', __( 'Invitation emails per holder per hour', 'wc-subscription-team-access' ), 1, 500, $defaults ),
			$this->number( 'resend_min_interval', __( 'Minimum delay between two sends of an invitation (minutes)', 'wc-subscription-team-access' ), 0, 1440, $defaults ),
			$this->number( 'max_resends', __( 'Maximum number of resends per invitation', 'wc-subscription-team-access' ), 0, 50, $defaults ),
			array(
				'id'      => Settings::OPTION . '[notify_owner_on_accept]',
				'type'    => 'checkbox',
				'title'   => __( 'Holder notification', 'wc-subscription-team-access' ),
				'desc'    => __( 'Notify the holder when a guest accepts an invitation', 'wc-subscription-team-access' ),
				'default' => $defaults['notify_owner_on_accept'],
			),
			array(
				'id'   => 'wcsta_invitations',
				'type' => 'sectionend',
			),

			array(
				'id'    => 'wcsta_quota',
				'type'  => 'title',
				'title' => __( 'Quota', 'wc-subscription-team-access' ),
			),
			array(
				'id'       => Settings::OPTION . '[overage_policy]',
				'type'     => 'select',
				'title'    => __( 'When the quota decreases', 'wc-subscription-team-access' ),
				'desc'     => __( 'Guests are ranked by acceptance date (oldest first), then pending invitations by creation date. Nobody is deleted: suspended guests regain access automatically when a seat becomes available.', 'wc-subscription-team-access' ),
				'desc_tip' => false,
				'default'  => $defaults['overage_policy'],
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					SeatAllocator::POLICY_SUSPEND_NEWEST => __( 'Suspend the most recent guests beyond the quota (default)', 'wc-subscription-team-access' ),
					SeatAllocator::POLICY_KEEP_ALL       => __( 'Keep all guests, only block new invitations', 'wc-subscription-team-access' ),
				),
			),
			array(
				'id'   => 'wcsta_quota',
				'type' => 'sectionend',
			),

			array(
				'id'    => 'wcsta_memberships',
				'type'  => 'title',
				'title' => __( 'Memberships and privacy', 'wc-subscription-team-access' ),
			),
			array(
				'id'      => Settings::OPTION . '[suppress_membership_emails]',
				'type'    => 'checkbox',
				'title'   => __( 'Memberships emails', 'wc-subscription-team-access' ),
				'desc'    => __( 'Do not send the WooCommerce Memberships emails (activated, ending soon, ended, renewal reminder) for guest memberships', 'wc-subscription-team-access' ),
				'default' => $defaults['suppress_membership_emails'],
			),
			$this->number( 'anonymize_after_days', __( 'Anonymize the email of ended invitations after (days, 0 = never)', 'wc-subscription-team-access' ), 0, 3650, $defaults ),
			array(
				'id'   => 'wcsta_memberships',
				'type' => 'sectionend',
			),

			array(
				'id'    => 'wcsta_urls',
				'type'  => 'title',
				'title' => __( 'Addresses', 'wc-subscription-team-access' ),
			),
			$this->text( 'invitation_slug', __( 'Invitation page slug', 'wc-subscription-team-access' ), $defaults ),
			$this->text( 'account_endpoint', __( 'My account endpoint slug', 'wc-subscription-team-access' ), $defaults ),
			array(
				'id'   => 'wcsta_urls',
				'type' => 'sectionend',
			),
		);
	}

	/** Enregistre, normalise et vide les règles de réécriture si une adresse change. */
	public function save() {
		$before = Settings::all();

		parent::save();

		$raw   = get_option( Settings::OPTION, array() );
		$after = Settings::sanitize( is_array( $raw ) ? $raw : array() );
		update_option( Settings::OPTION, $after, false );

		if ( $before['invitation_slug'] !== $after['invitation_slug'] || $before['account_endpoint'] !== $after['account_endpoint'] ) {
			update_option( Installer::FLUSH_OPTION, 'yes', false );
		}
	}

	/**
	 * Champ numérique.
	 *
	 * @param string               $key      Clé.
	 * @param string               $title    Libellé.
	 * @param int                  $min      Minimum.
	 * @param int                  $max      Maximum.
	 * @param array<string, mixed> $defaults Valeurs par défaut.
	 * @return array<string, mixed>
	 */
	private function number( string $key, string $title, int $min, int $max, array $defaults ): array {
		return array(
			'id'                => Settings::OPTION . '[' . $key . ']',
			'type'              => 'number',
			'title'             => $title,
			'default'           => (string) $defaults[ $key ],
			'css'               => 'width: 6em;',
			'custom_attributes' => array(
				'min'  => (string) $min,
				'max'  => (string) $max,
				'step' => '1',
			),
		);
	}

	/**
	 * Champ texte.
	 *
	 * @param string               $key      Clé.
	 * @param string               $title    Libellé.
	 * @param array<string, mixed> $defaults Valeurs par défaut.
	 * @return array<string, mixed>
	 */
	private function text( string $key, string $title, array $defaults ): array {
		return array(
			'id'      => Settings::OPTION . '[' . $key . ']',
			'type'    => 'text',
			'title'   => $title,
			'default' => (string) $defaults[ $key ],
		);
	}
}
