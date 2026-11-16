<?php
/**
 * Admin: WooCommerce > Team access.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Admin;

use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Labels;
use WCSubscriptionTeamAccess\MembershipManager;
use WCSubscriptionTeamAccess\QuotaManager;
use WCSubscriptionTeamAccess\Reconciler;
use WCSubscriptionTeamAccess\Repository\GrantRepository;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Repository\Seat;
use WCSubscriptionTeamAccess\Repository\SeatRepository;
use WCSubscriptionTeamAccess\Scheduler;
use WCSubscriptionTeamAccess\SubscriptionManager;
use WCSubscriptionTeamAccess\Support\Dates;
use WCSubscriptionTeamAccess\TeamManager;

/**
 * Browsing of shared subscriptions, admin actions, log and settings.
 * All actions require the "manage_woocommerce" capability and a nonce.
 */
final class AdminPage {

	public const SLUG       = 'wcsta';
	public const CAPABILITY = 'manage_woocommerce';
	private const PER_PAGE  = 20;

	public function __construct(
		private readonly TeamManager $team,
		private readonly Reconciler $reconciler,
		private readonly SubscriptionManager $subscriptions,
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats,
		private readonly GrantRepository $grants,
		private readonly MembershipManager $memberships,
		private readonly Logger $logger
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_post_wcsta_admin', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Team access', 'wc-subscription-team-access' ),
			__( 'Team access', 'wc-subscription-team-access' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Page stylesheet.
	 *
	 * @param string $hook Screen.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wcsta-admin', WCSTA_URL . 'assets/css/admin.css', array(), WCSTA_VERSION );
		wp_enqueue_script( 'wcsta-front', WCSTA_URL . 'assets/js/team-access.js', array(), WCSTA_VERSION, true );
	}

	/**
	 * Tab URL.
	 *
	 * @param array<string, scalar> $args Arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'wc-subscription-team-access' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only.
		$tab             = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'subscriptions';
		$subscription_id = isset( $_GET['subscription'] ) ? absint( wp_unslash( $_GET['subscription'] ) ) : 0;
		// phpcs:enable

		echo '<div class="wrap wcsta-admin">';
		echo '<h1>' . esc_html__( 'Team access', 'wc-subscription-team-access' ) . '</h1>';
		$this->print_notice();
		$this->print_error_banner();

		$tabs = array(
			'subscriptions' => __( 'Shared subscriptions', 'wc-subscription-team-access' ),
			'logs'          => __( 'Log', 'wc-subscription-team-access' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		printf( '<a href="%s" class="nav-tab">%s</a>', esc_url( SettingsPage::url() ), esc_html__( 'Settings', 'wc-subscription-team-access' ) );
		echo '</nav>';

		switch ( $tab ) {
			case 'logs':
				$this->render_logs();
				break;
			default:
				if ( $subscription_id > 0 ) {
					$this->render_subscription( $subscription_id );
				} else {
					$this->render_list();
				}
		}

		echo '</div>';
	}

	/** Handles actions (admin-post.php). */
	public function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'wc-subscription-team-access' ), 403 );
		}
		check_admin_referer( 'wcsta_admin' );

		$op              = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		$seat_id         = isset( $_POST['seat_id'] ) ? absint( wp_unslash( $_POST['seat_id'] ) ) : 0;
		$actor           = get_current_user_id();
		$back            = $subscription_id > 0 ? self::url( array( 'subscription' => $subscription_id ) ) : self::url();

		switch ( $op ) {
			case 'revoke':
				$this->notice_result( $this->team->remove( $seat_id, $actor ), __( 'The seat has been ended.', 'wc-subscription-team-access' ) );
				break;

			case 'resend':
				$this->notice_result( $this->team->resend( $seat_id, $actor ), __( 'The invitation has been sent again.', 'wc-subscription-team-access' ) );
				break;

			case 'invite':
				$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
				$this->notice_result( $this->team->invite( $subscription_id, $email, $actor ), __( 'The invitation has been sent.', 'wc-subscription-team-access' ) );
				break;

			case 'sync':
				$this->notice_result( $this->reconciler->sync_subscription( $subscription_id, 'admin' ), __( 'The subscription has been synchronized.', 'wc-subscription-team-access' ) );
				break;

			case 'sync_all':
				( new Scheduler( $this->reconciler ) )->enqueue_batch( 0 );
				$this->set_notice( __( 'A full synchronization has been scheduled. It runs in the background.', 'wc-subscription-team-access' ), 'success' );
				break;

			case 'override':
				$this->handle_override( $subscription_id, $actor );
				break;

			default:
				$this->set_notice( __( 'Unknown action.', 'wc-subscription-team-access' ), 'error' );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/** List of subscriptions that have guests. */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable

		$result = $this->seats->admin_subscription_summary( array( 'search' => $search ), self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<form method="get" class="wcsta-search"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		printf(
			'<p class="search-box"><label class="screen-reader-text" for="wcsta-search">%1$s</label><input type="search" id="wcsta-search" name="s" value="%2$s" placeholder="%3$s"> <button class="button">%1$s</button></p>',
			esc_html__( 'Search', 'wc-subscription-team-access' ),
			esc_attr( $search ),
			esc_attr__( 'Email or subscription number', 'wc-subscription-team-access' )
		);
		echo '</form>';

		echo '<table class="widefat striped wcsta-table"><thead><tr>';
		foreach ( array(
			__( 'Subscription', 'wc-subscription-team-access' ),
			__( 'Holder', 'wc-subscription-team-access' ),
			__( 'Status', 'wc-subscription-team-access' ),
			__( 'Quota', 'wc-subscription-team-access' ),
			__( 'Used', 'wc-subscription-team-access' ),
			__( 'Available', 'wc-subscription-team-access' ),
			__( 'Pending / accepted / suspended', 'wc-subscription-team-access' ),
			__( 'Actions', 'wc-subscription-team-access' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No subscription has guests yet.', 'wc-subscription-team-access' ) . '</td></tr>';
		}

		foreach ( $result['rows'] as $row ) {
			$subscription_id = (int) $row['subscription_id'];
			$subscription    = $this->subscriptions->get( $subscription_id );
			$allocation      = $subscription ? $this->reconciler->allocation( $subscription ) : null;
			$detail_url      = self::url( array( 'subscription' => $subscription_id ) );

			echo '<tr>';
			printf( '<td><a href="%s"><strong>#%d</strong></a> %s</td>', esc_url( $detail_url ), (int) $subscription_id, $subscription ? esc_html( $this->subscriptions->display_name( $subscription ) ) : '<em>' . esc_html__( 'deleted', 'wc-subscription-team-access' ) . '</em>' );
			echo '<td>' . wp_kses_post( $this->user_link( (int) $row['owner_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $subscription ? $this->subscriptions->status_label( $subscription ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $allocation ? (string) $allocation->quota : '—' );
			if ( $subscription && null !== $this->quotas->override( $subscription ) ) {
				echo ' <span class="wcsta-badge">' . esc_html__( 'forced', 'wc-subscription-team-access' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $allocation ? (string) $allocation->used : '—' ) . '</td>';
			echo '<td>';
			if ( $allocation && $allocation->is_over_quota() ) {
				/* translators: %d: number of guests over quota. */
				echo '<span class="wcsta-badge wcsta-badge--error">' . esc_html( sprintf( __( 'Over quota: %d', 'wc-subscription-team-access' ), $allocation->overage() ) ) . '</span>';
			} else {
				echo esc_html( $allocation ? (string) $allocation->available() : '—' );
			}
			echo '</td>';
			printf( '<td>%d / %d / %d</td>', (int) $row['pending'], (int) $row['accepted'], (int) $row['suspended'] );
			echo '<td><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Details', 'wc-subscription-team-access' ) . '</a> ';
			$this->action_button( 'sync', __( 'Resynchronize', 'wc-subscription-team-access' ), $subscription_id );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$this->pagination( $result['total'], $paged, array( 's' => $search ) );

		echo '<p class="description">' . esc_html__( 'A catch-up synchronization runs every day. You can start one now.', 'wc-subscription-team-access' ) . '</p>';
		$this->action_button( 'sync_all', __( 'Resynchronize all shared subscriptions', 'wc-subscription-team-access' ), 0, 0, 'button' );
	}

	/**
	 * Subscription detail.
	 *
	 * @param int $subscription_id Subscription.
	 */
	private function render_subscription( int $subscription_id ): void {
		$subscription = $this->subscriptions->get( $subscription_id );
		$all_seats    = $this->seats->for_subscription( $subscription_id );

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All shared subscriptions', 'wc-subscription-team-access' ) . '</a></p>';

		if ( ! $subscription ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This subscription no longer exists. Its seats have been ended.', 'wc-subscription-team-access' ) . '</p></div>';
		} else {
			$summary = $this->team->summary( $subscription );
			$access  = $this->subscriptions->effective_access( $subscription );
			$plans   = $this->subscriptions->plan_ids( $subscription );

			printf(
				'<h2>%s <a href="%s">#%d</a></h2>',
				esc_html( $this->subscriptions->display_name( $subscription ) ),
				esc_url( (string) $subscription->get_edit_order_url() ),
				(int) $subscription_id
			);

			echo '<table class="form-table wcsta-facts"><tbody>';
			$this->fact( __( 'Holder', 'wc-subscription-team-access' ), $this->user_link( (int) $subscription->get_customer_id() ) );
			$this->fact( __( 'Subscription status', 'wc-subscription-team-access' ), esc_html( $this->subscriptions->status_label( $subscription ) ) );
			$this->fact( __( 'Guest access', 'wc-subscription-team-access' ), esc_html( $this->access_label( $access->state, $access->end_ts ) ) );
			$this->fact( __( 'Shared membership plans', 'wc-subscription-team-access' ), $this->plan_links( $plans ) );
			$this->fact(
				__( 'Seats', 'wc-subscription-team-access' ),
				esc_html(
					sprintf(
						/* translators: 1: used seats, 2: quota, 3: available seats. */
						__( '%1$d used of %2$d (%3$d available)', 'wc-subscription-team-access' ),
						$summary['used'],
						$summary['quota'],
						$summary['available']
					)
				) . ( $summary['over'] > 0 ? ' <span class="wcsta-badge wcsta-badge--error">' . esc_html(
					/* translators: %d: number of guests over quota. */
					sprintf( __( 'Over quota: %d', 'wc-subscription-team-access' ), $summary['over'] )
				) . '</span>' : '' )
			);
			echo '</tbody></table>';

			$this->render_quota_form( $subscription );

			echo '<p>';
			$this->action_button( 'sync', __( 'Resynchronize this subscription', 'wc-subscription-team-access' ), $subscription_id, 0, 'button' );
			echo '</p>';
		}

		echo '<h3>' . esc_html__( 'Guests', 'wc-subscription-team-access' ) . '</h3>';
		echo '<table class="widefat striped wcsta-table"><thead><tr>';
		foreach ( array(
			__( 'Email', 'wc-subscription-team-access' ),
			__( 'Account', 'wc-subscription-team-access' ),
			__( 'Status', 'wc-subscription-team-access' ),
			__( 'Invited on', 'wc-subscription-team-access' ),
			__( 'Accepted on', 'wc-subscription-team-access' ),
			__( 'Memberships', 'wc-subscription-team-access' ),
			__( 'Actions', 'wc-subscription-team-access' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $all_seats ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No guest.', 'wc-subscription-team-access' ) . '</td></tr>';
		}

		foreach ( array_reverse( $all_seats ) as $seat ) {
			echo '<tr>';
			echo '<td>' . esc_html( $seat->email ) . '</td>';
			echo '<td>' . wp_kses_post( $seat->user_id > 0 ? $this->user_link( $seat->user_id ) : '—' ) . '</td>';
			echo '<td><span class="wcsta-status wcsta-status--' . esc_attr( Labels::display_status( $seat ) ) . '">' . esc_html( Labels::status( $seat ) ) . '</span>';
			$reason = Labels::reason( $seat );
			if ( '' !== $reason ) {
				echo '<br><small>' . esc_html( $reason ) . '</small>';
			}
			if ( $seat->is_pending() && $seat->token_expires_at ) {
				/* translators: %s: date. */
				echo '<br><small>' . esc_html( sprintf( __( 'Expires on %s', 'wc-subscription-team-access' ), Dates::display( $seat->token_expires_at, true ) ) ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( Dates::display( $seat->created_at, true ) ) . '</td>';
			echo '<td>' . esc_html( Dates::display( $seat->accepted_at, true ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->seat_memberships( $seat ) ) . '</td>';
			echo '<td>';
			if ( $seat->is_live() ) {
				$label = $seat->is_pending() ? __( 'Cancel invitation', 'wc-subscription-team-access' ) : __( 'Revoke access', 'wc-subscription-team-access' );
				$this->action_button( 'revoke', $label, $subscription_id, $seat->id, 'button button-small', __( 'Confirm?', 'wc-subscription-team-access' ) );
			}
			if ( in_array( $seat->status, array( SeatStatus::PENDING, SeatStatus::EXPIRED ), true ) ) {
				echo ' ';
				$this->action_button( 'resend', __( 'Resend', 'wc-subscription-team-access' ), $subscription_id, $seat->id, 'button button-small' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $subscription ) {
			echo '<h3>' . esc_html__( 'Invite on behalf of the holder', 'wc-subscription-team-access' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcsta-inline-form">';
			$this->hidden_fields( 'invite', $subscription_id );
			echo '<input type="email" name="email" required class="regular-text" placeholder="' . esc_attr__( 'Email address', 'wc-subscription-team-access' ) . '"> ';
			submit_button( __( 'Send invitation', 'wc-subscription-team-access' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		$this->render_log_table( $this->logger->query( '', 20, 0, $subscription_id )['rows'], __( 'Recent events', 'wc-subscription-team-access' ) );
	}

	/**
	 * Enforced quota form.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private function render_quota_form( \WC_Subscription $subscription ): void {
		$override = $this->quotas->override( $subscription );
		$snapshot = $this->quotas->snapshot_value( $subscription );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcsta-inline-form">';
		$this->hidden_fields( 'override', $subscription->get_id() );
		echo '<label for="wcsta-override"><strong>' . esc_html__( 'Forced quota', 'wc-subscription-team-access' ) . '</strong></label> ';
		printf( '<input type="number" min="0" step="1" id="wcsta-override" name="quota_override" value="%s" class="small-text"> ', esc_attr( null === $override ? '' : (string) $override ) );
		submit_button( __( 'Save', 'wc-subscription-team-access' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html(
			sprintf(
				/* translators: %s: quota stored at purchase. */
				__( 'Leave empty to use the quota recorded at purchase or at the last plan change (%s).', 'wc-subscription-team-access' ),
				null === $snapshot ? '—' : (string) $snapshot
			)
		) . '</span>';
		echo '</form>';
	}

	private function render_logs(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'problems';
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable

		$filters = array(
			'problems' => __( 'Errors and warnings', 'wc-subscription-team-access' ),
			''         => __( 'All events', 'wc-subscription-team-access' ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $filters as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%s"%s>%s</a></li>',
				esc_url( self::url( array( 'tab' => 'logs', 'level' => '' === $key ? 'all' : $key ) ) ),
				( ( 'all' === $level ? '' : $level ) === $key ) ? ' class="current"' : '',
				esc_html( $label )
			);
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- items escaped above.
		echo '</ul><br class="clear">';

		$result = $this->logger->query( 'all' === $level ? '' : $level, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$this->render_log_table( $result['rows'], '' );
		$this->pagination( $result['total'], $paged, array( 'tab' => 'logs', 'level' => $level ) );
	}

	/**
	 * Log entries table.
	 *
	 * @param array<int, array<string, mixed>> $rows  Entries.
	 * @param string                           $title Title.
	 */
	private function render_log_table( array $rows, string $title ): void {
		if ( '' !== $title ) {
			echo '<h3>' . esc_html( $title ) . '</h3>';
		}
		echo '<table class="widefat striped wcsta-table"><thead><tr>';
		foreach ( array(
			__( 'Date', 'wc-subscription-team-access' ),
			__( 'Level', 'wc-subscription-team-access' ),
			__( 'Event', 'wc-subscription-team-access' ),
			__( 'Subscription', 'wc-subscription-team-access' ),
			__( 'User', 'wc-subscription-team-access' ),
			__( 'Message', 'wc-subscription-team-access' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nothing to show.', 'wc-subscription-team-access' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$subscription_id = (int) $row['subscription_id'];
			echo '<tr>';
			echo '<td>' . esc_html( Dates::display( Dates::from_mysql( (string) $row['created_at'] ), true ) ) . '</td>';
			echo '<td><span class="wcsta-level wcsta-level--' . esc_attr( (string) $row['level'] ) . '">' . esc_html( (string) $row['level'] ) . '</span></td>';
			echo '<td><code>' . esc_html( (string) $row['event'] ) . '</code></td>';
			echo '<td>' . ( $subscription_id > 0 ? '<a href="' . esc_url( self::url( array( 'subscription' => $subscription_id ) ) ) . '">#' . (int) $subscription_id . '</a>' : '—' ) . '</td>';
			echo '<td>' . wp_kses_post( (int) $row['user_id'] > 0 ? $this->user_link( (int) $row['user_id'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $row['message'] );
			if ( ! empty( $row['context'] ) ) {
				echo '<br><small><code>' . esc_html( (string) $row['context'] ) . '</code></small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves the enforced quota.
	 *
	 * @param int $subscription_id Subscription.
	 * @param int $actor           Administrator.
	 */
	private function handle_override( int $subscription_id, int $actor ): void {
		$subscription = $this->subscriptions->get( $subscription_id );
		if ( ! $subscription ) {
			$this->set_notice( TeamManager::error( 'not_found' )->get_error_message(), 'error' );
			return;
		}
		$raw   = isset( $_POST['quota_override'] ) ? sanitize_text_field( wp_unslash( $_POST['quota_override'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = '' === $raw ? null : max( 0, (int) $raw );

		$this->quotas->set_override( $subscription, $value, $actor );
		$this->reconciler->sync_subscription( $subscription_id, 'admin_override' );
		$this->set_notice( __( 'The quota has been updated.', 'wc-subscription-team-access' ), 'success' );
	}

	/**
	 * Memberships of a seat (links to the Memberships screen).
	 *
	 * @param Seat $seat Seat.
	 */
	private function seat_memberships( Seat $seat ): string {
		if ( $seat->user_id <= 0 ) {
			return '—';
		}
		$parts = array();
		foreach ( $this->grants->for_seat( $seat->id ) as $grant ) {
			$plan    = wc_memberships_get_membership_plan( $grant['plan_id'] );
			$name    = $plan ? $plan->get_name() : '#' . $grant['plan_id'];
			$managed = $this->memberships->managed_membership( $seat->user_id, $grant['plan_id'] );
			if ( $managed ) {
				$parts[] = sprintf( '<a href="%s">%s</a> (%s)', esc_url( (string) get_edit_post_link( $managed->get_id() ) ), esc_html( $name ), esc_html( (string) wc_memberships_get_user_membership_status_name( $managed->get_status() ) ) );
			} else {
				/* translators: %s: plan name. */
				$parts[] = esc_html( sprintf( __( '%s (covered by the guest\'s own membership)', 'wc-subscription-team-access' ), $name ) );
			}
		}
		return $parts ? implode( '<br>', $parts ) : '—';
	}

	/**
	 * Link to an account.
	 *
	 * @param int $user_id User.
	 */
	private function user_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '—';
		}
		return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user_id ) ), esc_html( $user->display_name . ' <' . $user->user_email . '>' ) );
	}

	/**
	 * Links to the plans.
	 *
	 * @param int[] $plan_ids Plans.
	 */
	private function plan_links( array $plan_ids ): string {
		if ( ! $plan_ids ) {
			return '<em>' . esc_html__( 'None: this subscription does not grant any membership plan.', 'wc-subscription-team-access' ) . '</em>';
		}
		$links = array();
		foreach ( $plan_ids as $plan_id ) {
			$plan    = wc_memberships_get_membership_plan( $plan_id );
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $plan_id ) ), esc_html( $plan ? $plan->get_name() : '#' . $plan_id ) );
		}
		return implode( ', ', $links );
	}

	/**
	 * Label of an access entitlement.
	 *
	 * @param string   $state  State.
	 * @param int|null $end_ts End.
	 */
	private function access_label( string $state, ?int $end_ts ): string {
		if ( 'active' === $state ) {
			return null === $end_ts
				? __( 'Active', 'wc-subscription-team-access' )
				/* translators: %s: date. */
				: sprintf( __( 'Active until %s', 'wc-subscription-team-access' ), Dates::display( $end_ts, true ) );
		}
		return 'paused' === $state ? __( 'Suspended', 'wc-subscription-team-access' ) : __( 'None', 'wc-subscription-team-access' );
	}

	/**
	 * Information row.
	 *
	 * @param string $label Label.
	 * @param string $html  Value (already-escaped HTML).
	 */
	private function fact( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $html ) . '</td></tr>';
	}

	/**
	 * Action button (POST form with nonce).
	 *
	 * @param string $op              Operation.
	 * @param string $label           Label.
	 * @param int    $subscription_id Subscription.
	 * @param int    $seat_id         Seat.
	 * @param string $class           CSS classes.
	 * @param string $confirm         Confirmation message.
	 */
	private function action_button( string $op, string $label, int $subscription_id, int $seat_id = 0, string $class = 'button button-small', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcsta-inline-form"' . ( '' !== $confirm ? ' data-wcsta-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$this->hidden_fields( $op, $subscription_id, $seat_id );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Common hidden fields.
	 *
	 * @param string $op              Operation.
	 * @param int    $subscription_id Subscription.
	 * @param int    $seat_id         Seat.
	 */
	private function hidden_fields( string $op, int $subscription_id, int $seat_id = 0 ): void {
		wp_nonce_field( 'wcsta_admin' );
		echo '<input type="hidden" name="action" value="wcsta_admin">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $subscription_id ) . '">';
		if ( $seat_id > 0 ) {
			echo '<input type="hidden" name="seat_id" value="' . esc_attr( (string) $seat_id ) . '">';
		}
	}

	/**
	 * Pagination.
	 *
	 * @param int                   $total Total.
	 * @param int                   $paged Current page.
	 * @param array<string, scalar> $args  Preserved arguments.
	 */
	private function pagination( int $total, int $paged, array $args ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			(string) paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', self::url( $args ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * Message after an operation.
	 *
	 * @param mixed  $result  Result (WP_Error on failure).
	 * @param string $success Success message.
	 */
	private function notice_result( mixed $result, string $success ): void {
		if ( is_wp_error( $result ) ) {
			$this->set_notice( $result->get_error_message(), 'error' );
			return;
		}
		if ( is_array( $result ) && isset( $result['mailed'] ) && ! $result['mailed'] ) {
			$this->set_notice( TeamManager::error( 'mail_failed' )->get_error_message(), 'warning' );
			return;
		}
		$this->set_notice( $success, 'success' );
	}

	/**
	 * Stores a message for the next display.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning.
	 */
	private function set_notice( string $message, string $type ): void {
		set_transient(
			'wcsta_admin_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function print_notice(): void {
		$key    = 'wcsta_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/** Banner shown if synchronization errors occurred recently. */
	private function print_error_banner(): void {
		$count = $this->logger->count_errors_since( time() - 7 * DAY_IN_SECONDS );
		if ( $count <= 0 ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of errors. */
					_n( '%d synchronization error in the last 7 days.', '%d synchronization errors in the last 7 days.', $count, 'wc-subscription-team-access' ),
					$count
				)
			),
			esc_url( self::url( array( 'tab' => 'logs' ) ) ),
			esc_html__( 'See the log', 'wc-subscription-team-access' )
		);
	}
}
