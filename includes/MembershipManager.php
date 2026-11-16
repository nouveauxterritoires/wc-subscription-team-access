<?php
/**
 * Pilotage des adhésions WooCommerce Memberships des invités, via les API publiques.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\AccessAggregator;
use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Repository\GrantRepository;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Support\Lock;

/**
 * Modèle d'attribution (voir README, « Modèle des droits ») :
 *
 * - Memberships ne connaît qu'une adhésion « logique » par (utilisateur, plan) : la plus récente
 *   (wc_memberships_get_user_membership / is_user_active_member) ; un achat ultérieur la réutilise.
 * - L'extension crée donc au plus UNE adhésion « gérée » par (invité, plan), marquée par la méta
 *   _wcsta_managed, sans commande ni abonnement liés. Son état est l'agrégat de toutes les sources
 *   de droits (places acceptées) enregistrées dans la table wcsta_grants.
 * - Une adhésion indépendante (achetée, offerte par un administrateur…) n'est jamais modifiée.
 *   Si elle est active, l'adhésion gérée est supprimée : elle ne doit jamais masquer une adhésion
 *   personnelle plus ancienne.
 * - Si Memberships réutilise l'adhésion gérée pour un achat de l'invité (commande ou abonnement
 *   liés), elle devient indépendante : l'extension la « libère » et n'y touche plus.
 */
final class MembershipManager {

	public const META_MANAGED  = '_wcsta_managed';
	public const META_SOURCES  = '_wcsta_sources';
	public const META_RELEASED = '_wcsta_released_at';

	public const RESULT_NONE      = 'none';
	public const RESULT_CREATED   = 'created';
	public const RESULT_UPDATED   = 'updated';
	public const RESULT_UNCHANGED = 'unchanged';
	public const RESULT_DELETED   = 'deleted';
	public const RESULT_COVERED   = 'covered';
	public const RESULT_SKIPPED   = 'skipped';
	public const RESULT_ERROR     = 'error';

	/** E-mails natifs de Memberships à ne pas envoyer pour une adhésion gérée. */
	private const EMAIL_IDS = array(
		'WC_Memberships_User_Membership_Activated_Email',
		'WC_Memberships_User_Membership_Ended_Email',
		'WC_Memberships_User_Membership_Ending_Soon_Email',
		'WC_Memberships_User_Membership_Renewal_Reminder_Email',
	);

	/** Profondeur des opérations en cours (les événements que nous déclenchons sont ignorés). */
	private static int $applying = 0;

	/** Création d'adhésion gérée en cours (la méta n'est pas encore posée). */
	private static bool $creating = false;

	/**
	 * Constructeur.
	 *
	 * @param GrantRepository $grants Sources de droits.
	 * @param Logger          $logger Journal.
	 */
	public function __construct(
		private readonly GrantRepository $grants,
		private readonly Logger $logger
	) {}

	/** Branche les crochets Memberships. */
	public function register(): void {
		foreach ( self::EMAIL_IDS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( $this, 'filter_email_enabled' ), 99, 2 );
		}
		add_filter( 'wc_memberships_members_area_my-memberships_actions', array( $this, 'filter_member_actions' ), 99, 2 );
		add_filter( 'wc_memberships_members_area_my-membership-details_actions', array( $this, 'filter_member_actions' ), 99, 2 );

		add_action( 'wc_memberships_grant_membership_access_from_purchase', array( $this, 'on_granted_from_purchase' ), 5, 2 );
		add_action( 'wc_memberships_user_membership_created', array( $this, 'on_membership_created' ), 20, 2 );
		add_action( 'wc_memberships_user_membership_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
	}

	/**
	 * Rapproche l'adhésion gérée d'un invité de l'agrégat de ses sources de droits.
	 * Idempotent : un second appel sans changement de sources ne modifie rien.
	 *
	 * @param int $user_id Invité.
	 * @param int $plan_id Plan.
	 * @return string Résultat (constantes RESULT_*).
	 */
	public function reconcile( int $user_id, int $plan_id ): string {
		if ( ! function_exists( 'wc_memberships_get_membership_plan' ) || $user_id <= 0 || $plan_id <= 0 ) {
			return self::RESULT_SKIPPED;
		}

		$result = Lock::run(
			"user_plan_{$user_id}_{$plan_id}",
			function () use ( $user_id, $plan_id ): string {
				++self::$applying;
				try {
					return $this->apply( $user_id, $plan_id );
				} catch ( \Throwable $e ) {
					$this->logger->error(
						'membership_sync_failed',
						$e->getMessage(),
						array(
							'user_id' => $user_id,
							'plan_id' => $plan_id,
						)
					);
					return self::RESULT_ERROR;
				} finally {
					--self::$applying;
				}
			}
		);

		if ( $result instanceof \WP_Error ) {
			$this->logger->warning(
				'membership_sync_busy',
				'Membership sync postponed: lock busy.',
				array(
					'user_id' => $user_id,
					'plan_id' => $plan_id,
				)
			);
			Scheduler::enqueue_user_plan( $user_id, $plan_id );
			return self::RESULT_ERROR;
		}

		return $result;
	}

	/**
	 * Adhésions d'un utilisateur pour un plan, de la plus récente à la plus ancienne
	 * (même ordre que Memberships).
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $plan_id Plan.
	 * @return \WC_Memberships_User_Membership[]
	 */
	public function memberships_for( int $user_id, int $plan_id ): array {
		$posts = get_posts(
			array(
				'author'           => $user_id,
				'post_type'        => 'wc_user_membership',
				'post_parent'      => $plan_id,
				'post_status'      => array_keys( wc_memberships_get_user_membership_statuses() ),
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$memberships = array();
		foreach ( $posts as $post ) {
			$membership = wc_memberships_get_user_membership( $post );
			if ( $membership instanceof \WC_Memberships_User_Membership ) {
				$memberships[] = $membership;
			}
		}
		return $memberships;
	}

	/**
	 * Adhésion gérée d'un invité pour un plan, s'il y en a une.
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $plan_id Plan.
	 */
	public function managed_membership( int $user_id, int $plan_id ): ?\WC_Memberships_User_Membership {
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $membership ) {
			if ( $this->is_managed( $membership ) ) {
				return $membership;
			}
		}
		return null;
	}

	/**
	 * L'adhésion est gérée par l'extension.
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 */
	public function is_managed( \WC_Memberships_User_Membership $membership ): bool {
		return '1' === (string) get_post_meta( $membership->get_id(), self::META_MANAGED, true );
	}

	/**
	 * Filtre : désactive les e-mails natifs de Memberships pour les adhésions gérées.
	 *
	 * @param bool  $enabled E-mail actif.
	 * @param mixed $object  Objet de l'e-mail.
	 */
	public function filter_email_enabled( $enabled, $object = null ) {
		if ( ! $enabled || 'yes' !== Settings::get( 'suppress_membership_emails' ) ) {
			return $enabled;
		}
		if ( self::$creating ) {
			return false;
		}
		if ( $object instanceof \WC_Memberships_User_Membership && $this->is_managed( $object ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Filtre : retire « Annuler » et « Renouveler » de l'espace membre pour une adhésion gérée.
	 *
	 * @param array<string, mixed>            $actions    Actions.
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 * @return array<string, mixed>
	 */
	public function filter_member_actions( $actions, $membership ) {
		if ( is_array( $actions ) && $membership instanceof \WC_Memberships_User_Membership && $this->is_managed( $membership ) ) {
			unset( $actions['cancel'], $actions['renew'] );
		}
		return $actions;
	}

	/**
	 * Un achat de l'invité a réutilisé l'adhésion gérée : elle devient personnelle.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Plan.
	 * @param array<string, mixed>            $args Arguments (user_membership_id…).
	 */
	public function on_granted_from_purchase( $plan, $args ): void {
		$membership_id = (int) ( $args['user_membership_id'] ?? 0 );
		if ( $membership_id <= 0 ) {
			return;
		}
		$membership = wc_memberships_get_user_membership( $membership_id );
		if ( $membership instanceof \WC_Memberships_User_Membership && $this->is_managed( $membership ) ) {
			$this->release( $membership );
		}
	}

	/**
	 * Une adhésion indépendante vient d'être créée : réévaluer immédiatement.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Plan.
	 * @param array<string, mixed>            $args Arguments (user_id, user_membership_id…).
	 */
	public function on_membership_created( $plan, $args ): void {
		if ( self::$applying > 0 || ! $plan instanceof \WC_Memberships_Membership_Plan ) {
			return;
		}
		$user_id = (int) ( $args['user_id'] ?? 0 );
		if ( $user_id > 0 && $this->grants->for_user_plan( $user_id, (int) $plan->get_id() ) ) {
			$this->reconcile( $user_id, (int) $plan->get_id() );
		}
	}

	/**
	 * Changement de statut d'une adhésion fait par un tiers.
	 *
	 * - adhésion indépendante : réévaluation immédiate (elle peut désormais couvrir l'invité,
	 *   ou ne plus le couvrir) ;
	 * - adhésion gérée modifiée à la main : l'extension fait autorité, la réévaluation est
	 *   planifiée en tâche de fond (évite toute boucle).
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 * @param string                          $old_status Ancien statut.
	 * @param string                          $new_status Nouveau statut.
	 */
	public function on_status_changed( $membership, $old_status, $new_status ): void {
		unset( $old_status, $new_status );
		if ( self::$applying > 0 || ! $membership instanceof \WC_Memberships_User_Membership ) {
			return;
		}

		$user_id = (int) $membership->get_user_id();
		$plan_id = (int) $membership->get_plan_id();

		if ( $this->is_managed( $membership ) ) {
			Scheduler::enqueue_user_plan( $user_id, $plan_id );
			return;
		}
		if ( $this->grants->for_user_plan( $user_id, $plan_id ) ) {
			$this->reconcile( $user_id, $plan_id );
		}
	}

	/**
	 * Suppression d'une adhésion par un tiers : réévaluation en tâche de fond.
	 *
	 * @param int           $post_id Identifiant.
	 * @param \WP_Post|null $post    Contenu supprimé.
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		if ( self::$applying > 0 || ! $post instanceof \WP_Post || 'wc_user_membership' !== $post->post_type ) {
			return;
		}
		if ( $this->grants->for_user_plan( (int) $post->post_author, (int) $post->post_parent ) ) {
			Scheduler::enqueue_user_plan( (int) $post->post_author, (int) $post->post_parent );
		}
	}

	/**
	 * Cœur du rapprochement (appelé sous verrou).
	 *
	 * @param int $user_id Invité.
	 * @param int $plan_id Plan.
	 */
	private function apply( int $user_id, int $plan_id ): string {
		if ( ! wc_memberships_get_membership_plan( $plan_id ) || ! get_userdata( $user_id ) ) {
			return self::RESULT_SKIPPED;
		}

		$sources = $this->grants->for_user_plan( $user_id, $plan_id );
		$desired = AccessAggregator::aggregate( array_column( $sources, 'state' ) );

		$managed     = array();
		$independent = array();
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $membership ) {
			if ( $this->is_managed( $membership ) && $this->is_claimed_by_purchase( $membership ) ) {
				$this->release( $membership );
			}
			if ( $this->is_managed( $membership ) ) {
				$managed[] = $membership;
			} else {
				$independent[] = $membership;
			}
		}

		$covered = false;
		foreach ( $independent as $membership ) {
			if ( $membership->is_active() ) {
				$covered = true;
				break;
			}
		}

		if ( $desired->is_none() || $covered ) {
			foreach ( $managed as $membership ) {
				$this->delete( $membership, $covered ? 'covered_by_independent_membership' : 'no_active_source' );
			}
			if ( $covered && ! $desired->is_none() ) {
				return self::RESULT_COVERED;
			}
			return $managed ? self::RESULT_DELETED : self::RESULT_NONE;
		}

		// Doublons éventuels : on garde la plus récente.
		$membership = array_shift( $managed );
		foreach ( $managed as $duplicate ) {
			$this->delete( $duplicate, 'duplicate' );
		}

		// Une adhésion indépendante plus récente (inactive) masquerait la nôtre : on la recrée.
		if ( $membership && $this->is_shadowed( $membership, $user_id, $plan_id ) ) {
			$this->delete( $membership, 'shadowed' );
			$membership = null;
		}

		$result = self::RESULT_UNCHANGED;
		if ( ! $membership ) {
			$membership = $this->create( $user_id, $plan_id );
			$result     = self::RESULT_CREATED;
		}

		$changed = $this->apply_state( $membership, $desired );
		$this->update_sources( $membership, $sources );

		if ( self::RESULT_CREATED === $result ) {
			return $result;
		}
		return $changed ? self::RESULT_UPDATED : self::RESULT_UNCHANGED;
	}

	/**
	 * Crée l'adhésion gérée.
	 *
	 * @param int $user_id Invité.
	 * @param int $plan_id Plan.
	 * @throws \RuntimeException Si Memberships refuse la création.
	 */
	private function create( int $user_id, int $plan_id ): \WC_Memberships_User_Membership {
		// Memberships retient l'adhésion de date la plus récente : à la seconde près, deux
		// adhésions seraient départagées arbitrairement. La nôtre est datée strictement après.
		$latest = 0;
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $existing ) {
			$latest = max( $latest, (int) strtotime( (string) get_post_field( 'post_date_gmt', $existing->get_id() ) . ' UTC' ) );
		}
		$created_at = max( time(), $latest + 1 );
		$set_date   = static function ( array $data ) use ( $created_at ): array {
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $created_at );
			$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
			return $data;
		};

		self::$creating = true;
		add_filter( 'wc_memberships_new_membership_data', $set_date, 999 );
		try {
			$membership = wc_memberships_create_user_membership(
				array(
					'plan_id' => $plan_id,
					'user_id' => $user_id,
				),
				'create'
			);
		} finally {
			remove_filter( 'wc_memberships_new_membership_data', $set_date, 999 );
			self::$creating = false;
		}

		if ( ! $membership instanceof \WC_Memberships_User_Membership ) {
			throw new \RuntimeException( 'WooCommerce Memberships did not return a user membership.' );
		}

		update_post_meta( $membership->get_id(), self::META_MANAGED, '1' );
		$membership->add_note( __( 'Guest membership created by WC Subscription Team Access: access is shared by a subscription holder.', 'wc-subscription-team-access' ) );

		$this->logger->info(
			'membership_created',
			sprintf( 'Guest membership #%d created.', $membership->get_id() ),
			array(
				'user_id' => $user_id,
				'plan_id' => $plan_id,
			)
		);

		return $membership;
	}

	/**
	 * Aligne statut et date de fin de l'adhésion gérée sur l'état souhaité.
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion gérée.
	 * @param AccessState                     $desired    État souhaité (actif ou en pause).
	 * @return bool Modification effectuée.
	 */
	private function apply_state( \WC_Memberships_User_Membership $membership, AccessState $desired ): bool {
		$changed = false;

		$current_end = $membership->get_end_date( 'timestamp', false );
		$current_end = $current_end ? (int) $current_end : null;

		// La date de fin est posée avant l'activation : une date passée ferait expirer l'adhésion.
		if ( $desired->is_active() && $current_end !== $desired->end_ts ) {
			$membership->set_end_date( null === $desired->end_ts ? '' : gmdate( 'Y-m-d H:i:s', $desired->end_ts ) );
			$changed = true;
		}

		$status = (string) $membership->get_status();

		if ( $desired->is_active() && 'active' !== $status ) {
			$membership->activate_membership( __( 'Guest access activated: the sharing subscription grants access.', 'wc-subscription-team-access' ) );
			$changed = true;
		} elseif ( $desired->is_paused() && 'paused' !== $status ) {
			$membership->pause_membership( __( 'Guest access suspended: the sharing subscription is on hold or the seat is over quota.', 'wc-subscription-team-access' ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Supprime une adhésion gérée.
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 * @param string                          $reason     Motif.
	 */
	private function delete( \WC_Memberships_User_Membership $membership, string $reason ): void {
		$id      = $membership->get_id();
		$user_id = (int) $membership->get_user_id();
		$plan_id = (int) $membership->get_plan_id();

		// L'annulation passe par la transition de statut native : Memberships y recalcule le rôle
		// du membre (option « attribuer des rôles aux membres ») en tenant compte de ses autres
		// adhésions actives. Une suppression directe laisserait le rôle de membre actif en place.
		if ( ! in_array( (string) $membership->get_status(), array( 'cancelled', 'expired' ), true ) ) {
			$membership->cancel_membership( __( 'Guest access ended: no active sharing subscription for this member.', 'wc-subscription-team-access' ) );
		}

		wp_delete_post( $id, true );

		$this->logger->info(
			'membership_deleted',
			sprintf( 'Guest membership #%d deleted (%s).', $id, $reason ),
			array(
				'user_id' => $user_id,
				'plan_id' => $plan_id,
			)
		);
	}

	/**
	 * Libère une adhésion gérée réutilisée par un achat : elle devient indépendante.
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 */
	private function release( \WC_Memberships_User_Membership $membership ): void {
		delete_post_meta( $membership->get_id(), self::META_MANAGED );
		delete_post_meta( $membership->get_id(), self::META_SOURCES );
		update_post_meta( $membership->get_id(), self::META_RELEASED, (string) time() );

		++self::$applying;
		try {
			$membership->add_note( __( 'This membership is now linked to the member\'s own purchase and is no longer managed by WC Subscription Team Access.', 'wc-subscription-team-access' ) );
		} finally {
			--self::$applying;
		}

		$this->logger->info(
			'membership_released',
			sprintf( 'Guest membership #%d released to the member\'s own purchase.', $membership->get_id() ),
			array(
				'user_id' => (int) $membership->get_user_id(),
				'plan_id' => (int) $membership->get_plan_id(),
			)
		);
	}

	/**
	 * L'adhésion gérée a été reprise par un achat (commande, produit ou abonnement liés).
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion.
	 */
	private function is_claimed_by_purchase( \WC_Memberships_User_Membership $membership ): bool {
		if ( (int) $membership->get_order_id() > 0 || (int) $membership->get_product_id() > 0 ) {
			return true;
		}
		return function_exists( 'wc_memberships_is_user_membership_linked_to_subscription' )
			&& wc_memberships_is_user_membership_linked_to_subscription( $membership );
	}

	/**
	 * Une autre adhésion est plus récente que l'adhésion gérée : Memberships ne verrait pas la nôtre.
	 *
	 * @param \WC_Memberships_User_Membership $membership Adhésion gérée.
	 * @param int                             $user_id    Utilisateur.
	 * @param int                             $plan_id    Plan.
	 */
	private function is_shadowed( \WC_Memberships_User_Membership $membership, int $user_id, int $plan_id ): bool {
		$newest = get_posts(
			array(
				'author'           => $user_id,
				'post_type'        => 'wc_user_membership',
				'post_parent'      => $plan_id,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return ! empty( $newest ) && (int) $newest[0] !== $membership->get_id();
	}

	/**
	 * Mémorise les abonnements sources sur l'adhésion (affichage administrateur).
	 *
	 * @param \WC_Memberships_User_Membership                                  $membership Adhésion.
	 * @param array<int, array{seat_id:int, subscription_id:int, state:AccessState}> $sources    Sources.
	 */
	private function update_sources( \WC_Memberships_User_Membership $membership, array $sources ): void {
		$subscription_ids = array_values( array_unique( array_map( 'intval', array_column( $sources, 'subscription_id' ) ) ) );
		sort( $subscription_ids );
		if ( get_post_meta( $membership->get_id(), self::META_SOURCES, true ) !== $subscription_ids ) {
			update_post_meta( $membership->get_id(), self::META_SOURCES, $subscription_ids );
		}
	}
}
