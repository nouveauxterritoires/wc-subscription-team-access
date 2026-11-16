<?php
/**
 * Invitation page content.
 *
 * Override: your-theme/exprime-team-access/invitation/accept.php
 *
 * @package ExprimeTeamAccess
 *
 * @var string $state             invalid | expired | logged_out | mismatch | ready
 * @var string $message           Optional error message.
 * @var string $inviter_name      Plan holder name.
 * @var string $subscription_name Subscription name.
 * @var string $masked_email      Masked invited email address.
 * @var string $email             Invited email address (logged-out visitors only).
 * @var string $page_url          Page URL.
 * @var string $logout_url        Logout URL.
 * @var string $account_url       My Account URL.
 * @var int    $min_password      Minimum password length.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="exprime-team-invitation-card">
	<h1 class="exprime-team-invitation-card__title"><?php esc_html_e( 'Invitation', 'exprime-team-access' ); ?></h1>

	<?php if ( in_array( $state, array( 'invalid', 'expired' ), true ) ) : ?>

		<div class="woocommerce-error" role="alert"><?php echo esc_html( $message ); ?></div>
		<p><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to the website', 'exprime-team-access' ); ?></a></p>

	<?php else : ?>

		<p class="exprime-team-invitation-card__lead">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: holder name, 2: subscription name. */
					__( '<strong>%1$s</strong> invites you to share the premium access of their subscription <strong>%2$s</strong>.', 'exprime-team-access' ),
					esc_html( $inviter_name ),
					esc_html( $subscription_name )
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<p class="exprime-team-invitation-card__email">
			<?php
			/* translators: %s: masked email address. */
			echo esc_html( sprintf( __( 'Invitation sent to: %s', 'exprime-team-access' ), $masked_email ) );
			?>
		</p>

		<?php if ( 'ready' === $state ) : ?>

			<form method="post" class="woocommerce-form exprime-team-accept-form">
				<?php wp_nonce_field( 'exprime_team_accept', '_exprime_team_nonce' ); ?>
				<input type="hidden" name="exprime_team_invitation_action" value="accept">
				<button type="submit" class="woocommerce-button button alt"><?php esc_html_e( 'Accept the invitation', 'exprime-team-access' ); ?></button>
			</form>

		<?php elseif ( 'mismatch' === $state ) : ?>

			<div class="woocommerce-info" role="status"><?php echo esc_html( $message ); ?></div>
			<p><?php esc_html_e( 'Log out, then log in with the account that uses the invited email address, or create an account with it.', 'exprime-team-access' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'exprime-team-access' ); ?></a></p>

		<?php elseif ( 'logged_out' === $state ) : ?>

			<div class="exprime-team-invitation-columns u-columns col2-set">
				<div class="u-column1 col-1 exprime-team-invitation-column">
					<h2><?php esc_html_e( 'I already have an account', 'exprime-team-access' ); ?></h2>
					<p><?php esc_html_e( 'Log in with the account that uses the invited email address.', 'exprime-team-access' ); ?></p>
					<?php
					woocommerce_login_form(
						array(
							'redirect' => $page_url,
							'hidden'   => false,
						)
					);
					?>
				</div>

				<div class="u-column2 col-2 exprime-team-invitation-column">
					<h2><?php esc_html_e( 'I am creating my account', 'exprime-team-access' ); ?></h2>
					<form method="post" class="woocommerce-form woocommerce-form-register register exprime-team-register-form">
						<?php wp_nonce_field( 'exprime_team_register', '_exprime_team_nonce' ); ?>
						<input type="hidden" name="exprime_team_invitation_action" value="register">

						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="exprime_team_email"><?php esc_html_e( 'Email address', 'exprime-team-access' ); ?></label>
							<input type="email" class="woocommerce-Input input-text" id="exprime_team_email" value="<?php echo esc_attr( $email ); ?>" readonly aria-readonly="true">
						</p>
						<p class="woocommerce-form-row form-row form-row-first">
							<label for="exprime_team_first_name"><?php esc_html_e( 'First name', 'exprime-team-access' ); ?></label>
							<input type="text" class="woocommerce-Input input-text" name="exprime_team_first_name" id="exprime_team_first_name" autocomplete="given-name">
						</p>
						<p class="woocommerce-form-row form-row form-row-last">
							<label for="exprime_team_last_name"><?php esc_html_e( 'Last name', 'exprime-team-access' ); ?></label>
							<input type="text" class="woocommerce-Input input-text" name="exprime_team_last_name" id="exprime_team_last_name" autocomplete="family-name">
						</p>
						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="exprime_team_password"><?php esc_html_e( 'Password', 'exprime-team-access' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
							<input type="password" class="woocommerce-Input input-text" name="exprime_team_password" id="exprime_team_password" autocomplete="new-password" required minlength="<?php echo (int) $min_password; ?>">
							<small>
								<?php
								/* translators: %d: minimum number of characters. */
								echo esc_html( sprintf( __( 'At least %d characters.', 'exprime-team-access' ), (int) $min_password ) );
								?>
							</small>
						</p>
						<p class="woocommerce-form-row form-row form-row-wide">
							<label for="exprime_team_password_confirm"><?php esc_html_e( 'Confirm password', 'exprime-team-access' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
							<input type="password" class="woocommerce-Input input-text" name="exprime_team_password_confirm" id="exprime_team_password_confirm" autocomplete="new-password" required minlength="<?php echo (int) $min_password; ?>">
						</p>
						<?php
						if ( function_exists( 'wc_registration_privacy_policy_text' ) ) {
							wc_registration_privacy_policy_text();
						}
						?>
						<p class="woocommerce-form-row form-row">
							<button type="submit" class="woocommerce-Button woocommerce-button button"><?php esc_html_e( 'Create my account and accept', 'exprime-team-access' ); ?></button>
						</p>
					</form>
				</div>
			</div>

		<?php endif; ?>

	<?php endif; ?>
</div>
