<?php
/**
 * Outils de la suite d'intégration (WordPress, WooCommerce, Subscriptions et Memberships réels).
 *
 * Exécution : bash tests/integration/run.sh (voir README). Ne jamais exposer ces fichiers
 * sur un serveur web : ils créent et suppriment des comptes et des abonnements de test.
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

use WCSubscriptionTeamAccess\Plugin;

/**
 * Mini-framework d'assertions, fixtures et capture des e-mails.
 */
final class WCSTA_IT {

	public const PREFIX = 'wcsta-it-';

	/** @var array<int, array{name:string, ok:bool, message:string}> */
	public array $results = array();

	/** @var array<int, array<string, mixed>> E-mails capturés (aucun n'est réellement envoyé). */
	public array $mails = array();

	/** @var array<string, mixed> Fixtures créées par setup.php. */
	public array $fixtures = array();

	/** @var int[] Utilisateurs créés pendant les scénarios. */
	public array $created_users = array();

	/** @var int[] Commandes et abonnements créés pendant les scénarios. */
	public array $created_orders = array();

	private string $current = '';

	/**
	 * Constructeur.
	 *
	 * @param string $fixtures_file Fichier JSON des fixtures.
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
	 * Exécute un scénario.
	 *
	 * @param string   $name Nom.
	 * @param callable $test Scénario.
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
	 * @param string $message   Message d'échec.
	 * @throws \RuntimeException En cas d'échec.
	 */
	public function ok( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * Égalité stricte.
	 *
	 * @param mixed  $expected Attendu.
	 * @param mixed  $actual   Obtenu.
	 * @param string $message  Contexte.
	 */
	public function same( mixed $expected, mixed $actual, string $message ): void {
		$this->ok( $expected === $actual, $message . ' — attendu ' . var_export( $expected, true ) . ', obtenu ' . var_export( $actual, true ) );
	}

	/**
	 * Le résultat est une erreur portant ce code.
	 *
	 * @param mixed  $result Résultat.
	 * @param string $code   Code attendu (sans préfixe).
	 */
	public function error( mixed $result, string $code ): void {
		$this->ok( is_wp_error( $result ), "Erreur « {$code} » attendue, succès obtenu" );
		$this->same( 'wcsta_' . $code, $result->get_error_code(), 'Code d\'erreur' );
	}

	/**
	 * Le résultat n'est pas une erreur.
	 *
	 * @param mixed  $result  Résultat.
	 * @param string $context Contexte.
	 */
	public function success( mixed $result, string $context ): mixed {
		$this->ok( ! is_wp_error( $result ), $context . ' : ' . ( is_wp_error( $result ) ? $result->get_error_code() . ' — ' . $result->get_error_message() : '' ) );
		return $result;
	}

	/** Services de l'extension. */
	public function plugin(): Plugin {
		return Plugin::instance();
	}

	/** Plan de test. */
	public function plan(): int {
		return (int) $this->fixtures['plan_id'];
	}

	/**
	 * Crée un utilisateur de test.
	 *
	 * @param string $label Libellé.
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
	 * E-mail d'un utilisateur.
	 *
	 * @param int $user_id Utilisateur.
	 */
	public function email( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	/**
	 * Simule l'achat d'un produit d'abonnement : commande payée + abonnement actif.
	 * Memberships accorde alors l'adhésion du titulaire et la lie à l'abonnement.
	 *
	 * @param int $user_id    Acheteur.
	 * @param int $product_id Produit ou variation.
	 * @param int $quantity   Quantité.
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
		$this->same( 'active', $subscription->get_status(), 'Abonnement actif après paiement' );
		return $subscription;
	}

	/**
	 * Recharge un abonnement.
	 *
	 * @param int $id Abonnement.
	 */
	public function reload( int $id ): \WC_Subscription {
		return wcs_get_subscription( $id );
	}

	/**
	 * Invite et retourne la place.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param string           $email        Adresse.
	 * @param int              $actor        Acteur (titulaire par défaut).
	 */
	public function invite( \WC_Subscription $subscription, string $email, int $actor = 0 ): \WCSubscriptionTeamAccess\Repository\Seat {
		$result = $this->success(
			$this->plugin()->team()->invite( $subscription->get_id(), $email, $actor ?: (int) $subscription->get_customer_id() ),
			"Invitation de {$email}"
		);
		$this->ok( $result['mailed'], "E-mail d'invitation envoyé à {$email}" );
		return $result['seat'];
	}

	/**
	 * Jeton contenu dans le dernier e-mail envoyé à une adresse.
	 *
	 * @param string $email Adresse.
	 */
	public function token_for( string $email ): string {
		foreach ( array_reverse( $this->mails ) as $mail ) {
			if ( $this->mail_is_for( $mail, $email ) && preg_match( '/[?&](?:amp;)?token=([A-Za-z0-9_-]{43})/', (string) $mail['message'], $m ) ) {
				return $m[1];
			}
		}
		throw new \RuntimeException( "Aucun e-mail d'invitation pour {$email}" );
	}

	/**
	 * Un e-mail capturé est destiné à une adresse. Un éventuel filtre « wp_mail » de recette
	 * peut rediriger le destinataire : l'adresse d'origine est alors cherchée dans l'objet.
	 *
	 * @param array<string, mixed> $mail  E-mail capturé.
	 * @param string               $email Adresse.
	 */
	public function mail_is_for( array $mail, string $email ): bool {
		$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
		return str_contains( strtolower( $to . ' ' . (string) ( $mail['subject'] ?? '' ) ), strtolower( $email ) );
	}

	/**
	 * Invite puis accepte avec un compte.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param int              $user_id      Compte invité.
	 */
	public function invite_and_accept( \WC_Subscription $subscription, int $user_id ): \WCSubscriptionTeamAccess\Repository\Seat {
		$email = $this->email( $user_id );
		$this->invite( $subscription, $email );
		return $this->success( $this->plugin()->team()->accept( $this->token_for( $email ), $user_id ), "Acceptation par {$email}" );
	}

	/**
	 * Membre actif du plan selon Memberships (sans cache).
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $plan_id Plan (plan de test par défaut).
	 */
	public function is_member( int $user_id, int $plan_id = 0 ): bool {
		$this->reset_memberships_cache();
		return (bool) wc_memberships()->get_user_memberships_instance()->is_user_member( $user_id, $plan_id ?: $this->plan(), true, false );
	}

	/**
	 * L'utilisateur porte le rôle « membre actif » de Memberships (si l'option d'attribution des
	 * rôles est activée ; sinon la vérification est sans objet et renvoie la valeur attendue).
	 *
	 * @param int  $user_id  Utilisateur.
	 * @param bool $expected Valeur attendue lorsque l'option est désactivée.
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
	 * Vide la mémoïsation interne de Memberships. Elle ne vit que le temps d'une requête PHP ;
	 * la suite enchaîne de nombreuses opérations dans un même processus.
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
	 * Nombre d'adhésions gérées pour un utilisateur.
	 *
	 * @param int $user_id Utilisateur.
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
	 * Adhésion gérée.
	 *
	 * @param int $user_id Utilisateur.
	 */
	public function managed( int $user_id ): ?\WC_Memberships_User_Membership {
		return $this->plugin()->memberships()->managed_membership( $user_id, $this->plan() );
	}

	/**
	 * Place relue.
	 *
	 * @param int $seat_id Place.
	 */
	public function seat( int $seat_id ): \WCSubscriptionTeamAccess\Repository\Seat {
		return $this->plugin()->seats()->find( $seat_id );
	}

	/**
	 * Contenu restreint vu par un utilisateur (shortcode Memberships natif).
	 *
	 * @param int $user_id Utilisateur.
	 */
	public function sees_restricted_content( int $user_id ): bool {
		$this->reset_memberships_cache();
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		$html = do_shortcode( '[wcm_restrict]WCSTA_SECRET[/wcm_restrict]' );
		wp_set_current_user( $previous );
		return str_contains( $html, 'WCSTA_SECRET' );
	}

	/** Supprime les données créées par les scénarios. */
	public function cleanup_created(): void {
		global $wpdb;
		$seats = $wpdb->prefix . 'wcsta_seats';
		foreach ( array_unique( $this->created_orders ) as $id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wcsta_grants WHERE subscription_id = %d", $id ) ); // phpcs:ignore
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

	/** Bilan, et code de sortie non nul en cas d'échec. */
	public function report(): void {
		$failed = array_filter( $this->results, static fn( array $r ): bool => ! $r['ok'] );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%d scénario(s), %d réussi(s), %d échec(s).', count( $this->results ), count( $this->results ) - count( $failed ), count( $failed ) ) );
		if ( $failed ) {
			WP_CLI::error( 'Échecs : ' . implode( ' | ', array_column( $failed, 'name' ) ) );
		}
		WP_CLI::success( 'Tous les scénarios sont passés.' );
	}
}
