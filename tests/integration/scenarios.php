<?php
/**
 * Scénarios d'intégration (cahier des charges §11 et critères d'acceptation §13).
 *
 * Usage : wp eval-file tests/integration/scenarios.php <fichier-fixtures.json>
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

require_once __DIR__ . '/lib.php';

use WCSubscriptionTeamAccess\AccountEndpoint;
use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Domain\Token;
use WCSubscriptionTeamAccess\Settings;
use WCSubscriptionTeamAccess\Support\Dates;

$t    = new WCSTA_IT( (string) ( $args[0] ?? '' ) );
$team = $t->plugin()->team();
$sync = $t->plugin()->reconciler();

WP_CLI::log( 'WC Subscription Team Access — scénarios d\'intégration' );

// ---------------------------------------------------------------------------------------------
$t->test(
	'Abonnement sans places invitées : invitation refusée, pas de menu « Mes invités »',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'owner0' );
		$sub   = $t->purchase( $owner, $t->fixtures['p0'] );
		$t->same( 0, $t->plugin()->quotas()->quota( $sub ), 'Quota' );
		$t->error( $team->invite( $sub->get_id(), 'someone@example.test', $owner ), 'not_eligible' );
		$t->same( array(), $t->plugin()->subscriptions()->team_subscriptions_for( $owner ), 'Abonnements partageables' );
		$t->ok( $t->is_member( $owner ), 'Le titulaire garde son adhésion' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Abonnement avec 5 places : quota figé à l\'achat (catalogue modifié ensuite)',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'owner5' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->same( 5, $t->plugin()->quotas()->quota( $sub ), 'Quota à l\'achat' );

		$product = wc_get_product( $t->fixtures['p5'] );
		$product->update_meta_data( '_wcsta_max_guests', '9' );
		$product->save();
		try {
			$t->same( 5, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'Le quota ne suit pas le catalogue' );
		} finally {
			$product->update_meta_data( '_wcsta_max_guests', '5' );
			$product->save();
		}
		$t->ok( in_array( $t->plan(), $t->plugin()->subscriptions()->plan_ids( $sub ), true ), 'Plan partagé identifié' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Invitation d\'un nouvel utilisateur : e-mail sans mot de passe, compte créé, accès premium',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerN' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$email = WCSTA_IT::PREFIX . 'new-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';

		$seat = $t->invite( $sub, $email );
		$t->same( SeatStatus::PENDING, $seat->status, 'Statut initial' );

		$mail = end( $t->mails );
		$t->ok( str_contains( (string) $mail['message'], 'token=' ), 'Lien d\'acceptation dans l\'e-mail' );
		$t->ok( ! preg_match( '/mot de passe\s*:|password\s*:/i', (string) $mail['message'] ), 'Aucun mot de passe dans l\'e-mail' );

		$token = $t->token_for( $email );
		$t->ok( null === $t->plugin()->seats()->find_by_token_hash( $token ), 'Le jeton n\'est pas stocké en clair' );
		$t->ok( null !== $t->plugin()->seats()->find_by_token_hash( Token::hash( $token ) ), 'Seul son hachage est stocké' );

		// Création du compte comme le fait la page d'invitation (adresse imposée par l'invitation).
		$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
		$t->ok( ! is_wp_error( $user_id ), 'Compte créé' );
		$t->created_users[] = (int) $user_id;

		$t->ok( ! $t->is_member( (int) $user_id ), 'Pas d\'accès avant acceptation' );
		$accepted = $t->success( $team->accept( $token, (int) $user_id ), 'Acceptation' );
		$t->same( SeatStatus::ACCEPTED, $accepted->status, 'Statut accepté' );
		$t->same( SeatStatus::ACCESS_ACTIVE, $accepted->access, 'Accès actif' );
		$t->ok( $t->is_member( (int) $user_id ), 'Membre actif du plan (Memberships)' );
		$t->ok( $t->sees_restricted_content( (int) $user_id ), 'Voit le contenu [wcm_restrict]' );
		$t->same( 1, $t->managed_count( (int) $user_id ), 'Une seule adhésion gérée' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Invitation d\'un utilisateur existant et notification du titulaire',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerE' );
		$guest = $t->user( 'existing' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->ok( ! $t->sees_restricted_content( $guest ), 'Pas d\'accès avant' );

		$before = count( $t->mails );
		$t->invite_and_accept( $sub, $guest );
		$t->ok( $t->is_member( $guest ), 'Accès accordé' );
		$t->ok( $t->sees_restricted_content( $guest ), 'Contenu restreint visible' );

		$to_owner = array_filter( array_slice( $t->mails, $before ), static fn( $m ) => $t->mail_is_for( $m, $t->email( $owner ) ) );
		$t->ok( count( $to_owner ) >= 1, 'Le titulaire est prévenu de l\'acceptation' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Adresse du compte différente de l\'invitation : refus',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerM' );
		$other = $t->user( 'other' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$email = WCSTA_IT::PREFIX . 'target-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$t->invite( $sub, $email );
		$t->error( $team->accept( $t->token_for( $email ), $other ), 'email_mismatch' );
		$t->ok( ! $t->is_member( $other ), 'Aucun accès accordé' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Acceptation d\'une invitation expirée : refus et place libérée',
	function ( WCSTA_IT $t ) use ( $team ) {
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
		$t->same( SeatStatus::EXPIRED, $t->seat( $seat->id )->status, 'Invitation expirée' );
		$t->same( 0, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'La place est libérée' );

		// Une invitation expirée peut être relancée.
		$t->success( $team->resend( $seat->id, $owner ), 'Relance' );
		$t->success( $team->accept( $t->token_for( $t->email( $guest ) ), $guest ), 'Acceptation après relance' );
		$t->ok( $t->is_member( $guest ), 'Accès après relance' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Invitation déjà utilisée ou remplacée par un renvoi : lien invalide',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerU' );
		$guest = $t->user( 'used' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite( $sub, $t->email( $guest ) );
		$token = $t->token_for( $t->email( $guest ) );
		$t->success( $team->accept( $token, $guest ), 'Première acceptation' );
		$t->error( $team->accept( $token, $guest ), 'invalid_token' );

		$guest2 = $t->user( 'resent' );
		$seat   = $t->invite( $sub, $t->email( $guest2 ) );
		$old    = $t->token_for( $t->email( $guest2 ) );
		$t->plugin()->seats()->update( $seat->id, array( 'last_sent_at' => Dates::to_mysql( time() - 3600 ) ) );
		$t->success( $team->resend( $seat->id, $owner ), 'Renvoi' );
		$t->error( $team->accept( $old, $guest2 ), 'invalid_token' );
		$t->success( $team->accept( $t->token_for( $t->email( $guest2 ) ), $guest2 ), 'Le nouveau lien fonctionne' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Critères 1 à 6 : 5 invités acceptés, accès premium, sixième invitation refusée',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner  = $t->user( 'ownerA' );
		$sub    = $t->purchase( $owner, $t->fixtures['p5'] );
		$guests = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$guests[] = $guest = $t->user( "g{$i}" );
			$t->invite_and_accept( $sub, $guest );
		}
		foreach ( $guests as $guest ) {
			$t->ok( $t->is_member( $guest ), "Invité {$guest} actif" );
		}
		$summary = $team->summary( $t->reload( $sub->get_id() ) );
		$t->same( 5, $summary['used'], 'Places utilisées' );
		$t->same( 0, $summary['available'], 'Places disponibles' );
		$t->error( $team->invite( $sub->get_id(), 'sixth@example.test', $owner ), 'no_seat' );
		$t->ok( $t->is_member( $owner ), 'Le titulaire ne consomme pas de place et garde son accès' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Une invitation en attente réserve une place',
	function ( WCSTA_IT $t ) use ( $team ) {
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
	'Révocation d\'un invité : seuls ses droits disparaissent, la place est libérée',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerR' );
		$a     = $t->user( 'keep' );
		$b     = $t->user( 'revoked' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $a );
		$seat_b = $t->invite_and_accept( $sub, $b );

		$result = $t->success( $team->remove( $seat_b->id, $owner ), 'Suppression' );
		$t->same( SeatStatus::REVOKED, $result->status, 'Statut révoqué' );
		$t->ok( ! $t->is_member( $b ), 'L\'invité révoqué perd l\'accès' );
		$t->ok( ! $t->has_active_member_role( $b, false ), 'Rôle de membre actif retiré (transition native de Memberships)' );
		$t->ok( ! $t->sees_restricted_content( $b ), 'Contenu restreint masqué' );
		$t->same( 0, $t->managed_count( $b ), 'Adhésion gérée supprimée' );
		$t->ok( $t->is_member( $a ), 'Les autres invités gardent l\'accès' );
		$t->ok( $t->is_member( $owner ), 'Le titulaire garde l\'accès' );
		$t->same( 1, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'Place libérée' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Critère 7 : remplacement d\'un invité et réattribution de sa place',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerRe' );
		$old   = $t->user( 'replaced' );
		$new   = $t->user( 'replacement' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		for ( $i = 1; $i <= 4; $i++ ) {
			$t->invite( $sub, "filler{$i}-" . $sub->get_id() . '@example.test' );
		}
		$seat = $t->invite_and_accept( $sub, $old );
		$t->same( 5, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'Quota plein' );

		$result = $t->success( $team->replace( $seat->id, $t->email( $new ), $owner ), 'Remplacement' );
		$t->same( SeatStatus::REVOKED, $t->seat( $seat->id )->status, 'Ancien invité révoqué' );
		$t->same( $seat->id, $result['seat']->replaced_seat_id, 'Lien de remplacement' );
		$t->ok( ! $t->is_member( $old ), 'L\'ancien invité perd l\'accès' );
		$t->same( 5, $team->summary( $t->reload( $sub->get_id() ) )['used'], 'La place est réattribuée' );
		$t->success( $team->accept( $t->token_for( $t->email( $new ) ), $new ), 'Acceptation du remplaçant' );
		$t->ok( $t->is_member( $new ), 'Le remplaçant a l\'accès' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Critères 8 et 9 : suspension (on-hold) puis réactivation de l\'abonnement',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerH' );
		$guest = $t->user( 'held' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );

		$t->reload( $sub->get_id() )->update_status( 'on-hold' );
		$t->ok( ! $t->is_member( $guest ), 'Accès invité suspendu' );
		$t->same( 'paused', $t->managed( $guest )->get_status(), 'Adhésion gérée en pause' );
		$t->same( SeatStatus::ACCESS_SUSPENDED, $t->seat( $seat->id )->access, 'Place affichée « accès suspendu »' );
		$t->same( SeatStatus::REASON_SUBSCRIPTION_PAUSED, $t->seat( $seat->id )->access_reason, 'Raison' );

		$t->reload( $sub->get_id() )->update_status( 'active' );
		$t->ok( $t->is_member( $guest ), 'Accès invité restauré' );
		$t->same( 'active', $t->managed( $guest )->get_status(), 'Adhésion gérée active' );
		$t->same( 1, $t->managed_count( $guest ), 'Pas de doublon après réactivation' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Renouvellement échoué puis réussi',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerRn' );
		$guest = $t->user( 'renew' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$renewal = wcs_create_renewal_order( $t->reload( $sub->get_id() ) );
		$t->created_orders[] = $renewal->get_id();
		$t->reload( $sub->get_id() )->payment_failed();
		$t->same( 'on-hold', $t->reload( $sub->get_id() )->get_status(), 'Abonnement suspendu après échec' );
		$t->ok( ! $t->is_member( $guest ), 'Accès suspendu après échec' );

		$renewal = wc_get_order( $renewal->get_id() );
		$renewal->payment_complete();
		$t->same( 'active', $t->reload( $sub->get_id() )->get_status(), 'Abonnement réactivé' );
		$t->ok( $t->is_member( $guest ), 'Accès restauré après paiement' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Critère 10 : résiliation avec période payée restante, révocation à la fin seulement',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerC' );
		$guest = $t->user( 'cancel' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$t->reload( $sub->get_id() )->cancel_order();
		$sub = $t->reload( $sub->get_id() );
		$t->same( 'pending-cancel', $sub->get_status(), 'Abonnement en annulation programmée' );
		$end = (int) $sub->get_time( 'end' );
		$t->ok( $end > time(), 'Date de fin future' );
		$t->ok( $t->is_member( $guest ), 'L\'invité garde l\'accès pendant la période payée' );
		$t->same( $end, (int) $t->managed( $guest )->get_end_date( 'timestamp', false ), 'Fin de l\'adhésion invitée = fin de période payée' );
		$t->same( $t->is_member( $owner ), $t->is_member( $guest ), 'Même droit que le titulaire' );

		// Fin de période : Subscriptions passe l'abonnement en « cancelled ».
		$sub->update_status( 'cancelled' );
		$t->ok( ! $t->is_member( $guest ), 'Accès révoqué à la fin de la période' );
		$t->same( 0, $t->managed_count( $guest ), 'Adhésion gérée supprimée' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Expiration de l\'abonnement',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerEx' );
		$guest = $t->user( 'expire' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );
		$t->reload( $sub->get_id() )->update_status( 'expired' );
		$t->ok( ! $t->is_member( $guest ), 'Accès révoqué' );
		$t->same( SeatStatus::REASON_SUBSCRIPTION_INACTIVE, $t->seat( $seat->id )->access_reason, 'Raison' );
		$t->error( $t->plugin()->team()->invite( $sub->get_id(), 'after@example.test', $owner ), 'subscription_inactive' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Changement de formule : baisse du quota (suspension déterministe) puis hausse (restauration)',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerS' );
		$sub   = $t->purchase( $owner, $t->fixtures['vb'] );
		$t->same( 10, $t->plugin()->quotas()->quota( $sub ), 'Variation sans valeur : quota hérité du parent' );

		$seats = array();
		foreach ( array( 's1', 's2', 's3', 's4' ) as $label ) {
			$guest   = $t->user( $label );
			$seats[] = array( $guest, $t->invite_and_accept( $sub, $guest ) );
			sleep( 1 ); // Dates d'acceptation distinctes.
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

		$switch( $t->fixtures['va'] ); // 2 invités.
		$s = $t->reload( $sub->get_id() );
		$t->same( 2, $t->plugin()->quotas()->quota( $s ), 'Nouveau quota' );
		$summary = $team->summary( $s );
		$t->same( 2, $summary['over'], 'Dépassement signalé' );
		$t->ok( $t->is_member( $seats[0][0] ) && $t->is_member( $seats[1][0] ), 'Les deux premières acceptations gardent l\'accès' );
		$t->ok( ! $t->is_member( $seats[2][0] ) && ! $t->is_member( $seats[3][0] ), 'Les deux plus récentes sont suspendues' );
		$t->same( SeatStatus::REASON_OVER_QUOTA, $t->seat( $seats[3][1]->id )->access_reason, 'Raison : dépassement' );
		$t->error( $team->invite( $sub->get_id(), 'blocked@example.test', $owner ), 'over_quota' );
		$t->same( 4, $summary['used'], 'Personne n\'est supprimé' );

		$switch( $t->fixtures['vb'] ); // 10 invités.
		$s = $t->reload( $sub->get_id() );
		$t->same( 10, $t->plugin()->quotas()->quota( $s ), 'Quota rétabli' );
		foreach ( $seats as [ $guest ] ) {
			$t->ok( $t->is_member( $guest ), "Invité {$guest} restauré" );
		}
		$history = $t->plugin()->quotas()->history( $s );
		$t->ok( count( $history ) >= 3, 'Historique des changements de quota' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Quota imposé par un administrateur',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerO' );
		$sub   = $t->purchase( $owner, $t->fixtures['p0'] );
		$t->plugin()->quotas()->set_override( $sub, 1, (int) $t->fixtures['admin_id'] );
		$t->same( 1, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'Quota imposé' );
		$t->invite( $t->reload( $sub->get_id() ), 'forced-' . $sub->get_id() . '@example.test' );
		$t->plugin()->quotas()->set_override( $t->reload( $sub->get_id() ), null, (int) $t->fixtures['admin_id'] );
		$t->same( 0, $t->plugin()->quotas()->quota( $t->reload( $sub->get_id() ) ), 'Retour à l\'instantané' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Critère 11 : invité possédant déjà une adhésion personnelle (préservée, jamais modifiée)',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner    = $t->user( 'ownerPe' );
		$guest    = $t->user( 'personal' );
		$own_sub  = $t->purchase( $guest, $t->fixtures['p0'] );
		$personal = wc_memberships_get_user_membership( $guest, $t->plan() );
		$t->ok( $personal && $personal->is_active(), 'Adhésion personnelle active' );
		$personal_id = $personal->get_id();

		$sub  = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat = $t->invite_and_accept( $sub, $guest );
		$t->same( 0, $t->managed_count( $guest ), 'Aucune adhésion gérée créée (pas de doublon)' );
		$t->ok( $t->is_member( $guest ), 'Toujours membre' );

		// L'adhésion personnelle s'interrompt : l'invitation prend le relais.
		$t->reload( $own_sub->get_id() )->update_status( 'on-hold' );
		$t->ok( $t->is_member( $guest ), 'L\'invitation prend le relais quand l\'adhésion personnelle est suspendue' );
		$t->same( 1, $t->managed_count( $guest ), 'Adhésion gérée créée' );
		$t->same( 'paused', wc_memberships_get_user_membership( $personal_id )->get_status(), 'L\'adhésion personnelle n\'est pas modifiée' );

		// L'adhésion personnelle reprend : l'adhésion gérée disparaît.
		$t->reload( $own_sub->get_id() )->update_status( 'active' );
		$t->same( 0, $t->managed_count( $guest ), 'Adhésion gérée retirée quand l\'adhésion personnelle redevient active' );
		$t->ok( $t->is_member( $guest ), 'Membre via son adhésion personnelle' );

		// Révocation de l'invitation : l'adhésion personnelle est intacte.
		$t->success( $team->remove( $seat->id, $owner ), 'Révocation' );
		$personal = wc_memberships_get_user_membership( $personal_id );
		$t->ok( $personal && 'active' === $personal->get_status(), 'Adhésion personnelle toujours active' );
		$t->ok( $t->has_active_member_role( $guest, true ), 'Rôle de membre conservé grâce à l\'adhésion personnelle' );
		$t->ok( $t->is_member( $guest ), 'Droit indépendant préservé' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Invité ayant acheté son propre abonnement après coup : adhésion reprise par l\'achat',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner = $t->user( 'ownerHo' );
		$guest = $t->user( 'buyer' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$seat  = $t->invite_and_accept( $sub, $guest );
		$t->same( 1, $t->managed_count( $guest ), 'Adhésion gérée' );

		$t->purchase( $guest, $t->fixtures['p0'] );
		$t->same( 0, $t->managed_count( $guest ), 'L\'adhésion n\'est plus gérée par l\'extension' );
		$t->ok( $t->is_member( $guest ), 'Toujours membre' );

		$t->success( $team->remove( $seat->id, $owner ), 'Révocation de l\'invitation' );
		$t->ok( $t->is_member( $guest ), 'Son achat personnel lui conserve l\'accès' );
		$t->ok( $t->has_active_member_role( $guest, true ), 'Rôle de membre conservé' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Utilisateur invité par plusieurs abonnements',
	function ( WCSTA_IT $t ) use ( $team ) {
		$owner_a = $t->user( 'ownerMa' );
		$owner_b = $t->user( 'ownerMb' );
		$guest   = $t->user( 'multi' );
		$sub_a   = $t->purchase( $owner_a, $t->fixtures['p5'] );
		$sub_b   = $t->purchase( $owner_b, $t->fixtures['p5'] );
		$seat_a  = $t->invite_and_accept( $sub_a, $guest );
		$seat_b  = $t->invite_and_accept( $sub_b, $guest );
		$t->same( 1, $t->managed_count( $guest ), 'Une seule adhésion gérée pour deux sources' );

		$t->reload( $sub_a->get_id() )->update_status( 'on-hold' );
		$t->ok( $t->is_member( $guest ), 'B active suffit quand A est suspendu' );

		$t->success( $team->remove( $seat_b->id, $owner_b ), 'Révocation B' );
		$t->ok( ! $t->is_member( $guest ), 'A suspendu et B révoqué : accès suspendu' );
		$t->same( 'paused', $t->managed( $guest )->get_status(), 'Adhésion en pause (A en attente)' );

		$t->reload( $sub_a->get_id() )->update_status( 'active' );
		$t->ok( $t->is_member( $guest ), 'A réactivé' );
		$t->success( $team->remove( $seat_a->id, $owner_a ), 'Révocation A' );
		$t->ok( ! $t->is_member( $guest ), 'Plus aucune source : plus d\'accès' );
		$t->same( 0, $t->managed_count( $guest ), 'Adhésion gérée supprimée' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Gestion d\'un abonnement appartenant à un autre utilisateur : refus',
	function ( WCSTA_IT $t ) use ( $team ) {
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
		$t->ok( $t->is_member( $guest ), 'L\'invité n\'est pas affecté' );

		// Un gestionnaire de la boutique peut agir.
		$t->success( $team->remove( $seat->id, (int) $t->fixtures['admin_id'] ), 'Révocation par un administrateur' );
		$t->ok( ! $t->is_member( $guest ), 'Accès révoqué par l\'administrateur' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Exécution répétée d\'un même événement : idempotence',
	function ( WCSTA_IT $t ) use ( $sync ) {
		$owner = $t->user( 'ownerI' );
		$guest = $t->user( 'idem' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );
		$membership_id = $t->managed( $guest )->get_id();

		for ( $i = 0; $i < 3; $i++ ) {
			$result = $sync->sync_subscription( $sub->get_id(), 'test' );
			$t->same( 'unchanged', $result['memberships'][ $guest . ':' . $t->plan() ], 'Synchronisation sans effet' );
			do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'active', 'active' );
		}
		$t->same( 1, $t->managed_count( $guest ), 'Toujours une seule adhésion' );
		$t->same( $membership_id, $t->managed( $guest )->get_id(), 'Même adhésion' );
		$t->same( 1, count( $t->plugin()->grants()->for_user_plan( $guest, $t->plan() ) ), 'Une seule source de droits' );

		// Double changement de statut rejoué.
		$t->reload( $sub->get_id() )->update_status( 'on-hold' );
		do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'on-hold', 'active' );
		$t->reload( $sub->get_id() )->update_status( 'active' );
		do_action( 'woocommerce_subscription_status_updated', $t->reload( $sub->get_id() ), 'active', 'on-hold' );
		$t->same( 1, $t->managed_count( $guest ), 'Pas de doublon après rejeu' );
		$t->ok( $t->is_member( $guest ), 'Accès cohérent' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Rattrapage : adhésion gérée supprimée ou modifiée à la main, puis resynchronisation',
	function ( WCSTA_IT $t ) use ( $sync ) {
		$owner = $t->user( 'ownerCa' );
		$guest = $t->user( 'catch' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		wp_delete_post( $t->managed( $guest )->get_id(), true );
		$t->ok( ! $t->is_member( $guest ), 'Événement « manqué » : accès perdu' );
		$sync->sync_subscription( $sub->get_id(), 'catch_up' );
		$t->ok( $t->is_member( $guest ), 'La synchronisation de rattrapage restaure l\'accès' );

		$t->managed( $guest )->update_status( 'cancelled' );
		$sync->sync_subscription( $sub->get_id(), 'catch_up' );
		$t->ok( $t->is_member( $guest ), 'Statut modifié à la main corrigé' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'Limitation des envois et des renvois',
	function ( WCSTA_IT $t ) use ( $team ) {
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
	'Validation des adresses et auto-invitation',
	function ( WCSTA_IT $t ) use ( $team ) {
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
	'Mon compte : menu et contenu « Mes invités » pour le titulaire uniquement',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerUi' );
		$guest = $t->user( 'uiguest' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$endpoint = new AccountEndpoint( $t->plugin()->team(), $t->plugin()->subscriptions() );
		wp_set_current_user( $owner );
		$items = $endpoint->menu_items( array( 'dashboard' => 'Tableau de bord', 'customer-logout' => 'Déconnexion' ) );
		$t->ok( isset( $items[ AccountEndpoint::KEY ] ), 'Entrée de menu pour le titulaire' );
		ob_start();
		$endpoint->render();
		$html = (string) ob_get_clean();
		$t->ok( str_contains( $html, $t->email( $guest ) ), 'Invité listé' );
		$t->ok( str_contains( $html, 'wcsta-invite-form' ), 'Formulaire d\'invitation' );

		wp_set_current_user( $guest );
		$items = ( new AccountEndpoint( $t->plugin()->team(), $t->plugin()->subscriptions() ) )->menu_items( array( 'dashboard' => 'Tableau de bord' ) );
		$t->ok( ! isset( $items[ AccountEndpoint::KEY ] ), 'Pas de menu pour un invité' );
		wp_set_current_user( 0 );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'RGPD : export et effacement des données d\'un invité',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerG' );
		$guest = $t->user( 'gdpr' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );

		$privacy = new \WCSubscriptionTeamAccess\Privacy( $t->plugin()->seats(), $t->plugin()->team() );
		$export  = $privacy->export( $t->email( $guest ) );
		$t->same( 1, count( $export['data'] ), 'Une invitation exportée' );

		$erase = $privacy->erase( $t->email( $guest ) );
		$t->ok( $erase['items_removed'], 'Données effacées' );
		$t->ok( ! $t->is_member( $guest ), 'Accès retiré' );
		$t->same( array(), $t->plugin()->seats()->for_user_or_email( 0, strtolower( $t->email( $guest ) ) ), 'Adresse anonymisée' );
	}
);

// ---------------------------------------------------------------------------------------------
$t->test(
	'E-mails natifs Memberships désactivés pour les adhésions gérées',
	function ( WCSTA_IT $t ) {
		$owner = $t->user( 'ownerMl' );
		$guest = $t->user( 'mailguest' );
		$sub   = $t->purchase( $owner, $t->fixtures['p5'] );
		$t->invite_and_accept( $sub, $guest );
		$membership = $t->managed( $guest );
		$t->ok( false === apply_filters( 'woocommerce_email_enabled_WC_Memberships_User_Membership_Ending_Soon_Email', true, $membership ), 'E-mail « bientôt expirée » désactivé' );
		$t->ok( false === apply_filters( 'woocommerce_email_enabled_WC_Memberships_User_Membership_Renewal_Reminder_Email', true, $membership ), 'Rappel de renouvellement désactivé' );
		$actions = apply_filters( 'wc_memberships_members_area_my-memberships_actions', array( 'cancel' => array(), 'renew' => array(), 'view' => array() ), $membership );
		$t->ok( ! isset( $actions['cancel'] ) && ! isset( $actions['renew'] ) && isset( $actions['view'] ), 'Actions Annuler/Renouveler retirées de l\'espace membre' );
	}
);

// Données pour le test de concurrence (lancé ensuite par run.sh) : 4 places prises sur 5.
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
