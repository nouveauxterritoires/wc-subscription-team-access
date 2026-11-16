<?php
/**
 * Business operations on guests: invite, cancel, revoke, replace, resend, accept.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\EmailAddress;
use WCSubscriptionTeamAccess\Domain\SeatAllocator;
use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Domain\Token;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Repository\Seat;
use WCSubscriptionTeamAccess\Repository\SeatRepository;
use WCSubscriptionTeamAccess\Support\Dates;
use WCSubscriptionTeamAccess\Support\Lock;
use WCSubscriptionTeamAccess\Support\RateLimiter;

/**
 * All operations that change seat occupancy run under the subscription lock: the quota check
 * and the write are atomic with respect to concurrent requests. The unique key
 * (subscription, email, live) additionally prevents any duplicate.
 */
final class TeamManager {

	/**
	 * Constructor.
	 *
	 * @param SeatRepository      $seats         Seats.
	 * @param SubscriptionManager $subscriptions Subscriptions.
	 * @param Reconciler          $reconciler    Synchronization.
	 * @param Emails              $emails        Emails.
	 * @param Logger              $logger        Logger.
	 */
	public function __construct(
		private readonly SeatRepository $seats,
		private readonly SubscriptionManager $subscriptions,
		private readonly Reconciler $reconciler,
		private readonly Emails $emails,
		private readonly Logger $logger
	) {}

	/** Registers the hooks. */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'on_user_deleted' ) );
	}

	/**
	 * Invites an address to a subscription.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param string $raw_email       Entered address.
	 * @param int    $actor_id        Actor (holder or administrator).
	 * @return array{seat: Seat, mailed: bool}|\WP_Error
	 */
	public function invite( int $subscription_id, string $raw_email, int $actor_id ): array|\WP_Error {
		$subscription = $this->authorize( $subscription_id, $actor_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$email = $this->validate_email( $subscription, $raw_email );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$eligible = $this->check_can_invite( $subscription );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		if ( ! $this->consume_mail_quota( $actor_id ) ) {
			return self::error( 'rate_limited' );
		}

		$token  = Token::generate();
		$result = Lock::run(
			"subscription_{$subscription_id}",
			function () use ( $subscription, $email, $actor_id, $token ) {
				$check = $this->check_seat_available( $subscription, $email );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				return $this->insert_pending( $subscription, $email, $actor_id, $token, 0 );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->info(
			'seat_invited',
			'Invitation created.',
			array(
				'subscription_id' => $subscription_id,
				'seat_id'         => $result->id,
				'user_id'         => $actor_id,
			)
		);

		return array(
			'seat'   => $result,
			'mailed' => $this->send_invitation( $result, $token, $subscription ),
		);
	}

	/**
	 * Removes a seat: cancels a pending invitation or revokes access.
	 *
	 * @param int $seat_id  Seat.
	 * @param int $actor_id Actor.
	 */
	public function remove( int $seat_id, int $actor_id ): Seat|\WP_Error {
		$seat = $this->seats->find( $seat_id );
		if ( ! $seat ) {
			return self::error( 'not_found' );
		}
		return $seat->is_pending() ? $this->cancel( $seat_id, $actor_id ) : $this->revoke( $seat_id, $actor_id );
	}

	/**
	 * Removes a seat without an actor check (GDPR erasure, system tasks).
	 *
	 * @param int $seat_id Seat.
	 */
	public function remove_as_system( int $seat_id ): Seat|\WP_Error {
		$seat = $this->seats->find( $seat_id );
		if ( ! $seat ) {
			return self::error( 'not_found' );
		}
		return $seat->is_pending()
			? $this->end_seat( $seat_id, 0, SeatStatus::PENDING, SeatStatus::CANCELLED, false )
			: $this->end_seat( $seat_id, 0, SeatStatus::ACCEPTED, SeatStatus::REVOKED, false );
	}

	/**
	 * Cancels a pending invitation (frees the seat).
	 *
	 * @param int $seat_id  Seat.
	 * @param int $actor_id Actor.
	 */
	public function cancel( int $seat_id, int $actor_id ): Seat|\WP_Error {
		return $this->end_seat( $seat_id, $actor_id, SeatStatus::PENDING, SeatStatus::CANCELLED );
	}

	/**
	 * Revokes a guest's access (frees the seat and removes only the rights granted by this seat).
	 *
	 * @param int $seat_id  Seat.
	 * @param int $actor_id Actor.
	 */
	public function revoke( int $seat_id, int $actor_id ): Seat|\WP_Error {
		return $this->end_seat( $seat_id, $actor_id, SeatStatus::ACCEPTED, SeatStatus::REVOKED );
	}

	/**
	 * Replaces a guest (or an invitation) with a new address, reassigning the seat.
	 *
	 * @param int    $seat_id   Replaced seat.
	 * @param string $raw_email New address.
	 * @param int    $actor_id  Actor.
	 * @return array{seat: Seat, mailed: bool}|\WP_Error New seat.
	 */
	public function replace( int $seat_id, string $raw_email, int $actor_id ): array|\WP_Error {
		$old = $this->seats->find( $seat_id );
		if ( ! $old || ! $old->is_live() ) {
			return self::error( 'not_found' );
		}

		$subscription = $this->authorize( $old->subscription_id, $actor_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$email = $this->validate_email( $subscription, $raw_email );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$eligible = $this->check_can_invite( $subscription, false );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		if ( ! $this->consume_mail_quota( $actor_id ) ) {
			return self::error( 'rate_limited' );
		}

		$token  = Token::generate();
		$result = Lock::run(
			"subscription_{$subscription->get_id()}",
			function () use ( $subscription, $old, $email, $actor_id, $token ) {
				$current = $this->seats->find( $old->id );
				if ( ! $current || ! $current->is_live() ) {
					return self::error( 'not_found' );
				}
				$check = $this->check_seat_available( $subscription, $email, $old->id );
				if ( is_wp_error( $check ) ) {
					return $check;
				}

				// The new seat is written before the old one ends: on failure, nothing changes.
				$new = $this->insert_pending( $subscription, $email, $actor_id, $token, $old->id );
				if ( is_wp_error( $new ) ) {
					return $new;
				}

				$this->mark_ended( $current, $current->is_pending() ? SeatStatus::CANCELLED : SeatStatus::REVOKED );
				$this->reconciler->sync_subscription( $subscription->get_id(), 'replace' );

				return $new;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->info(
			'seat_replaced',
			sprintf( 'Seat #%d replaced by seat #%d.', $old->id, $result->id ),
			array(
				'subscription_id' => $subscription->get_id(),
				'seat_id'         => $result->id,
				'user_id'         => $actor_id,
			)
		);

		return array(
			'seat'   => $result,
			'mailed' => $this->send_invitation( $result, $token, $subscription ),
		);
	}

	/**
	 * Resends an invitation (new token: the old link stops working).
	 * An expired invitation can be resent if a seat is available.
	 *
	 * @param int $seat_id  Seat.
	 * @param int $actor_id Actor.
	 * @return array{seat: Seat, mailed: bool}|\WP_Error
	 */
	public function resend( int $seat_id, int $actor_id ): array|\WP_Error {
		$seat = $this->seats->find( $seat_id );
		if ( ! $seat || ! in_array( $seat->status, array( SeatStatus::PENDING, SeatStatus::EXPIRED ), true ) || ! is_email( $seat->email ) || str_starts_with( $seat->email, 'anonymized-' ) ) {
			return self::error( 'not_resendable' );
		}

		$subscription = $this->authorize( $seat->subscription_id, $actor_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$eligible = $this->check_can_invite( $subscription, false );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$is_admin = $this->is_admin_actor( $actor_id, $subscription );
		if ( ! $is_admin ) {
			$min_interval = (int) Settings::get( 'resend_min_interval' ) * MINUTE_IN_SECONDS;
			if ( null !== $seat->last_sent_at && ( time() - $seat->last_sent_at ) < $min_interval ) {
				return self::error( 'resend_too_soon' );
			}
			if ( $seat->sent_count > (int) Settings::get( 'max_resends' ) ) {
				return self::error( 'resend_limit' );
			}
		}

		if ( ! $this->consume_mail_quota( $actor_id ) ) {
			return self::error( 'rate_limited' );
		}

		$token  = Token::generate();
		$result = Lock::run(
			"subscription_{$subscription->get_id()}",
			function () use ( $seat, $subscription, $token ) {
				$current = $this->seats->find( $seat->id );
				if ( ! $current || ! in_array( $current->status, array( SeatStatus::PENDING, SeatStatus::EXPIRED ), true ) ) {
					return self::error( 'not_resendable' );
				}

				if ( SeatStatus::EXPIRED === $current->status ) {
					$check = $this->check_seat_available( $subscription, $current->email );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
				}

				$updated = $this->seats->update(
					$current->id,
					array(
						'status'           => SeatStatus::PENDING,
						'live'             => 1,
						'token_hash'       => Token::hash( $token ),
						'token_expires_at' => Dates::to_mysql( time() + Settings::expiry_seconds() ),
						'ended_at'         => null,
					)
				);
				if ( ! $updated ) {
					return self::error( 'already_invited' );
				}
				return $this->seats->find( $current->id );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result instanceof Seat ) {
			return self::error( 'not_found' );
		}

		$this->logger->info(
			'seat_resent',
			'Invitation resent.',
			array(
				'subscription_id' => $subscription->get_id(),
				'seat_id'         => $result->id,
				'user_id'         => $actor_id,
			)
		);

		return array(
			'seat'   => $result,
			'mailed' => $this->send_invitation( $result, $token, $subscription ),
		);
	}

	/**
	 * Validates a token and returns the associated seat, without modifying anything (display).
	 *
	 * @param string $token Received token.
	 */
	public function find_invitation( string $token ): Seat|\WP_Error {
		if ( ! Token::is_well_formed( $token ) ) {
			return self::error( 'invalid_token' );
		}
		$seat = $this->seats->find_by_token_hash( Token::hash( $token ) );
		if ( ! $seat || ! $seat->is_pending() ) {
			return self::error( 'invalid_token' );
		}
		if ( $seat->is_token_expired( time() ) ) {
			return self::error( 'expired_token' );
		}
		$subscription = $this->subscriptions->get( $seat->subscription_id );
		if ( ! $subscription || $this->subscriptions->effective_access( $subscription )->is_none() ) {
			return self::error( 'subscription_inactive' );
		}
		return $seat;
	}

	/**
	 * Accepts an invitation for a logged-in account.
	 *
	 * @param string $token   Received token.
	 * @param int    $user_id Accepting account.
	 */
	public function accept( string $token, int $user_id ): Seat|\WP_Error {
		$seat = $this->find_invitation( $token );
		if ( is_wp_error( $seat ) ) {
			if ( 'wcsta_expired_token' === $seat->get_error_code() ) {
				$this->seats->expire_stale();
			}
			return $seat;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return self::error( 'not_logged_in' );
		}
		if ( ! EmailAddress::same( (string) $user->user_email, $seat->email ) ) {
			return self::error( 'email_mismatch' );
		}

		$subscription = $this->subscriptions->get( $seat->subscription_id );
		if ( ! $subscription ) {
			return self::error( 'subscription_inactive' );
		}
		if ( $this->subscriptions->is_owner( $subscription, $user_id ) ) {
			return self::error( 'self_invite' );
		}

		$result = Lock::run(
			"subscription_{$subscription->get_id()}",
			function () use ( $seat, $subscription, $user_id, $token ) {
				// Full revalidation under lock: a concurrent resend may have changed the token,
				// and the subscription may have become inactive since the first check.
				$current = $this->seats->find_by_token_hash( Token::hash( $token ) );
				if ( ! $current || $current->id !== $seat->id || ! $current->is_pending() || $current->is_token_expired( time() ) ) {
					return self::error( 'invalid_token' );
				}
				if ( $this->subscriptions->effective_access( $subscription )->is_none() ) {
					return self::error( 'subscription_inactive' );
				}
				if ( $this->seats->accepted_for_user( $subscription->get_id(), $user_id ) ) {
					return self::error( 'already_member' );
				}

				$allocation = $this->reconciler->allocation( $subscription );
				if ( SeatAllocator::POLICY_KEEP_ALL !== Settings::get( 'overage_policy' ) && ! $allocation->is_within( $current->id ) ) {
					return self::error( 'no_seat' );
				}

				$this->seats->update(
					$current->id,
					array(
						'status'           => SeatStatus::ACCEPTED,
						'user_id'          => $user_id,
						'accepted_at'      => Dates::now(),
						'token_hash'       => null,
						'token_expires_at' => null,
					)
				);

				$this->reconciler->sync_subscription( $subscription->get_id(), 'accept' );

				return $this->seats->find( $current->id );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result instanceof Seat ) {
			return self::error( 'not_found' );
		}

		$this->logger->info(
			'seat_accepted',
			'Invitation accepted.',
			array(
				'subscription_id' => $subscription->get_id(),
				'seat_id'         => $result->id,
				'user_id'         => $user_id,
			)
		);

		if ( 'yes' === Settings::get( 'notify_owner_on_accept' ) ) {
			$this->emails->send_accepted( $result, $subscription );
		}

		return $result;
	}

	/**
	 * Account deleted: its guest seats end and its rights sources disappear.
	 *
	 * @param int $user_id Deleted account.
	 */
	public function on_user_deleted( $user_id ): void {
		$user_id = (int) $user_id;
		foreach ( $this->seats->for_user_or_email( $user_id, '' ) as $seat ) {
			if ( $seat->is_accepted() ) {
				$this->mark_ended( $seat, SeatStatus::REVOKED );
				$this->reconciler->sync_subscription( $seat->subscription_id, 'user_deleted' );
			}
		}
	}

	/**
	 * Summary of a subscription for display.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @return array{quota:int, used:int, available:int, over:int, can_invite:bool, invitable:bool, seats: Seat[], history: Seat[]}
	 */
	public function summary( \WC_Subscription $subscription ): array {
		$this->seats->expire_stale( $subscription->get_id() );

		$all        = $this->seats->for_subscription( $subscription->get_id() );
		$live       = array_values( array_filter( $all, static fn( Seat $seat ): bool => $seat->is_live() ) );
		$history    = array_reverse( array_values( array_filter( $all, static fn( Seat $seat ): bool => ! $seat->is_live() ) ) );
		$allocation = $this->reconciler->allocation( $subscription, $live );
		$active     = $this->subscriptions->effective_access( $subscription )->is_active();

		return array(
			'quota'      => $allocation->quota,
			'used'       => $allocation->used,
			'available'  => $allocation->available(),
			'over'       => $allocation->overage(),
			'can_invite' => $active && $allocation->can_invite(),
			'invitable'  => $active,
			'seats'      => $live,
			'history'    => array_slice( $history, 0, 10 ),
		);
	}

	/**
	 * Translated error message.
	 *
	 * @param string $code Short code.
	 */
	public static function error( string $code ): \WP_Error {
		$messages = array(
			'not_found'             => __( 'This guest could not be found.', 'wc-subscription-team-access' ),
			'forbidden'             => __( 'You are not allowed to manage the guests of this subscription.', 'wc-subscription-team-access' ),
			'not_eligible'          => __( 'This subscription does not allow inviting guests.', 'wc-subscription-team-access' ),
			'subscription_inactive' => __( 'The subscription that shares this access is not active.', 'wc-subscription-team-access' ),
			'invalid_email'         => __( 'Please enter a valid email address.', 'wc-subscription-team-access' ),
			'self_invite'           => __( 'The subscription holder already has access and cannot be invited.', 'wc-subscription-team-access' ),
			'already_invited'       => __( 'This email address has already been invited to this subscription.', 'wc-subscription-team-access' ),
			'already_member'        => __( 'This person already has access through this subscription.', 'wc-subscription-team-access' ),
			'no_seat'               => __( 'No guest seat is available on this subscription.', 'wc-subscription-team-access' ),
			'over_quota'            => __( 'The number of guests exceeds the quota of this subscription. Remove guests before inviting new ones.', 'wc-subscription-team-access' ),
			'rate_limited'          => __( 'Too many invitations have been sent recently. Please try again later.', 'wc-subscription-team-access' ),
			'resend_too_soon'       => __( 'This invitation was sent a few minutes ago. Please wait before sending it again.', 'wc-subscription-team-access' ),
			'resend_limit'          => __( 'This invitation has been sent too many times.', 'wc-subscription-team-access' ),
			'not_resendable'        => __( 'Only pending or expired invitations can be sent again.', 'wc-subscription-team-access' ),
			'invalid_token'         => __( 'This invitation link is no longer valid: it may have already been used, cancelled or replaced by a more recent invitation.', 'wc-subscription-team-access' ),
			'expired_token'         => __( 'This invitation has expired. Ask the person who invited you to send it again.', 'wc-subscription-team-access' ),
			'email_mismatch'        => __( 'This invitation was sent to an address other than your account\'s email.', 'wc-subscription-team-access' ),
			'not_logged_in'         => __( 'Please log in to accept this invitation.', 'wc-subscription-team-access' ),
			'mail_failed'           => __( 'The invitation was saved but the email could not be sent. Use "Resend" to try again.', 'wc-subscription-team-access' ),
		);

		return new \WP_Error( 'wcsta_' . $code, $messages[ $code ] ?? __( 'An error occurred.', 'wc-subscription-team-access' ) );
	}

	/**
	 * Loads the subscription and checks the actor's permissions.
	 *
	 * @param int $subscription_id Subscription.
	 * @param int $actor_id        Actor.
	 */
	private function authorize( int $subscription_id, int $actor_id ): \WC_Subscription|\WP_Error {
		$subscription = $this->subscriptions->get( $subscription_id );
		// Same message for "not found" and "forbidden": does not reveal whether a subscription exists.
		if ( ! $subscription || ! $this->subscriptions->can_manage( $subscription, $actor_id ) ) {
			return self::error( 'forbidden' );
		}
		return $subscription;
	}

	/**
	 * Validates the invited address.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $raw_email    Entered address.
	 */
	private function validate_email( \WC_Subscription $subscription, string $raw_email ): string|\WP_Error {
		$email = EmailAddress::normalize( sanitize_email( $raw_email ) );
		if ( '' === $email || strlen( $email ) > EmailAddress::MAX_LENGTH || ! is_email( $email ) ) {
			return self::error( 'invalid_email' );
		}

		$owner = get_userdata( (int) $subscription->get_customer_id() );
		if ( EmailAddress::same( $email, (string) $subscription->get_billing_email() ) || ( $owner && EmailAddress::same( $email, (string) $owner->user_email ) ) ) {
			return self::error( 'self_invite' );
		}

		return $email;
	}

	/**
	 * The subscription allows invitations (positive quota and active access).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param bool             $need_quota   Require a strictly positive quota.
	 */
	private function check_can_invite( \WC_Subscription $subscription, bool $need_quota = true ): bool|\WP_Error {
		$quota = $this->reconciler->allocation( $subscription, array() )->quota;
		if ( $need_quota && $quota <= 0 ) {
			return self::error( 'not_eligible' );
		}
		if ( ! $this->subscriptions->effective_access( $subscription )->is_active() ) {
			return self::error( 'subscription_inactive' );
		}
		return true;
	}

	/**
	 * Checks, under lock, that a seat is available for this address.
	 *
	 * @param \WC_Subscription $subscription    Subscription.
	 * @param string           $email           Normalized address.
	 * @param int              $exclude_seat_id Ignored seat (replacement).
	 */
	private function check_seat_available( \WC_Subscription $subscription, string $email, int $exclude_seat_id = 0 ): bool|\WP_Error {
		$this->seats->expire_stale( $subscription->get_id() );

		$existing = $this->seats->live_for_email( $subscription->get_id(), $email );
		if ( $existing && $existing->id !== $exclude_seat_id ) {
			return self::error( 'already_invited' );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			if ( $this->subscriptions->is_owner( $subscription, (int) $user->ID ) ) {
				return self::error( 'self_invite' );
			}
			$accepted = $this->seats->accepted_for_user( $subscription->get_id(), (int) $user->ID );
			if ( $accepted && $accepted->id !== $exclude_seat_id ) {
				return self::error( 'already_member' );
			}
		}

		$live = array_values(
			array_filter(
				$this->seats->live_for_subscription( $subscription->get_id() ),
				static fn( Seat $seat ): bool => $seat->id !== $exclude_seat_id
			)
		);

		$allocation = $this->reconciler->allocation( $subscription, $live );
		if ( $allocation->is_over_quota() ) {
			return self::error( 'over_quota' );
		}
		if ( ! $allocation->can_invite() ) {
			return self::error( 'no_seat' );
		}

		return true;
	}

	/**
	 * Writes a pending invitation.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $email        Normalized address.
	 * @param int              $actor_id     Actor.
	 * @param string           $token        Plain-text token (only its hash is stored).
	 * @param int              $replaced_id  Replaced seat.
	 */
	private function insert_pending( \WC_Subscription $subscription, string $email, int $actor_id, string $token, int $replaced_id ): Seat|\WP_Error {
		$id = $this->seats->insert(
			array(
				'subscription_id'  => $subscription->get_id(),
				'owner_id'         => (int) $subscription->get_customer_id(),
				'email'            => $email,
				'status'           => SeatStatus::PENDING,
				'access'           => SeatStatus::ACCESS_NONE,
				'token_hash'       => Token::hash( $token ),
				'token_expires_at' => Dates::to_mysql( time() + Settings::expiry_seconds() ),
				'replaced_seat_id' => $replaced_id,
				'created_by'       => $actor_id,
			)
		);

		if ( is_wp_error( $id ) ) {
			if ( 'wcsta_duplicate' === $id->get_error_code() ) {
				return self::error( 'already_invited' );
			}
			$this->logger->error( 'seat_insert_failed', $id->get_error_message(), array( 'subscription_id' => $subscription->get_id() ) );
			return new \WP_Error( 'wcsta_db_error', __( 'An error occurred.', 'wc-subscription-team-access' ) );
		}

		$seat = $this->seats->find( $id );
		return $seat ?? self::error( 'not_found' );
	}

	/**
	 * Ends a seat (cancellation or revocation) and resynchronizes.
	 *
	 * @param int    $seat_id   Seat.
	 * @param int    $actor_id  Actor.
	 * @param string $expected  Expected status.
	 * @param string $new_state Final status.
	 * @param bool   $authorize Check the actor's permissions.
	 */
	private function end_seat( int $seat_id, int $actor_id, string $expected, string $new_state, bool $authorize = true ): Seat|\WP_Error {
		$seat = $this->seats->find( $seat_id );
		if ( ! $seat ) {
			return self::error( 'not_found' );
		}

		$subscription = $this->subscriptions->get( $seat->subscription_id );
		$allowed      = ! $authorize || ( $subscription ? $this->subscriptions->can_manage( $subscription, $actor_id ) : user_can( $actor_id, 'manage_woocommerce' ) );
		if ( ! $allowed ) {
			return self::error( 'forbidden' );
		}

		$result = Lock::run(
			"subscription_{$seat->subscription_id}",
			function () use ( $seat_id, $expected, $new_state ) {
				$current = $this->seats->find( $seat_id );
				if ( ! $current || $current->status !== $expected ) {
					return self::error( 'not_found' );
				}
				$this->mark_ended( $current, $new_state );
				$sync = $this->reconciler->sync_subscription( $current->subscription_id, $new_state );
				if ( is_wp_error( $sync ) ) {
					// The seat is properly ended; the removal of rights is replayed as a background task.
					$this->logger->warning(
						'seat_end_sync_deferred',
						'Seat ended but synchronization failed; a retry has been scheduled.',
						array(
							'subscription_id' => $current->subscription_id,
							'seat_id'         => $current->id,
						)
					);
				}
				return $this->seats->find( $seat_id );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result instanceof Seat ) {
			return self::error( 'not_found' );
		}

		$this->logger->info(
			'seat_' . $new_state,
			sprintf( 'Seat %s.', $new_state ),
			array(
				'subscription_id' => $result->subscription_id,
				'seat_id'         => $result->id,
				'user_id'         => $actor_id,
			)
		);

		return $result;
	}

	/**
	 * Writes the end of a seat.
	 *
	 * @param Seat   $seat   Seat.
	 * @param string $status Final status.
	 */
	private function mark_ended( Seat $seat, string $status ): void {
		$this->seats->update(
			$seat->id,
			array(
				'status'           => $status,
				'live'             => null,
				'token_hash'       => null,
				'token_expires_at' => null,
				'access'           => SeatStatus::ACCESS_NONE,
				'access_reason'    => SeatStatus::REASON_NONE,
				'ended_at'         => Dates::now(),
			)
		);
	}

	/**
	 * Sends the invitation email and updates the send counters.
	 *
	 * @param Seat             $seat         Seat.
	 * @param string           $token        Plain-text token.
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private function send_invitation( Seat $seat, string $token, \WC_Subscription $subscription ): bool {
		$sent = $this->emails->send_invitation( $seat, $token, $subscription );

		$this->seats->update(
			$seat->id,
			array(
				'sent_count'   => $seat->sent_count + 1,
				'last_sent_at' => Dates::now(),
			)
		);

		if ( ! $sent ) {
			$this->logger->warning(
				'invitation_mail_failed',
				'Invitation email could not be sent.',
				array(
					'subscription_id' => $subscription->get_id(),
					'seat_id'         => $seat->id,
				)
			);
		}
		return $sent;
	}

	/**
	 * Limits the number of invitation emails per actor per hour (except administrators).
	 *
	 * @param int $actor_id Actor.
	 */
	private function consume_mail_quota( int $actor_id ): bool {
		if ( user_can( $actor_id, 'manage_woocommerce' ) ) {
			return true;
		}
		return RateLimiter::hit( 'invite_mail_' . $actor_id, (int) Settings::get( 'max_invites_per_hour' ), HOUR_IN_SECONDS );
	}

	/**
	 * The actor acts as an administrator (not as the holder).
	 *
	 * @param int              $actor_id     Actor.
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private function is_admin_actor( int $actor_id, \WC_Subscription $subscription ): bool {
		return ! $this->subscriptions->is_owner( $subscription, $actor_id ) && user_can( $actor_id, 'manage_woocommerce' );
	}
}
