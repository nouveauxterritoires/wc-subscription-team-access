<?php
/**
 * Enregistrement et envoi des e-mails WooCommerce de l'extension.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Emails\InvitationAcceptedEmail;
use WCSubscriptionTeamAccess\Emails\InvitationEmail;
use WCSubscriptionTeamAccess\Repository\Seat;

/**
 * Les e-mails sont des WC_Email : ils se configurent dans WooCommerce > Réglages > E-mails et
 * leurs gabarits peuvent être surchargés dans le thème (woocommerce/emails/…).
 */
final class Emails {

	public const INVITATION = 'WCSTA_Invitation';
	public const ACCEPTED   = 'WCSTA_Invitation_Accepted';

	/**
	 * Constructeur.
	 *
	 * @param InvitationController $invitations Construction des liens d'acceptation.
	 */
	public function __construct( private readonly InvitationController $invitations ) {}

	/** Branche les crochets. */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_classes' ) );
	}

	/**
	 * Ajoute les classes d'e-mails.
	 *
	 * @param array<string, \WC_Email> $classes Classes existantes.
	 * @return array<string, \WC_Email>
	 */
	public function register_classes( $classes ) {
		$classes                     = is_array( $classes ) ? $classes : array();
		$classes[ self::INVITATION ] = new InvitationEmail();
		$classes[ self::ACCEPTED ]   = new InvitationAcceptedEmail();
		return $classes;
	}

	/**
	 * Envoie l'invitation.
	 *
	 * @param Seat             $seat         Place.
	 * @param string           $token        Jeton en clair (n'est jamais stocké).
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function send_invitation( Seat $seat, string $token, \WC_Subscription $subscription ): bool {
		$email = $this->get( self::INVITATION );
		if ( ! $email instanceof InvitationEmail ) {
			return false;
		}
		return $email->trigger(
			$seat,
			$this->invitations->accept_url( $token ),
			$this->inviter_name( $subscription ),
			$this->subscription_name( $subscription )
		);
	}

	/**
	 * Prévient le titulaire qu'une invitation a été acceptée.
	 *
	 * @param Seat             $seat         Place acceptée.
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function send_accepted( Seat $seat, \WC_Subscription $subscription ): bool {
		$email = $this->get( self::ACCEPTED );
		$owner = get_userdata( (int) $subscription->get_customer_id() );
		if ( ! $email instanceof InvitationAcceptedEmail || ! $owner ) {
			return false;
		}
		return $email->trigger(
			$seat,
			(string) $owner->user_email,
			$this->subscription_name( $subscription ),
			AccountEndpoint::url()
		);
	}

	/**
	 * Nom affiché du titulaire (prénom et nom, sinon nom public).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function inviter_name( \WC_Subscription $subscription ): string {
		$name = trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() );
		if ( '' === $name ) {
			$owner = get_userdata( (int) $subscription->get_customer_id() );
			$name  = $owner ? (string) $owner->display_name : '';
		}
		// Nom saisi librement par le client : on retire balises, liens et caractères de contrôle
		// pour qu'il ne puisse pas servir à glisser un message ou une adresse dans l'invitation.
		$name = wp_strip_all_tags( $name );
		$name = (string) preg_replace( '#(?:https?://|www\.)\S*#i', '', $name );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = trim( mb_substr( $name, 0, 80 ) );

		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * Nom de l'abonnement (produits), sans information de facturation.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	private function subscription_name( \WC_Subscription $subscription ): string {
		$names = array();
		foreach ( $subscription->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		return implode( ' + ', array_filter( $names ) );
	}

	/**
	 * Instance d'un e-mail chargé par WooCommerce.
	 *
	 * @param string $id Identifiant de classe.
	 */
	private function get( string $id ): ?\WC_Email {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$emails = WC()->mailer()->get_emails();
		return isset( $emails[ $id ] ) && $emails[ $id ] instanceof \WC_Email ? $emails[ $id ] : null;
	}
}
