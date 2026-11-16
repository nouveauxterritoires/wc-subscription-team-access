<?php
/**
 * Customer area: "My guests" WooCommerce endpoint (/mon-compte/mes-invites/).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

/**
 * Display and handling of the forms (PRG: POST -> message -> redirect).
 */
final class AccountEndpoint {

	public const KEY = 'team-access';

	/** @var array<int, \WC_Subscription[]> Per-request cache. */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param TeamManager         $team          Operations.
	 * @param SubscriptionManager $subscriptions Subscriptions.
	 */
	public function __construct(
		private readonly TeamManager $team,
		private readonly SubscriptionManager $subscriptions
	) {}

	/** Registers the hooks. */
	public function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::KEY . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::KEY . '_endpoint', array( $this, 'render' ) );
		add_action( 'wp_loaded', array( $this, 'handle_post' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Endpoint URL. */
	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::KEY ) : home_url( '/' );
	}

	/**
	 * Registers the endpoint with WooCommerce (which creates the rewrite rule).
	 *
	 * @param array<string, string> $vars Variables.
	 * @return array<string, string>
	 */
	public function add_query_var( $vars ) {
		$vars              = is_array( $vars ) ? $vars : array();
		$vars[ self::KEY ] = (string) Settings::get( 'account_endpoint' );
		return $vars;
	}

	/**
	 * Adds the menu entry for holders of a shareable subscription.
	 *
	 * @param array<string, string> $items Entries.
	 * @return array<string, string>
	 */
	public function menu_items( $items ) {
		if ( ! is_array( $items ) || ! $this->team_subscriptions( get_current_user_id() ) ) {
			return $items;
		}

		$entry  = array( self::KEY => __( 'My guests', 'wc-subscription-team-access' ) );
		$result = array();
		$added  = false;
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$result += $entry;
				$added   = true;
			}
			$result[ $key ] = $label;
		}
		return $added ? $result : $result + $entry;
	}

	/** Page title. */
	public function title(): string {
		return __( 'My guests', 'wc-subscription-team-access' );
	}

	/** Endpoint content. */
	public function render(): void {
		$user_id = get_current_user_id();
		$blocks  = array();

		foreach ( $this->team_subscriptions( $user_id ) as $subscription ) {
			$blocks[] = array(
				'subscription' => $subscription,
				'name'         => $this->subscriptions->display_name( $subscription ),
				'status'       => $this->subscriptions->status_label( $subscription ),
				'summary'      => $this->team->summary( $subscription ),
				'nonce'        => wp_create_nonce( self::nonce_action( $subscription->get_id() ) ),
			);
		}

		wc_get_template(
			'my-account/team-access.php',
			array(
				'blocks'     => $blocks,
				'action_url' => self::url(),
			),
			'wc-subscription-team-access/',
			WCSTA_DIR . 'templates/'
		);
	}

	/** Loads the stylesheet and script on the endpoint only. */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::KEY ) ) {
			return;
		}
		wp_enqueue_style( 'wcsta-front', WCSTA_URL . 'assets/css/team-access.css', array(), WCSTA_VERSION );
		wp_enqueue_script( 'wcsta-front', WCSTA_URL . 'assets/js/team-access.js', array(), WCSTA_VERSION, true );
	}

	/** Handles the endpoint's forms. */
	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wcsta_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce           = isset( $_POST['_wcsta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcsta_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect        = self::url() . '#wcsta-subscription-' . $subscription_id;

		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, self::nonce_action( $subscription_id ) ) ) {
			wc_add_notice( __( 'Your session has expired. Please try again.', 'wc-subscription-team-access' ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$action  = sanitize_key( wp_unslash( $_POST['wcsta_action'] ) );
		$seat_id = isset( $_POST['seat_id'] ) ? absint( wp_unslash( $_POST['seat_id'] ) ) : 0;
		$email   = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$user_id = get_current_user_id();

		if ( $seat_id > 0 && ! $this->seat_belongs_to( $seat_id, $subscription_id ) ) {
			wc_add_notice( TeamManager::error( 'not_found' )->get_error_message(), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		switch ( $action ) {
			case 'invite':
				$this->notice_for_mail(
					$this->team->invite( $subscription_id, $email, $user_id ),
					/* translators: %s: email address. */
					__( 'An invitation has been sent to %s.', 'wc-subscription-team-access' )
				);
				break;

			case 'replace':
				$this->notice_for_mail(
					$this->team->replace( $seat_id, $email, $user_id ),
					/* translators: %s: email address. */
					__( 'The guest has been replaced. An invitation has been sent to %s.', 'wc-subscription-team-access' )
				);
				break;

			case 'resend':
				$this->notice_for_mail(
					$this->team->resend( $seat_id, $user_id ),
					/* translators: %s: email address. */
					__( 'The invitation has been sent again to %s.', 'wc-subscription-team-access' )
				);
				break;

			case 'remove':
				$result = $this->team->remove( $seat_id, $user_id );
				if ( is_wp_error( $result ) ) {
					wc_add_notice( $result->get_error_message(), 'error' );
				} elseif ( 'cancelled' === $result->status ) {
					/* translators: %s: email address. */
					wc_add_notice( sprintf( __( 'The invitation sent to %s has been cancelled.', 'wc-subscription-team-access' ), $result->email ), 'success' );
				} else {
					/* translators: %s: email address. */
					wc_add_notice( sprintf( __( 'The access of %s has been revoked. The seat is available again.', 'wc-subscription-team-access' ), $result->email ), 'success' );
				}
				break;

			default:
				wc_add_notice( TeamManager::error( 'not_found' )->get_error_message(), 'error' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Nonce action per subscription.
	 *
	 * @param int $subscription_id Subscription.
	 */
	public static function nonce_action( int $subscription_id ): string {
		return 'wcsta_team_' . $subscription_id;
	}

	/**
	 * The user's shareable subscriptions (cached for the request).
	 *
	 * @param int $user_id User.
	 * @return \WC_Subscription[]
	 */
	private function team_subscriptions( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( ! isset( $this->cache[ $user_id ] ) ) {
			$this->cache[ $user_id ] = $this->subscriptions->team_subscriptions_for( $user_id );
		}
		return $this->cache[ $user_id ];
	}

	/**
	 * The seat does belong to the form's subscription.
	 *
	 * @param int $seat_id         Seat.
	 * @param int $subscription_id Subscription.
	 */
	private function seat_belongs_to( int $seat_id, int $subscription_id ): bool {
		$seat = Plugin::instance()->seats()->find( $seat_id );
		return $seat && $seat->subscription_id === $subscription_id;
	}

	/**
	 * Message after an operation that sends an email.
	 *
	 * @param array{seat: \WCSubscriptionTeamAccess\Repository\Seat, mailed: bool}|\WP_Error $result  Result.
	 * @param string                                                                         $success Message (%s: address).
	 */
	private function notice_for_mail( array|\WP_Error $result, string $success ): void {
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}
		if ( ! $result['mailed'] ) {
			wc_add_notice( TeamManager::error( 'mail_failed' )->get_error_message(), 'notice' );
			return;
		}
		wc_add_notice( sprintf( $success, $result['seat']->email ), 'success' );
	}
}
