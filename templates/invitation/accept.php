<?php
/**
 * Contenu de la page d'invitation.
 *
 * Surcharge : votre-theme/wc-subscription-team-access/invitation/accept.php
 *
 * @package WCSubscriptionTeamAccess
 *
 * @var string $state             invalid | expired | logged_out | mismatch | ready
 * @var string $message           Message d'erreur éventuel.
 * @var string $inviter_name      Nom du titulaire.
 * @var string $subscription_name Nom de l'abonnement.
 * @var string $masked_email      Adresse invitée masquée.
 * @var string $email             Adresse invitée (visiteur déconnecté uniquement).
 * @var string $page_url          URL de la page.
 * @var string $logout_url        URL de déconnexion.
 * @var string $account_url       URL de Mon compte.
 * @var int    $min_password      Longueur minimale du mot de passe.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wcsta-invitation-card">
	<h1 class="wcsta-invitation-card__title"><?php esc_html_e( 'Invitation', 'wc-subscription-team-access' ); ?></h1>

	<?php if ( in_array( $state, array( 'invalid', 'expired' ), true ) ) : ?>

		<div class="woocommerce-error" role="alert"><?php echo esc_html( $message ); ?></div>
		<p><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to the website', 'wc-subscription-team-access' ); ?></a></p>

	<?php else : ?>

		<p class="wcsta-invitation-card__lead">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: holder name, 2: subscription name. */
					__( '<strong>%1$s</strong> invites you to share the premium access of their subscription <strong>%2$s</strong>.', 'wc-subscription-team-access' ),
					esc_html( $inviter_name ),
					esc_html( $subscription_name )
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<p class="wcsta-invitation-card__email">
			<?php
			/* translators: %s: masked email address. */
			echo esc_html( sprintf( __( 'Invitation sent to: %s', 'wc-subscription-team-access' ), $masked_email ) );
			?>
		</p>

		<?php if ( 'ready' === $state ) : ?>

			<form method="post" class="woocommerce-form wcsta-accept-form">
				<?php wp_nonce_field( 'wcsta_accept', '_wcsta_nonce' ); ?>
				<input type="hidden" name="wcsta_invitation_action" value="accept">
				<button type="submit" class="woocommerce-button button alt"><?php esc_html_e( 'Accept the invitation', 'wc-subscription-team-access' ); ?></button>
			</form>

		<?php elseif ( 'mismatch' === $state ) : ?>

			<div class="woocommerce-info" role="status"><?php echo esc_html( $message ); ?></div>
			<p><?php esc_html_e( 'Log out, then log in with the account that uses the invited email address, or create an account with it.', 'wc-subscription-team-access' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'wc-subscription-team-access' ); ?></a></p>

		<?php elseif ( 'logged_out' === $state ) : ?>

			<div class="wcsta-invitation-columns u-columns col2-set">
				<div class="u-column1 col-1 wcsta-invitation-column">
					<h2><?php esc_html_e( 'I already have an account', 'wc-subscription-team-access' ); ?></h2>
					<p><?php esc_html_e( 'Log in with the account that uses the invited email address.', 'wc-subscription-team-access' ); ?></p>
					<?php
					woocommerce_login_form(
						array(
							'redirect' => $page_url,
							'hidden'   => false,
						)
					);
					?>
				</div>

				<div class="u-column2 col-2 wcsta-invitation-column">
					<h2><?php esc_html_e( 'I am creating my account', 'wc-subscription-team-access' ); ?></h2>
					<form method="post" class="woocommerce-form woocommerce-form-register register wcsta-register-form">
						<?php wp_nonce_field( 'wcsta_register', '_wcsta_nonce' ); ?>
						<input type="hidden" name="wcsta_invitation_action" value="register">

						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="wcsta_email"><?php esc_html_e( 'Email address', 'wc-subscription-team-access' ); ?></label>
							<input type="email" class="woocommerce-Input input-text" id="wcsta_email" value="<?php echo esc_attr( $email ); ?>" readonly aria-readonly="true">
						</p>
						<p class="woocommerce-form-row form-row form-row-first">
							<label for="wcsta_first_name"><?php esc_html_e( 'First name', 'wc-subscription-team-access' ); ?></label>
							<input type="text" class="woocommerce-Input input-text" name="wcsta_first_name" id="wcsta_first_name" autocomplete="given-name">
						</p>
						<p class="woocommerce-form-row form-row form-row-last">
							<label for="wcsta_last_name"><?php esc_html_e( 'Last name', 'wc-subscription-team-access' ); ?></label>
							<input type="text" class="woocommerce-Input input-text" name="wcsta_last_name" id="wcsta_last_name" autocomplete="family-name">
						</p>
						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="wcsta_password"><?php esc_html_e( 'Password', 'wc-subscription-team-access' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
							<input type="password" class="woocommerce-Input input-text" name="wcsta_password" id="wcsta_password" autocomplete="new-password" required minlength="<?php echo (int) $min_password; ?>">
							<small>
								<?php
								/* translators: %d: minimum number of characters. */
								echo esc_html( sprintf( __( 'At least %d characters.', 'wc-subscription-team-access' ), (int) $min_password ) );
								?>
							</small>
						</p>
						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="wcsta_password_confirm"><?php esc_html_e( 'Confirm password', 'wc-subscription-team-access' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
							<input type="password" class="woocommerce-Input input-text" name="wcsta_password_confirm" id="wcsta_password_confirm" autocomplete="new-password" required minlength="<?php echo (int) $min_password; ?>">
						</p>
						<?php
						if ( function_exists( 'wc_registration_privacy_policy_text' ) ) {
							wc_registration_privacy_policy_text();
						}
						?>
						<p class="woocommerce-form-row form-row">
							<button type="submit" class="woocommerce-Button woocommerce-button button"><?php esc_html_e( 'Create my account and accept', 'wc-subscription-team-access' ); ?></button>
						</p>
					</form>
				</div>
			</div>

		<?php endif; ?>

	<?php endif; ?>
</div>
