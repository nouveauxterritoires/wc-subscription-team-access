<?php
/**
 * Commandes WP-CLI : wp team-access …
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\CLI;

use WCSubscriptionTeamAccess\Labels;
use WCSubscriptionTeamAccess\QuotaManager;
use WCSubscriptionTeamAccess\Reconciler;
use WCSubscriptionTeamAccess\Repository\SeatRepository;
use WCSubscriptionTeamAccess\Scheduler;
use WCSubscriptionTeamAccess\SubscriptionManager;
use WCSubscriptionTeamAccess\Support\Dates;
use WCSubscriptionTeamAccess\TeamManager;

/**
 * Consulte et pilote les accès partagés des abonnements.
 */
final class Command {

	/**
	 * Constructeur.
	 *
	 * @param TeamManager         $team          Opérations.
	 * @param Reconciler          $reconciler    Synchronisation.
	 * @param SubscriptionManager $subscriptions Abonnements.
	 * @param QuotaManager        $quotas        Quotas.
	 * @param SeatRepository      $seats         Places.
	 */
	public function __construct(
		private readonly TeamManager $team,
		private readonly Reconciler $reconciler,
		private readonly SubscriptionManager $subscriptions,
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats
	) {}

	/**
	 * Affiche l'état d'un abonnement : quota, places, droits et plans partagés.
	 *
	 * ## OPTIONS
	 *
	 * <subscription_id>
	 * : Identifiant de l'abonnement.
	 *
	 * [--format=<format>]
	 * : table, json ou csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp team-access status 1234
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$subscription = $this->subscription( (int) $args[0] );
		$summary      = $this->team->summary( $subscription );
		$access       = $this->subscriptions->effective_access( $subscription );

		\WP_CLI::log( sprintf( 'Subscription #%d — %s (%s)', $subscription->get_id(), $this->subscriptions->display_name( $subscription ), $subscription->get_status() ) );
		\WP_CLI::log( sprintf( 'Guest access: %s%s', $access->state, $access->end_ts ? ' until ' . gmdate( 'c', $access->end_ts ) : '' ) );
		\WP_CLI::log( sprintf( 'Plans: %s', implode( ', ', $this->subscriptions->plan_ids( $subscription ) ) ?: 'none' ) );
		\WP_CLI::log(
			sprintf(
				'Quota: %d (snapshot %s, override %s) — used %d, available %d, over %d',
				$summary['quota'],
				$this->quotas->snapshot_value( $subscription ) ?? '-',
				$this->quotas->override( $subscription ) ?? '-',
				$summary['used'],
				$summary['available'],
				$summary['over']
			)
		);

		$rows = array();
		foreach ( $this->seats->for_subscription( $subscription->get_id() ) as $seat ) {
			$rows[] = array(
				'seat'     => $seat->id,
				'email'    => $seat->email,
				'user'     => $seat->user_id,
				'status'   => $seat->status,
				'access'   => $seat->access . ( $seat->access_reason ? ' (' . $seat->access_reason . ')' : '' ),
				'label'    => Labels::status( $seat ),
				'invited'  => Dates::to_mysql( $seat->created_at ),
				'accepted' => Dates::to_mysql( $seat->accepted_at ) ?? '',
			);
		}

		if ( $rows ) {
			\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array_keys( $rows[0] ) );
		} else {
			\WP_CLI::log( 'No seat.' );
		}
	}

	/**
	 * Invite une adresse sur un abonnement (en tant qu'administrateur).
	 *
	 * ## OPTIONS
	 *
	 * <subscription_id>
	 * : Abonnement.
	 *
	 * <email>
	 * : Adresse invitée.
	 *
	 * [--as=<user_id>]
	 * : Agir en tant que cet utilisateur (par défaut : premier administrateur).
	 *
	 * ## EXAMPLES
	 *
	 *     wp team-access invite 1234 jeanne@example.org
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function invite( array $args, array $assoc_args ): void {
		$result = $this->team->invite( (int) $args[0], (string) $args[1], $this->actor( $assoc_args ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Seat #%d created, email %s.', $result['seat']->id, $result['mailed'] ? 'sent' : 'NOT sent' ) );
	}

	/**
	 * Annule une invitation ou révoque un accès.
	 *
	 * ## OPTIONS
	 *
	 * <seat_id>
	 * : Place.
	 *
	 * [--as=<user_id>]
	 * : Agir en tant que cet utilisateur.
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$result = $this->team->remove( (int) $args[0], $this->actor( $assoc_args ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Seat #%d is now %s.', $result->id, $result->status ) );
	}

	/**
	 * Renvoie une invitation.
	 *
	 * ## OPTIONS
	 *
	 * <seat_id>
	 * : Place.
	 *
	 * [--as=<user_id>]
	 * : Agir en tant que cet utilisateur.
	 *
	 * @param array<int, string>    $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function resend( array $args, array $assoc_args ): void {
		$result = $this->team->resend( (int) $args[0], $this->actor( $assoc_args ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Invitation #%d resent.', $result['seat']->id ) );
	}

	/**
	 * Resynchronise un abonnement, ou tous les abonnements partagés.
	 *
	 * ## OPTIONS
	 *
	 * [<subscription_id>]
	 * : Abonnement. Sans argument : tous, de façon synchrone.
	 *
	 * ## EXAMPLES
	 *
	 *     wp team-access sync 1234
	 *     wp team-access sync
	 *
	 * @param array<int, string> $args Arguments.
	 */
	public function sync( array $args ): void {
		if ( isset( $args[0] ) ) {
			$this->print_sync( $this->reconciler->sync_subscription( (int) $args[0], 'cli' ) );
			return;
		}

		$after = 0;
		$count = 0;
		do {
			$ids = $this->reconciler->subscription_ids( $after, 100 );
			foreach ( $ids as $id ) {
				$this->print_sync( $this->reconciler->sync_subscription( $id, 'cli' ) );
				$after = $id;
				++$count;
			}
		} while ( count( $ids ) === 100 );

		$this->reconciler->sweep_orphan_memberships();
		\WP_CLI::success( sprintf( '%d subscription(s) synchronized.', $count ) );
	}

	/**
	 * Lance la maintenance quotidienne (expirations, anonymisation, purge du journal).
	 */
	public function maintenance(): void {
		$this->reconciler->maintenance();
		\WP_CLI::success( 'Maintenance done.' );
	}

	/**
	 * Recalcule l'instantané du quota depuis le catalogue actuel (à utiliser avec précaution).
	 *
	 * ## OPTIONS
	 *
	 * <subscription_id>
	 * : Abonnement.
	 *
	 * @param array<int, string> $args Arguments.
	 */
	public function snapshot( array $args ): void {
		$subscription = $this->subscription( (int) $args[0] );
		$value        = $this->quotas->snapshot( $subscription, 'cli' );
		$this->reconciler->sync_subscription( $subscription->get_id(), 'cli_snapshot' );
		\WP_CLI::success( sprintf( 'Quota snapshot is now %d.', $value ) );
	}

	/**
	 * Planifie la synchronisation complète en tâche de fond.
	 */
	public function schedule(): void {
		( new Scheduler( $this->reconciler ) )->enqueue_batch( 0 );
		\WP_CLI::success( 'Full synchronization scheduled.' );
	}

	/**
	 * Charge un abonnement ou arrête la commande.
	 *
	 * @param int $id Identifiant.
	 */
	private function subscription( int $id ): \WC_Subscription {
		$subscription = $this->subscriptions->get( $id );
		if ( ! $subscription ) {
			\WP_CLI::error( sprintf( 'Subscription #%d not found.', $id ) );
		}
		return $subscription;
	}

	/**
	 * Acteur des opérations.
	 *
	 * @param array<string, string> $assoc_args Options.
	 */
	private function actor( array $assoc_args ): int {
		if ( isset( $assoc_args['as'] ) ) {
			return (int) $assoc_args['as'];
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		return $admins ? (int) $admins[0] : 0;
	}

	/**
	 * Affiche le résultat d'une synchronisation.
	 *
	 * @param array<string, mixed>|\WP_Error $result Résultat.
	 */
	private function print_sync( array|\WP_Error $result ): void {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::warning( $result->get_error_message() );
			return;
		}
		\WP_CLI::log(
			sprintf(
				'#%d access=%s quota=%d used=%d over=%d plans=[%s] memberships=%s',
				$result['subscription_id'],
				$result['access'],
				$result['quota'],
				$result['used'],
				$result['over'],
				implode( ',', $result['plans'] ),
				(string) wp_json_encode( $result['memberships'] )
			)
		);
	}
}
