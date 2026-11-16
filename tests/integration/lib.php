<?php
/**
 * Tooling for the integration suite (real WordPress, WooCommerce, Subscriptions and Memberships).
 *
 * Run with: bash tests/integration/run.sh (see README). Never expose these files
 * on a web server: they create and delete test accounts and subscriptions.
 *
 * @package ExprimeTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

use Exprime\TeamAccess\Plugin;

/**
 * Mini framework for assertions, fixtures and email capture.
 */
final class EXPRIME_TEAM_ACCESS_IT {

	public const PREFIX = 'exprime-team-it-';

	/** @var array<int, array{name:string, ok:bool, message:string}> */
	public array $results = array();

	/** @var array<int, array<string, mixed>> Captured emails (none is actually sent). */
	public array $mails = array();

	/** @var array<string, mixed> Fixtures created by setup.php. */
	public array $fixtures = array();

	/** @var int[] Users created during the scenarios. */
	public array $created_users = array();

	/** @var int[] Orders and subscriptions created during the scenarios. */
	public array $created_orders = array();

	private string $current = '';

	/**
	 * Constructor.
	 *
	 * @param string $fixtures_file Fixtures JSON file.
	 */
	public function __construct( string $fixtures_file = '' ) {
		if ( '' !== $fixtures_file ) {
			$this->fixtures = (array) json_decode( (string) file_get_contents( $fixtures_file ), true );
		}

		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Runs a scenario.
	 *
	 * @param string   $name Name.
	 * @param callable $test Scenario.
	 */
	public function test( string $name, callable $test ): void {
		$this->current = $name;
		try {
			$test( $this );
			$this->results[] = array(
				'name'    => $name,
				'ok'      => true,
				'message' => '',
			);
			WP_CLI::log( "  ✔ {$name}" );
		} catch ( \Throwable $e ) {
			$this->results[] = array(
				'name'    => $name,
				'ok'      => false,
				'message' => $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(),
			);
			WP_CLI::log( "  ✘ {$name}\n      " . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
	}

	/**
	 * Assertion.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 * @throws \RuntimeException On failure.
	 */
	public function ok( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * Strict equality.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Context.
	 */
	public function same( mixed $expected, mixed $actual, string $message ): void {
		$this->ok( $expected === $actual, $message . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}

	/**
	 * The result is an error carrying this code.
	 *
	 * @param mixed  $result Result.
	 * @param string $code   Expected code (without prefix).
	 */
	public function error( mixed $result, string $code ): void {
		$this->ok( is_wp_error( $result ), "Error \"{$code}\" expected, got success" );
		$this->same( 'exprime_team_' . $code, $result->get_error_code(), 'Error code' );
	}

	/**
	 * The result is not an error.
	 *
	 * @param mixed  $result  Result.
	 * @param string $context Context.
	 */
	public function success( mixed $result, string $context ): mixed {
		$this->ok( ! is_wp_error( $result ), $context . ' : ' . ( is_wp_error( $result ) ? $result->get_error_code() . ' — ' . $result->get_error_message() : '' ) );
		return $result;
	}

	/** Plugin services. */
	public function plugin(): Plugin {
		return Plugin::instance();
	}

	/** Test plan. */
	public function plan(): int {
		return (int) $this->fixtures['plan_id'];
	}

	/**
	 * Creates a test user.
	 *
	 * @param string $label Label.
	 */
	public function user( string $label ): int {
		$suffix = strtolower( wp_generate_password( 6, false ) );
		$id     = wp_insert_user(
			array(
				'user_login' => self::PREFIX . $label . '-' . $suffix,
				'user_email' => self::PREFIX . $label . '-' . $suffix . '@example.test',
				'user_pass'  => wp_generate_password( 24 ),
				'first_name' => 'Test',
				'last_name'  => ucfirst( $label ),
				'role'       => 'customer',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		$this->created_users[] = (int) $id;
		return (int) $id;
	}

	/**
	 * A user's email.
	 *
	 * @param int $user_id User.
	 */
	public function email( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	/**
	 * Simulates the purchase of a subscription product: paid order + active subscription.
	 * Memberships then grants the holder's membership and links it to the subscription.
	 *
	 * @param int $user_id    Buyer.
	 * @param int $product_id Product or variation.
	 * @param int $quantity   Quantity.
	 */
	public function purchase( int $user_id, int $product_id, int $quantity = 1 ): \WC_Subscription {
		$product = wc_get_product( $product_id );
		$user    = get_userdata( $user_id );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->add_product( $product, $quantity );
		$order->set_billing_email( $user->user_email );
		$order->set_billing_first_name( (string) $user->first_name );
		$order->set_billing_last_name( (string) $user->last_name );
		$order->calculate_totals();
		$order->save();

		$subscription = wcs_create_subscription(
			array(
				'order_id'         => $order->get_id(),
				'customer_id'      => $user_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		if ( is_wp_error( $subscription ) ) {
			throw new \RuntimeException( $subscription->get_error_message() );
		}
		$subscription->add_product( $product, $quantity );
		$subscription->set_billing_email( $user->user_email );
		$subscription->set_billing_first_name( (string) $user->first_name );
		$subscription->set_billing_last_name( (string) $user->last_name );
		$subscription->set_requires_manual_renewal( true );
		$subscription->calculate_totals();
		$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		$subscription->save();

		$order->payment_complete();

		$this->created_orders[] = $order->get_id();
		$this->created_orders[] = $subscription->get_id();

		$subscription = wcs_get_subscription( $subscription->get_id() );
		$this->same( 'active', $subscription->get_status(), 'Subscription active after payment' );
		return $subscription;
	}

	/**
	 * Reloads a subscription.
	 *
	 * @param int $id Subscription.
	 */
	public function reload( int $id ): \WC_Subscription {
		return wcs_get_subscription( $id );
	}

	/**
	 * Invites and returns the seat.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $email        Email address.
	 * @param int              $actor        Actor (plan holder by default).
	 */
	public function invite( \WC_Subscription $subscription, string $email, int $actor = 0 ): \Exprime\TeamAccess\Repository\Seat {
		$result = $this->success(
			$this->plugin()->team()->invite( $subscription->get_id(), $email, $actor ?: (int) $subscription->get_customer_id() ),
			"Invitation of {$email}"
		);
		$this->ok( $result['mailed'], "Invitation email sent to {$email}" );
		return $result['seat'];
	}

	/**
	 * Token contained in the last email sent to an address.
	 *
	 * @param string $email Email address.
	 */
	public function token_for( string $email ): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( $this->mail_is_for( $mail, $email ) && preg_match( '/[?&](?:amp;)?token=([A-Za-z0-9_-]{43})/', (string) $mail['message'], $m ) ) {
				return $m[1];
			}
		}
		throw new \RuntimeException( "No invitation email for {$email}" );
	}

	/**
	 * A captured email is addressed to an address. A possible staging "wp_mail" filter
	 * may redirect the recipient: the original address is then searched for in the subject.
	 *
	 * @param array<string, mixed> $mail  Captured email.
	 * @param string               $email Email address.
	 */
	public function mail_is_for( array $mail, string $email ): bool {
		$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
		return str_contains( strtolower( $to . ' ' . (string) ( $mail['subject'] ?? '' ) ), strtolower( $email ) );
	}

	/**
	 * Invites, then accepts with an account.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      Invited account.
	 */
	public function invite_and_accept( \WC_Subscription $subscription, int $user_id ): \Exprime\TeamAccess\Repository\Seat {
		$email = $this->email( $user_id );
		$this->invite( $subscription, $email );
		return $this->success( $this->plugin()->team()->accept( $this->token_for( $email ), $user_id ), "Acceptance by {$email}" );
	}

	/**
	 * Active plan member according to Memberships (uncached).
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan (test plan by default).
	 */
	public function is_member( int $user_id, int $plan_id = 0 ): bool {
		$this->reset_memberships_cache();
		return (bool) wc_memberships()->get_user_memberships_instance()->is_user_member( $user_id, $plan_id ?: $this->plan(), true, false );
	}

	/**
	 * The user has the Memberships "active member" role (if the role assignment option is
	 * enabled; otherwise the check is moot and returns the expected value).
	 *
	 * @param int  $user_id  User.
	 * @param bool $expected Expected value when the option is disabled.
	 */
	public function has_active_member_role( int $user_id, bool $expected ): bool {
		if ( 'yes' !== get_option( 'wc_memberships_assign_user_roles_to_members', 'no' ) ) {
			return $expected;
		}
		clean_user_cache( $user_id );
		$role = (string) get_option( 'wc_memberships_active_member_user_role', 'customer' );
		return in_array( $role, (array) get_userdata( $user_id )->roles, true );
	}

	/**
	 * Clears Memberships' internal memoization. It only lives for one PHP request;
	 * the suite chains many operations in a single process.
	 */
	public function reset_memberships_cache(): void {
		$instance = wc_memberships()->get_user_memberships_instance();
		\Closure::bind(
			function (): void {
				$this->is_user_member = array();
				$this->prune_object_caches();
			},
			$instance,
			get_class( $instance )
		)();
	}

	/**
	 * Number of managed memberships for a user.
	 *
	 * @param int $user_id User.
	 */
	public function managed_count( int $user_id ): int {
		$count = 0;
		foreach ( $this->plugin()->memberships()->memberships_for( $user_id, $this->plan() ) as $membership ) {
			if ( $this->plugin()->memberships()->is_managed( $membership ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Managed membership.
	 *
	 * @param int $user_id User.
	 */
	public function managed( int $user_id ): ?\WC_Memberships_User_Membership {
		return $this->plugin()->memberships()->managed_membership( $user_id, $this->plan() );
	}

	/**
	 * Seat reloaded.
	 *
	 * @param int $seat_id Seat.
	 */
	public function seat( int $seat_id ): \Exprime\TeamAccess\Repository\Seat {
		return $this->plugin()->seats()->find( $seat_id );
	}

	/**
	 * Restricted content seen by a user (native Memberships shortcode).
	 *
	 * @param int $user_id User.
	 */
	public function sees_restricted_content( int $user_id ): bool {
		$this->reset_memberships_cache();
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		$html = do_shortcode( '[wcm_restrict]EXPRIME_TEAM_ACCESS_SECRET[/wcm_restrict]' );
		wp_set_current_user( $previous );
		return str_contains( $html, 'EXPRIME_TEAM_ACCESS_SECRET' );
	}

	/** Deletes the data created by the scenarios. */
	public function cleanup_created(): void {
		global $wpdb;
		$seats = $wpdb->prefix . 'exprime_team_seats';
		foreach ( array_unique( $this->created_orders ) as $id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}exprime_team_grants WHERE subscription_id = %d", $id ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$seats} WHERE subscription_id = %d", $id ) ); // phpcs:ignore
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array_unique( $this->created_users ) as $user_id ) {
			wp_delete_user( $user_id );
		}
	}

	/** Summary, and non-zero exit code on failure. */
	public function report(): void {
		$failed = array_filter( $this->results, static fn( array $r ): bool => ! $r['ok'] );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%d scenario(s), %d passed, %d failed.', count( $this->results ), count( $this->results ) - count( $failed ), count( $failed ) ) );
		if ( $failed ) {
			WP_CLI::error( 'Failures: ' . implode( ' | ', array_column( $failed, 'name' ) ) );
		}
		WP_CLI::success( 'All scenarios passed.' );
	}
}
