<?php
/**
 * "Team access" meta box on the subscription edit screen (HPOS compatible).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Admin;

use WCSubscriptionTeamAccess\Labels;
use WCSubscriptionTeamAccess\QuotaManager;
use WCSubscriptionTeamAccess\TeamManager;

/**
 * Seat summary and link to the detail.
 */
final class SubscriptionMetaBox {

	/**
	 * Constructor.
	 *
	 * @param TeamManager  $team   Operations.
	 * @param QuotaManager $quotas Quotas.
	 */
	public function __construct(
		private readonly TeamManager $team,
		private readonly QuotaManager $quotas
	) {}

	/** Hooks into WordPress. */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 40 );
	}

	/** Registers the meta box for the subscriptions screen (CPT and HPOS). */
	public function add(): void {
		$screens = array( 'shop_subscription' );
		if ( function_exists( 'wcs_get_page_screen_id' ) ) {
			$screens[] = wcs_get_page_screen_id( 'shop_subscription' );
		} elseif ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop_subscription' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'wcsta-team-access', __( 'Team access', 'wc-subscription-team-access' ), array( $this, 'render' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Meta box content.
	 *
	 * @param \WP_Post|\WC_Subscription $object Subscription or post.
	 */
	public function render( $object ): void {
		$subscription = $object instanceof \WC_Subscription ? $object : ( $object instanceof \WP_Post ? wcs_get_subscription( $object->ID ) : null );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$summary = $this->team->summary( $subscription );
		$forced  = null !== $this->quotas->override( $subscription );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: used seats, 2: quota. */
				__( '%1$d guest seat(s) used of %2$d.', 'wc-subscription-team-access' ),
				$summary['used'],
				$summary['quota']
			)
		) . ( $forced ? ' <em>' . esc_html__( '(forced quota)', 'wc-subscription-team-access' ) . '</em>' : '' ) . '</p>';

		if ( $summary['over'] > 0 ) {
			echo '<p style="color:#b32d2e">' . esc_html(
				sprintf(
					/* translators: %d: number of guests over quota. */
					__( 'Over quota: %d', 'wc-subscription-team-access' ),
					$summary['over']
				)
			) . '</p>';
		}

		if ( $summary['seats'] ) {
			echo '<ul>';
			foreach ( $summary['seats'] as $seat ) {
				echo '<li>' . esc_html( $seat->email ) . ' — <em>' . esc_html( Labels::status( $seat ) ) . '</em></li>';
			}
			echo '</ul>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( AdminPage::url( array( 'subscription' => $subscription->get_id() ) ) ),
			esc_html__( 'Manage guests', 'wc-subscription-team-access' )
		);
	}
}
