<?php
/**
 * Integration scenarios (spec §11 and acceptance criteria §13).
 *
 * Usage: wp eval-file tests/integration/scenarios.php <fixtures-file.json>
 *
 * @package ExprimeTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

require_once __DIR__ . '/lib.php';

use Exprime\TeamAccess\AccountEndpoint;
use Exprime\TeamAccess\Domain\SeatStatus;
use Exprime\TeamAccess\Domain\Token;
use Exprime\TeamAccess\Settings;
use Exprime\TeamAccess\Support\Dates;

$t    = new EXPRIME_TEAM_ACCESS_IT( (string) ( $args[0] ?? '' ) );
$team = $t->plugin()->team();
$sync = $t->plugin()->reconciler();

WP_CLI::log( 'Exprime Team Access — integration scenarios' );

// ---------------------------------------------------------------------------------------------
$t->test(
	'Subscription without guest seats: invitation refused, no "My guests" menu',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'owner0' );
		$sub   = $t->purchase( $owner, $t->fixtures['p0'] );
		$t->same( 0, $t->plugin()->quotas()->quota( $sub ), 'Quota' );
		$t->error( $team->invite( $sub->get_id(), 'someone@example.test', $owner ), 'not_eligible' );
		$t->same( array(), $t->plugin()->subscriptions()->team_subscriptions_for( $owner ), 'Shareable subscriptions' );
		$t->ok( $t->is_member( $owner ), 'The holder keeps their membership' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Subscription with 5 seats: quota frozen at purchase (catalog changed afterwards)',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'owner5' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->same( 5, $t->plugin()->quotas()->quota( $sub ), 'Quota at purchase' );

		$product = wc_get_product( $t->fixtures['p5'] );
		$product->update_meta_data( '_exprime_team_max_guests', '9' );
		$product->save();
		try {
			$t->same( 5, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'The quota does not follow the catalog' );
		} finally {
			$product->update_meta_data( '_exprime_team_max_guests', '5' );
			$product->save();
		}
		$t->ok( in_array( $t->plan(), $t->plugin()->subscriptions()->plan_ids( $sub ), true ), 'Shared plan identified' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Inviting a new user: email without password, account created, premium access',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerN' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$email = EXPRIME_TEAM_ACCESS_IT::PREFIX . 'new-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';

		$seat = $t->invite( $sub, $email );
		$t->same( SeatStatus::PENDING, $seat->status, 'Initial status' );

		$mail = end( $t->mails );
		$t->ok( str_contains( (string) $mail['message'], 'token=' ), 'Acceptance link in the email' );
		$t->ok( ! preg_match( '/mot de passe\s*:|password\s*:/i', (string) $mail['message'] ), 'No password in the email' );

		$token = $t->token_for( $email );
		$t->ok( null === $t->plugin()->seats()->find_by_token_hash( $token ), 'The token is not stored in clear text' );
		$t->ok( null !== $t->plugin()->seats()->find_by_token_hash( Token::hash( $token ) ), 'Only its hash is stored' );

		// Account creation as done by the invitation page (email address imposed by the invitation).
		$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
		$t->ok( ! is_wp_error( $user_id ), 'Account created' );
		$t->created_users[] = (int) $user_id;

		$t->ok( ! $t->is_member( (int) $user_id ), 'No access before acceptance' );
		$accepted = $t->success( $team->accept( $token, (int) $user_id ), 'Acceptance' );
		$t->same( SeatStatus::ACCEPTED, $accepted->status, 'Accepted status' );
		$t->same( SeatStatus::ACCESS_ACTIVE, $accepted->access, 'Active access' );
		$t->ok( $t->is_member( (int) $user_id ), 'Active plan member (Memberships)' );
		$t->ok( $t->sees_restricted_content( (int) $user_id ), 'Sees the [wcm_restrict] content' );
		$t->same( 1, $t->managed_count( (int) $user_id ), 'A single managed membership' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Inviting an existing user and notifying the holder',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerE' );
		$guest = $t->user( 'existing' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->ok( ! $t->sees_restricted_content( $guest ), 'No access before' );

		$before = count( $t->mails );
		$t->invite_and_accept( $sub, $guest );
		$t->ok( $t->is_member( $guest ), 'Access granted' );
		$t->ok( $t->sees_restricted_content( $guest ), 'Restricted content visible' );

		$to_owner = array_filter( array_slice( $t->mails, $before ), static fn( $m ) => $t->mail_is_for( $m, $t->email( $owner ) ) );
		$t->ok( count( $to_owner ) >= 1, 'The holder is notified of the acceptance' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Account email different from the invitation: refused',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerM' );
		$other = $t->user( 'other' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$email = EXPRIME_TEAM_ACCESS_IT::PREFIX . 'target-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$t->invite( $sub, $email );
		$t->error( $team->accept( $t->token_for( $email ), $other ), 'email_mismatch' );
		$t->ok( ! $t->is_member( $other ), 'No access granted' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Accepting an expired invitation: refused and seat released',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerX' );
		$guest = $t->user( 'late' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite( $sub, $t->email( $guest ) );
		$t->plugin()->seats()->update(
			$seat->id,
			array(
				'token_expires_at' => Dates::to_mysql( time() - 60 ),
				'last_sent_at'     => Dates::to_mysql( time() - 3600 ),
			)
		);

		$t->error( $team->accept( $t->token_for( $t->email( $guest ) ), $guest ), 'expired_token' );
		$t->same( SeatStatus::EXPIRED, $t->seat( $seat->id )->status, 'Invitation expired' );
		$t->same( 0, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'The seat is released' );

		// An expired invitation can be resent.
		$t->success( $team->resend( $seat->id, $owner ), 'Resend' );
		$t->success( $team->accept( $t->token_for( $t->email( $guest ) ), $guest ), 'Acceptance after resend' );
		$t->ok( $t->is_member( $guest ), 'Access after resend' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Invitation already used or replaced by a resend: invalid link',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerU' );
		$guest = $t->user( 'used' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite( $sub, $t->email( $guest ) );
		$token = $t->token_for( $t->email( $guest ) );
		$t->success( $team->accept( $token, $guest ), 'First acceptance' );
		$t->error( $team->accept( $token, $guest ), 'invalid_token' );

		$guest2 = $t->user( 'resent' );
		$seat   = $t->invite( $sub, $t->email( $guest2 ) );
		$old    = $t->token_for( $t->email( $guest2 ) );
		$t->plugin()->seats()->update( $seat->id, array( 'last_sent_at' => Dates::to_mysql( time() - 3600 ) ) );
		$t->success( $team->resend( $seat->id, $owner ), 'Resend' );
		$t->error( $team->accept( $old, $guest2 ), 'invalid_token' );
		$t->success( $team->accept( $t->token_for( $t->email( $guest2 ) ), $guest2 ), 'The new link works' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Criteria 1 to 6: 5 guests accepted, premium access, sixth invitation refused',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner  = $t->user( 'ownerA' );
		$sub    = $t->purchase( $owner, $t->fixtures['p5'] );
		$guests = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$guests[] = $guest = $t->user( "g{$i}" );
			$t->invite_and_accept( $sub, $guest );
		}
		foreach ( $guests as $guest ) {
			$t->ok( $t->is_member( $guest ), "Guest {$guest} active" );
		}
		$summary = $team->summary( $t->reload( $sub->get_id() ) );
		$t->same( 5, $summary['used'], 'Seats used' );
		$t->same( 0, $summary['available'], 'Seats available' );
		$t->error( $team->invite( $sub->get_id(), 'sixth@example.test', $owner ), 'no_seat' );
		$t->ok( $t->is_member( $owner ), 'The holder does not use a seat and keeps their access' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'A pending invitation reserves a seat',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerP' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		for ( $i = 1; $i <= 5; $i++ ) {
			$t->invite( $sub, "pending{$i}-" . $sub->get_id() . '@example.test' );
		}
		$t->error( $team->invite( $sub->get_id(), 'one-more@example.test', $owner ), 'no_seat' );
		$t->error( $team->invite( $sub->get_id(), 'pending1-' . $sub->get_id() . '@example.test', $owner ), 'already_invited' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Revoking a guest: only their access disappears, the seat is released',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerR' );
		$a     = $t->user( 'keep' );
		$b     = $t->user( 'revoked' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $a );
		$seat_b = $t->invite_and_accept( $sub, $b );

		$result = $t->success( $team->remove( $seat_b->id, $owner ), 'Removal' );
		$t->same( SeatStatus::REVOKED, $result->status, 'Revoked status' );
		$t->ok( ! $t->is_member( $b ), 'The revoked guest loses access' );
		$t->ok( ! $t->has_active_member_role( $b, false ), 'Active member role removed (native Memberships transition)' );
		$t->ok( ! $t->sees_restricted_content( $b ), 'Restricted content hidden' );
		$t->same( 0, $t->managed_count( $b ), 'Managed membership deleted' );
		$t->ok( $t->is_member( $a ), 'The other guests keep their access' );
		$t->ok( $t->is_member( $owner ), 'The holder keeps access' );
		$t->same( 1, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'Seat released' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Criterion 7: replacing a guest and reassigning their seat',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerRe' );
		$old   = $t->user( 'replaced' );
		$new   = $t->user( 'replacement' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		for ( $i = 1; $i <= 4; $i++ ) {
			$t->invite( $sub, "filler{$i}-" . $sub->get_id() . '@example.test' );
		}
		$seat = $t->invite_and_accept( $sub, $old );
		$t->same( 5, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'Quota full' );

		$result = $t->success( $team->replace( $seat->id, $t->email( $new ), $owner ), 'Replacement' );
		$t->same( SeatStatus::REVOKED, $t->seat( $seat->id )->status, 'Former guest revoked' );
		$t->same( $seat->id, $result['seat']->replaced_seat_id, 'Replacement link' );
		$t->ok( ! $t->is_member( $old ), 'The former guest loses access' );
		$t->same( 5, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'The seat is reassigned' );
		$t->success( $team->accept( $t->token_for( $t->email( $new ) ), $new ), 'Acceptance by the replacement' );
		$t->ok( $t->is_member( $new ), 'The replacement has access' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Criteria 8 and 9: suspension (on-hold) then reactivation of the subscription',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerH' );
		$guest = $t->user( 'held' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );

		$t->reload( $sub->get_id() )->update_status( 'on-hold' );
		$t->ok( ! $t->is_member( $guest ), 'Guest access suspended' );
		$t->same( 'paused', $t->managed( $guest )->get_status(), 'Managed membership paused' );
		$t->same( SeatStatus::ACCESS_SUSPENDED, $t->seat( $seat->id )->access, 'Seat displayed as "access suspended"' );
		$t->same( SeatStatus::REASON_SUBSCRIPTION_PAUSED, $t->seat( $seat->id )->access_reason, 'Reason' );

		$t->reload( $sub->get_id() )->update_status( 'active' );
		$t->ok( $t->is_member( $guest ), 'Guest access restored' );
		$t->same( 'active', $t->managed( $guest )->get_status(), 'Managed membership active' );
		$t->same( 1, $t->managed_count( $guest ), 'No duplicate after reactivation' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Failed then successful renewal',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerRn' );
		$guest = $t->user( 'renew' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$renewal = wcs_create_renewal_order( $t->reload( $sub->get_id() ) );
		$t->created_orders[] = $renewal->get_id();
		$t->reload( $sub->get_id() )->payment_failed();
		$t->same( 'on-hold', $t->reload( $sub->get_id() )->get_status(), 'Subscription suspended after failure' );
		$t->ok( ! $t->is_member( $guest ), 'Access suspended after failure' );

		$renewal = wc_get_order( $renewal->get_id() );
		$renewal->payment_complete();
		$t->same( 'active', $t->reload( $sub->get_id() )->get_status(), 'Subscription reactivated' );
		$t->ok( $t->is_member( $guest ), 'Access restored after payment' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Criterion 10: cancellation with paid period remaining, revocation only at the end',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerC' );
		$guest = $t->user( 'cancel' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$t->reload( $sub->get_id() )->cancel_order();
		$sub = $t->reload( $sub->get_id() );
		$t->same( 'pending-cancel', $sub->get_status(), 'Subscription pending cancellation' );
		$end = (int) $sub->get_time( 'end' );
		$t->ok( $end > time(), 'Future end date' );
		$t->ok( $t->is_member( $guest ), 'The guest keeps access during the paid period' );
		$t->same( $end, (int) $t->managed( $guest )->get_end_date( 'timestamp', false ), 'Guest membership end = end of paid period' );
		$t->same( $t->is_member( $owner ), $t->is_member( $guest ), 'Same access as the holder' );

		// End of period: Subscriptions moves the subscription to "cancelled".
		$sub->update_status( 'cancelled' );
		$t->ok( ! $t->is_member( $guest ), 'Access revoked at the end of the period' );
		$t->same( 0, $t->managed_count( $guest ), 'Managed membership deleted' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Subscription expiration',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerEx' );
		$guest = $t->user( 'expire' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );
		$t->reload( $sub->get_id() )->update_status( 'expired' );
		$t->ok( ! $t->is_member( $guest ), 'Access revoked' );
		$t->same( SeatStatus::REASON_SUBSCRIPTION_INACTIVE, $t->seat( $seat->id )->access_reason, 'Reason' );
		$t->error( $t->plugin()->team()->invite( $sub->get_id(), 'after@example.test', $owner ), 'subscription_inactive' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Plan switch: quota decrease (deterministic suspension) then increase (restoration)',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerS' );
		$sub   = $t->purchase( $owner, $t->fixtures['vb'] );
		$t->same( 10, $t->plugin()->quotas()->quota( $sub ), 'Variation without a value: quota inherited from the parent' );

		$seats = array();
		foreach ( array( 's1', 's2', 's3', 's4' ) as $label ) {
			$guest   = $t->user( $label );
			$seats[] = array( $guest, $t->invite_and_accept( $sub, $guest ) );
			sleep( 1 ); // Distinct acceptance dates.
		}

		$switch = function ( int $variation_id ) use ( $t, $sub ) {
			$subscription = $t->reload( $sub->get_id() );
			foreach ( $subscription->get_items() as $item ) {
				$item->set_variation_id( $variation_id );
				$item->save();
			}
			$order = wc_create_order( array( 'customer_id' => $subscription->get_customer_id() ) );
			$order->update_meta_data( '_subscription_switch', $subscription->get_id() );
			$order->save();
			$t->created_orders[] = $order->get_id();
			do_action( 'woocommerce_subscriptions_switch_completed', $order );
		};

		$switch( $t->fixtures['va'] ); // 2 guests.
		$s = $t->reload( $sub->get_id() );
		$t->same( 2, $t->plugin()->quotas()->quota( $s ), 'New quota' );
		$summary = $team->summary( $s );
		$t->same( 2, $summary['over'], 'Overflow reported' );
		$t->ok( $t->is_member( $seats[0][0] ) && $t->is_member( $seats[1][0] ), 'The first two acceptances keep their access' );
		$t->ok( ! $t->is_member( $seats[2][0] ) && ! $t->is_member( $seats[3][0] ), 'The two most recent are suspended' );
		$t->same( SeatStatus::REASON_OVER_QUOTA, $t->seat( $seats[3][1]->id )->access_reason, 'Reason: over quota' );
		$t->error( $team->invite( $sub->get_id(), 'blocked@example.test', $owner ), 'over_quota' );
		$t->same( 4, $summary['used'], 'Nobody is removed' );

		$switch( $t->fixtures['vb'] ); // 10 guests.
		$s = $t->reload( $sub->get_id() );
		$t->same( 10, $t->plugin()->quotas()->quota( $s ), 'Quota restored' );
		foreach ( $seats as [ $guest ] ) {
			$t->ok( $t->is_member( $guest ), "Guest {$guest} restored" );
		}
		$history = $t->plugin()->quotas()->history( $s );
		$t->ok( count( $history ) >= 3, 'Quota change history' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Quota enforced by an administrator',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerO' );
		$sub   = $t->purchase( $owner, $t->fixtures['p0'] );
		$t->plugin()->quotas()->set_override( $sub, 1, (int) $t->fixtures['admin_id'] );
		$t->same( 1, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'Enforced quota' );
		$t->invite( $t->reload( $sub->get_id() ), 'forced-' . $sub->get_id() . '@example.test' );
		$t->plugin()->quotas()->set_override( $t->reload( $sub->get_id() ), null, (int) $t->fixtures['admin_id'] );
		$t->same( 0, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'Back to the snapshot' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Criterion 11: guest who already has a personal membership (preserved, never modified)',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner    = $t->user( 'ownerPe' );
		$guest    = $t->user( 'personal' );
		$own_sub  = $t->purchase( $guest, $t->fixtures['p0'] );
		$personal = wc_memberships_get_user_membership( $guest, $t->plan() );
		$t->ok( $personal && $personal->is_active(), 'Personal membership active' );
		$personal_id = $personal->get_id();

		$sub  = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat = $t->invite_and_accept( $sub, $guest );
		$t->same( 0, $t->managed_count( $guest ), 'No managed membership created (no duplicate)' );
		$t->ok( $t->is_member( $guest ), 'Still a member' );

		// The personal membership is interrupted: the invitation takes over.
		$t->reload( $own_sub->get_id() )->update_status( 'on-hold' );
		$t->ok( $t->is_member( $guest ), 'The invitation takes over when the personal membership is suspended' );
		$t->same( 1, $t->managed_count( $guest ), 'Managed membership created' );
		$t->same( 'paused', wc_memberships_get_user_membership( $personal_id )->get_status(), 'The personal membership is not modified' );

		// The personal membership resumes: the managed membership disappears.
		$t->reload( $own_sub->get_id() )->update_status( 'active' );
		$t->same( 0, $t->managed_count( $guest ), 'Managed membership removed when the personal membership becomes active again' );
		$t->ok( $t->is_member( $guest ), 'Member through their personal membership' );

		// Invitation revoked: the personal membership is intact.
		$t->success( $team->remove( $seat->id, $owner ), 'Revocation' );
		$personal = wc_memberships_get_user_membership( $personal_id );
		$t->ok( $personal && 'active' === $personal->get_status(), 'Personal membership still active' );
		$t->ok( $t->has_active_member_role( $guest, true ), 'Member role kept thanks to the personal membership' );
		$t->ok( $t->is_member( $guest ), 'Independent entitlement preserved' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Guest who bought their own subscription afterwards: membership taken over by the purchase',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerHo' );
		$guest = $t->user( 'buyer' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );
		$t->same( 1, $t->managed_count( $guest ), 'Managed membership' );

		$t->purchase( $guest, $t->fixtures['p0'] );
		$t->same( 0, $t->managed_count( $guest ), 'The membership is no longer managed by the plugin' );
		$t->ok( $t->is_member( $guest ), 'Still a member' );

		$t->success( $team->remove( $seat->id, $owner ), 'Invitation revocation' );
		$t->ok( $t->is_member( $guest ), 'Their personal purchase keeps their access' );
		$t->ok( $t->has_active_member_role( $guest, true ), 'Member role kept' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'User invited by several subscriptions',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner_a = $t->user( 'ownerMa' );
		$owner_b = $t->user( 'ownerMb' );
		$guest   = $t->user( 'multi' );
		$sub_a   = $t->purchase( $owner_a, $t->fixtures['p5'] );
		$sub_b   = $t->purchase( $owner_b, $t->fixtures['p5'] );
		$seat_a  = $t->invite_and_accept( $sub_a, $guest );
		$seat_b  = $t->invite_and_accept( $sub_b, $guest );
		$t->same( 1, $t->managed_count( $guest ), 'A single managed membership for two sources' );

		$t->reload( $sub_a->get_id() )->update_status( 'on-hold' );
		$t->ok( $t->is_member( $guest ), 'Active B is enough when A is suspended' );

		$t->success( $team->remove( $seat_b->id, $owner_b ), 'Revocation of B' );
		$t->ok( ! $t->is_member( $guest ), 'A suspended and B revoked: access suspended' );
		$t->same( 'paused', $t->managed( $guest )->get_status(), 'Membership paused (A on hold)' );

		$t->reload( $sub_a->get_id() )->update_status( 'active' );
		$t->ok( $t->is_member( $guest ), 'A reactivated' );
		$t->success( $team->remove( $seat_a->id, $owner_a ), 'Revocation of A' );
		$t->ok( ! $t->is_member( $guest ), 'No source left: no access' );
		$t->same( 0, $t->managed_count( $guest ), 'Managed membership deleted' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Managing a subscription belonging to another user: refused',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner    = $t->user( 'ownerV' );
		$intruder = $t->user( 'intruder' );
		$guest    = $t->user( 'victim' );
		$sub      = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat     = $t->invite_and_accept( $sub, $guest );
		$pending  = $t->invite( $sub, 'pending-' . $sub->get_id() . '@example.test' );

		$t->error( $team->invite( $sub->get_id(), 'x@example.test', $intruder ), 'forbidden' );
		$t->error( $team->remove( $seat->id, $intruder ), 'forbidden' );
		$t->error( $team->replace( $seat->id, 'y@example.test', $intruder ), 'forbidden' );
		$t->error( $team->resend( $pending->id, $intruder ), 'forbidden' );
		$t->error( $team->invite( 999999999, 'x@example.test', $intruder ), 'forbidden' );
		$t->ok( $t->is_member( $guest ), 'The guest is not affected' );

		// A shop manager can act.
		$t->success( $team->remove( $seat->id, (int) $t->fixtures['admin_id'] ), 'Revocation by an administrator' );
		$t->ok( ! $t->is_member( $guest ), 'Access revoked by the administrator' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Repeated execution of the same event: idempotence',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $sync ) {
		$owner = $t->user( 'ownerI' );
		$guest = $t->user( 'idem' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );
		$membership_id = $t->managed( $guest )->get_id();

		for ( $i = 0; $i < 3; $i++ ) {
			$result = $sync->sync_subscription( $sub->get_id(), 'test' );
			$t->same( 'unchanged', $result['memberships'][ $guest . ':' . $t->plan() ], 'Synchronization with no effect' );
			do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'active', 'active' );
		}
		$t->same( 1, $t->managed_count( $guest ), 'Still a single membership' );
		$t->same( $membership_id, $t->managed( $guest )->get_id(), 'Same membership' );
		$t->same( 1, count( $t->plugin()->grants()->for_user_plan( $guest, $t->plan() ) ), 'A single access source' );

		// Double status change replayed.
		$t->reload( $sub->get_id() )->update_status( 'on-hold' );
		do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'on-hold', 'active' );
		$t->reload( $sub->get_id() )->update_status( 'active' );
		do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'active', 'on-hold' );
		$t->same( 1, $t->managed_count( $guest ), 'No duplicate after replay' );
		$t->ok( $t->is_member( $guest ), 'Consistent access' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Catch-up: managed membership deleted or edited by hand, then resynchronization',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $sync ) {
		$owner = $t->user( 'ownerCa' );
		$guest = $t->user( 'catch' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		wp_delete_post( $t->managed( $guest )->get_id(), true );
		$t->ok( ! $t->is_member( $guest ), '"Missed" event: access lost' );
		$sync->sync_subscription( $sub->get_id(), 'catch_up' );
		$t->ok( $t->is_member( $guest ), 'The catch-up synchronization restores access' );

		$t->managed( $guest )->update_status( 'cancelled' );
		$sync->sync_subscription( $sub->get_id(), 'catch_up' );
		$t->ok( $t->is_member( $guest ), 'Manually changed status corrected' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Rate limiting of sends and resends',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerL' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite( $sub, 'limit-' . $sub->get_id() . '@example.test' );
		$t->error( $team->resend( $seat->id, $owner ), 'resend_too_soon' );

		$previous = get_option( Settings::OPTION );
		update_option( Settings::OPTION, array_merge( Settings::all(), array( 'max_invites_per_hour' => 1 ) ) );
		try {
			$t->error( $team->invite( $sub->get_id(), 'limit2-' . $sub->get_id() . '@example.test', $owner ), 'rate_limited' );
		} finally {
			update_option( Settings::OPTION, $previous );
		}
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Email validation and self-invitation',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerVa' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->error( $team->invite( $sub->get_id(), 'not-an-email', $owner ), 'invalid_email' );
		$t->error( $team->invite( $sub->get_id(), '<script>@x', $owner ), 'invalid_email' );
		$t->error( $team->invite( $sub->get_id(), strtoupper( $t->email( $owner ) ), $owner ), 'self_invite' );
		$t->invite( $sub, 'Mixed.Case-' . $sub->get_id() . '@Example.test' );
		$t->error( $team->invite( $sub->get_id(), 'mixed.case-' . $sub->get_id() . '@example.test', $owner ), 'already_invited' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'My Account: "My guests" menu and content for the holder only',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerUi' );
		$guest = $t->user( 'uiguest' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$endpoint = new AccountEndpoint( $t->plugin()->team(), $t->plugin()->subscriptions() );
		wp_set_current_user( $owner );
		$items = $endpoint->menu_items( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Logout' ) );
		$t->ok( isset( $items[ AccountEndpoint::KEY ] ), 'Menu entry for the holder' );
		ob_start();
		$endpoint->render();
		$html = (string) ob_get_clean();
		$t->ok( str_contains( $html, $t->email( $guest ) ), 'Guest listed' );
		$t->ok( str_contains( $html, 'exprime-team-invite-form' ), 'Invitation form' );

		wp_set_current_user( $guest );
		$items = ( new AccountEndpoint( $t->plugin()->team(), $t->plugin()->subscriptions() ) )->menu_items( array( 'dashboard' => 'Dashboard' ) );
		$t->ok( ! isset( $items[ AccountEndpoint::KEY ] ), 'No menu for a guest' );
		wp_set_current_user( 0 );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'GDPR: export and erasure of a guest\'s data',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerG' );
		$guest = $t->user( 'gdpr' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$privacy = new \Exprime\TeamAccess\Privacy( $t->plugin()->seats(), $t->plugin()->team() );
		$export  = $privacy->export( $t->email( $guest ) );
		$t->same( 1, count( $export['data'] ), 'One invitation exported' );

		$erase = $privacy->erase( $t->email( $guest ) );
		$t->ok( $erase['items_removed'], 'Data erased' );
		$t->ok( ! $t->is_member( $guest ), 'Access removed' );
		$t->same( array(), $t->plugin()->seats()->for_user_or_email( 0, strtolower( $t->email( $guest ) ) ), 'Email address anonymized' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Native Memberships emails disabled for managed memberships',
	function ( EXPRIME_TEAM_ACCESS_IT $t ) {
		$owner = $t->user( 'ownerMl' );
		$guest = $t->user( 'mailguest' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );
		$membership = $t->managed( $guest );
		$t->ok( false === apply_filters( 'woocommerce_email_enabled_WC_Memberships_User_Membership_Ending_Soon_Email', true, $membership ), '"Ending soon" email disabled' );
		$t->ok( false === apply_filters( 'woocommerce_email_enabled_WC_Memberships_User_Membership_Renewal_Reminder_Email', true, $membership ), 'Renewal reminder disabled' );
		$actions = apply_filters( 'wc_memberships_members_area_my-memberships_actions', array( 'cancel' => array(), 'renew' => array(), 'view' => array() ), $membership );
		$t->ok( ! isset( $actions['cancel'] ) && ! isset( $actions['renew'] ) && isset( $actions['view'] ), 'Cancel/Renew actions removed from the members area' );
	}
);

// Data for the concurrency test (run afterwards by run.sh): 4 seats taken out of 5.
$concurrency_owner = $t->user( 'ownerCc' );
$concurrency_sub   = $t->purchase( $concurrency_owner, $t->fixtures['p5'] );
for ( $i = 1; $i <= 4; $i++ ) {
	$t->invite( $concurrency_sub, "cc{$i}-" . $concurrency_sub->get_id() . '@example.test' );
}
$fixtures                       = $t->fixtures;
$fixtures['concurrency_sub']    = $concurrency_sub->get_id();
$fixtures['concurrency_owner']  = $concurrency_owner;
$fixtures['created_users']      = $t->created_users;
$fixtures['created_orders']     = $t->created_orders;
file_put_contents( (string) $args[0], (string) wp_json_encode( $fixtures, JSON_PRETTY_PRINT ) );

$t->report();
