<?php
/**
 * Public page for accepting invitations (/invitation-equipe/).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\EmailAddress;
use WCSubscriptionTeamAccess\Domain\Token;
use WCSubscriptionTeamAccess\Repository\Seat;
use WCSubscriptionTeamAccess\Support\RateLimiter;

/**
 * Flow:
 *
 * 1. The link received by email carries the token (?token=...). It is immediately moved into an
 *    HttpOnly cookie and the user is redirected to the URL without the token (no leak through the
 *    Referer header, browser history or analytics tools).
 * 2. Logged-out visitor: login (WooCommerce form) or creation of an account whose address is
 *    imposed by the invitation. An existing account can never be given a password this way.
 * 3. Logged-in visitor: the account's address must match the invitation's; they confirm.
 */
final class InvitationController {

	public const QUERY_VAR = 'wcsta_invitation';
	public const COOKIE    = 'wcsta_invitation';

	private const MIN_PASSWORD_LENGTH = 8;
	// Tokens (256 bits) cannot be guessed: these limits protect the database and the sign-up
	// form without blocking guests from the same company (NAT, proxy).
	private const BAD_TOKEN_LIMIT     = 100;
	private const REGISTER_LIMIT      = 30;

	/** Team manager (injected after construction: circular dependency with the emails). */
	private ?TeamManager $team = null;

	/**
	 * Injects the team manager.
	 *
	 * @param TeamManager $team Manager.
	 */
	public function set_team( TeamManager $team ): void {
		$this->team = $team;
	}

	/** Registers the hooks. */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ), 5 );
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
		add_filter( 'restricted_site_access_is_restricted', array( $this, 'allow_through_site_restriction' ), 10, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'document_title_parts', array( $this, 'document_title' ) );
		add_filter( 'pre_get_document_title', array( $this, 'pre_document_title' ), 999 );
		add_filter( 'wp_title', array( $this, 'pre_document_title' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Stylesheet of the invitation page. */
	public function enqueue_assets(): void {
		if ( $this->is_request() ) {
			wp_enqueue_style( 'wcsta-front', WCSTA_URL . 'assets/css/team-access.css', array(), WCSTA_VERSION );
		}
	}

	/**
	 * Full title (for themes that do not go through document_title_parts).
	 *
	 * @param string $title Title.
	 */
	public function pre_document_title( $title ) {
		if ( ! $this->is_request() ) {
			return $title;
		}
		return __( 'Invitation', 'wc-subscription-team-access' ) . ' – ' . get_bloginfo( 'name' );
	}

	/** Rewrite rule for the invitation page. */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Registers the query variable.
	 *
	 * @param string[] $vars Variables.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** URL of the invitation page. */
	public static function url(): string {
		return home_url( user_trailingslashit( self::slug() ) );
	}

	/**
	 * Acceptance link contained in the email.
	 *
	 * @param string $token Plain-text token.
	 */
	public function accept_url( string $token ): string {
		return add_query_arg( 'token', rawurlencode( $token ), self::url() );
	}

	/** Page slug. */
	private static function slug(): string {
		return (string) Settings::get( 'invitation_slug' );
	}

	/** The current request targets the invitation page. */
	public function is_request(): bool {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Lets the invitation page through "Restricted Site Access", only
	 * when a token is presented (guests do not have an account yet).
	 *
	 * @param bool  $is_restricted Restricted access.
	 * @param \WP   $wp            Request.
	 */
	public function allow_through_site_restriction( $is_restricted, $wp = null ) {
		if ( ! $wp instanceof \WP || empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return $is_restricted;
		}
		return ( isset( $_GET['token'] ) || isset( $_COOKIE[ self::COOKIE ] ) ) ? false : $is_restricted; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Non-indexable page.
	 *
	 * @param array<string, bool> $robots Directives.
	 * @return array<string, bool>
	 */
	public function robots( $robots ) {
		if ( $this->is_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Document title.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function document_title( $parts ) {
		if ( $this->is_request() ) {
			$parts['title'] = __( 'Invitation', 'wc-subscription-team-access' );
		}
		return $parts;
	}

	/** Handles the request (token in the URL, forms) before display. */
	public function handle_request(): void {
		if ( ! $this->is_request() || ! $this->team ) {
			return;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// 1. Token in the URL -> cookie, then redirect to the clean URL.
		if ( isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( Token::is_well_formed( $token ) ) {
				$this->set_cookie( $token );
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- routing only; each handler checks its own nonce.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wcsta_invitation_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wcsta_invitation_action'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$token  = $this->token();

		if ( 'accept' === $action ) {
			$this->handle_accept( $token );
		} elseif ( 'register' === $action ) {
			$this->handle_register( $token );
		}
	}

	/**
	 * Replaces the template with the invitation page.
	 *
	 * @param string $template Template.
	 */
	public function template_include( $template ) {
		if ( ! $this->is_request() ) {
			return $template;
		}
		status_header( 200 );
		return WCSTA_DIR . 'templates/invitation/page.php';
	}

	/** Renders the page content (called by the template). */
	public function render(): void {
		wc_get_template( 'invitation/accept.php', $this->view(), 'wc-subscription-team-access/', WCSTA_DIR . 'templates/' );
	}

	/**
	 * View data.
	 *
	 * @return array<string, mixed>
	 */
	public function view(): array {
		$view = array(
			'state'             => 'invalid',
			'message'           => '',
			'inviter_name'      => '',
			'subscription_name' => '',
			'masked_email'      => '',
			'email'             => '',
			'page_url'          => self::url(),
			'logout_url'        => wp_logout_url( self::url() ),
			'account_url'       => wc_get_page_permalink( 'myaccount' ),
			'min_password'      => self::MIN_PASSWORD_LENGTH,
		);

		$token = $this->token();
		if ( '' === $token || ! $this->team ) {
			$view['message'] = TeamManager::error( 'invalid_token' )->get_error_message();
			return $view;
		}

		if ( RateLimiter::exceeded( 'bad_token_' . RateLimiter::client_fingerprint(), self::BAD_TOKEN_LIMIT, HOUR_IN_SECONDS ) ) {
			$view['message'] = TeamManager::error( 'invalid_token' )->get_error_message();
			return $view;
		}

		$seat = $this->team->find_invitation( $token );
		if ( is_wp_error( $seat ) ) {
			RateLimiter::hit( 'bad_token_' . RateLimiter::client_fingerprint(), self::BAD_TOKEN_LIMIT, HOUR_IN_SECONDS );
			$view['state']   = 'wcsta_expired_token' === $seat->get_error_code() ? 'expired' : 'invalid';
			$view['message'] = $seat->get_error_message();
			return $view;
		}

		$subscription              = wcs_get_subscription( $seat->subscription_id );
		$view['inviter_name']      = $subscription ? Plugin::instance()->emails()->inviter_name( $subscription ) : '';
		$view['subscription_name'] = $subscription ? Plugin::instance()->subscriptions()->display_name( $subscription ) : '';
		$view['masked_email']      = EmailAddress::mask( $seat->email );

		if ( ! is_user_logged_in() ) {
			// The invited address is only shown in clear text to the link holder, to pre-fill the form.
			$view['state'] = 'logged_out';
			$view['email'] = $seat->email;
			return $view;
		}

		$user = wp_get_current_user();
		if ( ! EmailAddress::same( (string) $user->user_email, $seat->email ) ) {
			$view['state']   = 'mismatch';
			$view['message'] = TeamManager::error( 'email_mismatch' )->get_error_message();
			return $view;
		}

		$view['state'] = 'ready';
		return $view;
	}

	/**
	 * Acceptance by a logged-in user.
	 *
	 * @param string $token Token.
	 */
	private function handle_accept( string $token ): void {
		if ( ! is_user_logged_in() ) {
			self::notice( TeamManager::error( 'not_logged_in' )->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		if ( ! $this->verify_nonce( 'wcsta_accept' ) ) {
			self::notice( __( 'Your session has expired. Please try again.', 'wc-subscription-team-access' ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		$this->finish( $this->team->accept( $token, get_current_user_id() ) );
	}

	/**
	 * Account creation, then acceptance.
	 *
	 * @param string $token Token.
	 */
	private function handle_register( string $token ): void {
		if ( is_user_logged_in() || ! $this->verify_nonce( 'wcsta_register' ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( ! RateLimiter::hit( 'register_' . RateLimiter::client_fingerprint(), self::REGISTER_LIMIT, HOUR_IN_SECONDS ) ) {
			self::notice( TeamManager::error( 'rate_limited' )->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$seat = $this->team->find_invitation( $token );
		if ( is_wp_error( $seat ) ) {
			$this->finish( $seat );
		}

		$user_id = $this->create_account( $seat );
		if ( is_wp_error( $user_id ) ) {
			self::notice( $user_id->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		wc_set_customer_auth_cookie( $user_id );
		wp_set_current_user( $user_id );

		$this->finish( $this->team->accept( $token, $user_id ) );
	}

	/**
	 * Creates the guest's account with the invitation's address.
	 *
	 * @param Seat $seat Invitation.
	 * @return int|\WP_Error Account identifier.
	 */
	private function create_account( Seat $seat ): int|\WP_Error {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_register().
		$password   = isset( $_POST['wcsta_password'] ) ? (string) wp_unslash( $_POST['wcsta_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password must not be altered.
		$confirm    = isset( $_POST['wcsta_password_confirm'] ) ? (string) wp_unslash( $_POST['wcsta_password_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$first_name = isset( $_POST['wcsta_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsta_first_name'] ) ) : '';
		$last_name  = isset( $_POST['wcsta_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsta_last_name'] ) ) : '';
		// phpcs:enable

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			/* translators: %d: minimum number of characters. */
			return new \WP_Error( 'wcsta_password', sprintf( __( 'Your password must contain at least %d characters.', 'wc-subscription-team-access' ), self::MIN_PASSWORD_LENGTH ) );
		}
		if ( ! hash_equals( $password, $confirm ) ) {
			return new \WP_Error( 'wcsta_password', __( 'The two passwords do not match.', 'wc-subscription-team-access' ) );
		}
		if ( email_exists( $seat->email ) ) {
			return new \WP_Error( 'wcsta_account_exists', __( 'An account may already exist for this email address: please log in to accept the invitation.', 'wc-subscription-team-access' ) );
		}

		$args = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'source'     => 'wcsta-invitation',
		);

		$username = function_exists( 'wc_create_new_customer_username' )
			? wc_create_new_customer_username( $seat->email, $args )
			: sanitize_user( strstr( $seat->email, '@', true ) . wp_rand( 100, 999 ), true );

		$user_id = wc_create_new_customer( $seat->email, $username, $password, $args );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		Plugin::instance()->logger()->info(
			'account_created',
			'Account created from an invitation.',
			array(
				'subscription_id' => $seat->subscription_id,
				'seat_id'         => $seat->id,
				'user_id'         => (int) $user_id,
			)
		);

		return (int) $user_id;
	}

	/**
	 * Completes an acceptance: message, cookie cleanup and redirect.
	 *
	 * @param Seat|\WP_Error $result Result.
	 */
	private function finish( Seat|\WP_Error $result ): never {
		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$this->clear_cookie();
		self::notice( __( 'Welcome! Your invitation is accepted: you now have access to the premium content.', 'wc-subscription-team-access' ), 'success' );

		/**
		 * Filters the destination page after an invitation is accepted.
		 *
		 * @param string $url  URL (default: My Account).
		 * @param Seat   $seat Accepted seat.
		 */
		$url = (string) apply_filters( 'wcsta_after_accept_redirect', wc_get_page_permalink( 'myaccount' ), $result );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Adds a WooCommerce notice, ensuring a session exists for a logged-out visitor
	 * (otherwise the notice would be lost on redirect).
	 *
	 * @param string $message Message.
	 * @param string $type    Type (success, error, notice).
	 */
	private static function notice( string $message, string $type ): void {
		if ( function_exists( 'WC' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		wc_add_notice( $message, $type );
	}

	/**
	 * Verifies a form's nonce.
	 *
	 * @param string $action Action.
	 */
	private function verify_nonce( string $action ): bool {
		$nonce = isset( $_POST['_wcsta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcsta_nonce'] ) ) : '';
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/** Presented token (cookie). */
	private function token(): string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return Token::is_well_formed( $token ) ? $token : '';
	}

	/**
	 * Sets the token cookie (HttpOnly, SameSite=Lax, one day at most).
	 *
	 * @param string $token Token.
	 */
	private function set_cookie( string $token ): void {
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/** Deletes the token cookie. */
	private function clear_cookie(): void {
		setcookie(
			self::COOKIE,
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE ] );
	}
}
