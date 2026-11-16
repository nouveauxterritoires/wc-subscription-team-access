<?php
/**
 * Background jobs via Action Scheduler (bundled with WooCommerce).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

/**
 * Schedules the catch-up sync and asynchronous jobs.
 */
final class Scheduler {

	public const GROUP              = 'wc-subscription-team-access';
	public const DAILY_HOOK         = 'wcsta_daily_maintenance';
	public const SYNC_SUBSCRIPTION  = 'wcsta_sync_subscription';
	public const SYNC_USER_PLAN     = 'wcsta_sync_user_plan';
	public const SYNC_BATCH         = 'wcsta_sync_batch';
	private const BATCH_SIZE        = 100;

	public function __construct( private readonly Reconciler $reconciler ) {}

	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'ensure_daily' ) );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
		add_action( self::SYNC_BATCH, array( $this, 'run_batch' ) );
		add_action( self::SYNC_SUBSCRIPTION, array( $this, 'run_subscription' ) );
		add_action( self::SYNC_USER_PLAN, array( $this, 'run_user_plan' ), 10, 2 );
	}

	/** Schedules the daily maintenance if it is not already scheduled. */
	public function ensure_daily(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::DAILY_HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP );
	}

	/** Daily maintenance: expirations, data minimization, purge, then batched resync. */
	public function run_daily(): void {
		$this->reconciler->maintenance();
		$this->enqueue_batch( 0 );
	}

	/**
	 * Syncs a batch of subscriptions, then schedules the next batch.
	 *
	 * @param int $after_id Last subscription processed.
	 */
	public function run_batch( $after_id = 0 ): void {
		$ids = $this->reconciler->subscription_ids( (int) $after_id, self::BATCH_SIZE );
		foreach ( $ids as $id ) {
			$this->reconciler->sync_subscription( $id, 'catch_up' );
		}
		if ( count( $ids ) === self::BATCH_SIZE ) {
			$this->enqueue_batch( (int) end( $ids ) );
			return;
		}
		$this->reconciler->sweep_orphan_memberships();
	}

	/**
	 * Asynchronous sync of a subscription.
	 *
	 * @param int $subscription_id Subscription.
	 */
	public function run_subscription( $subscription_id ): void {
		$this->reconciler->sync_subscription( (int) $subscription_id, 'async' );
	}

	/**
	 * Asynchronous sync of a user / plan pair.
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan.
	 */
	public function run_user_plan( $user_id, $plan_id ): void {
		$this->reconciler->sync_user_plan( (int) $user_id, (int) $plan_id );
	}

	/**
	 * Schedules a batch.
	 *
	 * @param int $after_id Starting point.
	 */
	public function enqueue_batch( int $after_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->run_batch( $after_id );
			return;
		}
		as_enqueue_async_action( self::SYNC_BATCH, array( $after_id ), self::GROUP );
	}

	/**
	 * Schedules a subscription sync (deduplicated).
	 *
	 * @param int $subscription_id Subscription.
	 */
	public static function enqueue_subscription( int $subscription_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::SYNC_SUBSCRIPTION, array( $subscription_id ), self::GROUP, true );
	}

	/**
	 * Schedules a user / plan sync (deduplicated).
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan.
	 */
	public static function enqueue_user_plan( int $user_id, int $plan_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::SYNC_USER_PLAN, array( $user_id, $plan_id ), self::GROUP, true );
	}
}
