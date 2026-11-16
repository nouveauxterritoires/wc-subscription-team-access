<?php
/**
 * My Account > My guests.
 *
 * Override: your-theme/exprime-team-access/my-account/team-access.php
 *
 * @package ExprimeTeamAccess
 *
 * @var array<int, array{subscription: WC_Subscription, name: string, status: string, summary: array, nonce: string}> $blocks
 * @var string $action_url Endpoint URL.
 */

use Exprime\TeamAccess\Labels;
use Exprime\TeamAccess\Support\Dates;

defined( 'ABSPATH' ) || exit;

if ( ! $blocks ) : ?>
	<div class="woocommerce-info"><?php esc_html_e( 'None of your subscriptions allows inviting guests.', 'exprime-team-access' ); ?></div>
	<?php
	return;
endif;
?>
<div class="exprime-team-team">
	<p class="exprime-team-team__intro"><?php esc_html_e( 'Invite people to share the premium access of your subscription. Each guest uses their own account. You can remove or replace a guest at any time.', 'exprime-team-access' ); ?></p>

	<?php foreach ( $blocks as $block ) : ?>
		<?php
		$subscription = $block['subscription'];
		$summary      = $block['summary'];
		$sub_id       = (int) $subscription->get_id();
		$percent      = $summary['quota'] > 0 ? min( 100, (int) round( 100 * $summary['used'] / $summary['quota'] ) ) : 100;
		$hidden       = static function ( string $action, int $seat_id = 0 ) use ( $block, $sub_id ): void {
			echo '<input type="hidden" name="_exprime_team_nonce" value="' . esc_attr( $block['nonce'] ) . '">';
			echo '<input type="hidden" name="exprime_team_action" value="' . esc_attr( $action ) . '">';
			echo '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $sub_id ) . '">';
			if ( $seat_id > 0 ) {
				echo '<input type="hidden" name="seat_id" value="' . esc_attr( (string) $seat_id ) . '">';
			}
		};
		?>
		<section class="exprime-team-subscription" id="exprime-team-subscription-<?php echo esc_attr( (string) $sub_id ); ?>" aria-labelledby="exprime-team-title-<?php echo esc_attr( (string) $sub_id ); ?>">
			<header class="exprime-team-subscription__header">
				<h3 class="exprime-team-subscription__title" id="exprime-team-title-<?php echo esc_attr( (string) $sub_id ); ?>"><?php echo esc_html( $block['name'] ); ?></h3>
				<p class="exprime-team-subscription__meta">
					<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>">
						<?php
						/* translators: %s: subscription number. */
						echo esc_html( sprintf( __( 'Subscription #%s', 'exprime-team-access' ), $subscription->get_order_number() ) );
						?>
					</a>
					<span class="exprime-team-subscription__status subscription-status status-<?php echo esc_attr( $subscription->get_status() ); ?>"><?php echo esc_html( $block['status'] ); ?></span>
				</p>
			</header>

			<dl class="exprime-team-counters">
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Maximum guests', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['quota'] ); ?></dd>
				</div>
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Seats used', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['used'] ); ?></dd>
				</div>
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Seats available', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['available'] ); ?></dd>
				</div>
			</dl>
			<div class="exprime-team-meter<?php echo $summary['over'] > 0 ? ' exprime-team-meter--over' : ''; ?>" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span></div>

			<?php if ( $summary['over'] > 0 ) : ?>
				<div class="woocommerce-error" role="alert">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of guests over quota. */
							_n(
								'Your subscription now allows fewer guests: %d guest is over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.',
								'Your subscription now allows fewer guests: %d guests are over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.',
								$summary['over'],
								'exprime-team-access'
							),
							$summary['over']
						)
					);
					?>
				</div>
			<?php elseif ( ! $summary['invitable'] ) : ?>
				<div class="woocommerce-info" role="status"><?php esc_html_e( 'Your subscription is not active: guests cannot access premium content and new invitations are not possible.', 'exprime-team-access' ); ?></div>
			<?php endif; ?>

			<?php if ( $summary['seats'] ) : ?>
				<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders exprime-team-seats">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Guest', 'exprime-team-access' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'exprime-team-access' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'exprime-team-access' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'exprime-team-access' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $summary['seats'] as $seat ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Guest', 'exprime-team-access' ); ?>" class="exprime-team-seats__email"><?php echo esc_html( $seat->email ); ?></td>
							<td data-title="<?php esc_attr_e( 'Status', 'exprime-team-access' ); ?>">
								<span class="exprime-team-status exprime-team-status--<?php echo esc_attr( Labels::display_status( $seat ) ); ?>"><?php echo esc_html( Labels::status( $seat ) ); ?></span>
								<?php $reason = Labels::reason( $seat ); ?>
								<?php if ( '' !== $reason ) : ?>
									<small class="exprime-team-status__reason"><?php echo esc_html( $reason ); ?></small>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'exprime-team-access' ); ?>">
								<?php
								if ( $seat->is_accepted() ) {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Joined on %s', 'exprime-team-access' ), Dates::display( $seat->accepted_at ) ) );
								} else {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Invited on %s', 'exprime-team-access' ), Dates::display( $seat->last_sent_at ?? $seat->created_at ) ) );
									echo '<br><small>';
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Expires on %s', 'exprime-team-access' ), Dates::display( $seat->token_expires_at ) ) );
									echo '</small>';
								}
								?>
							</td>
							<td class="exprime-team-seats__actions" data-title="<?php esc_attr_e( 'Actions', 'exprime-team-access' ); ?>">
								<?php if ( $seat->is_pending() ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-inline-form">
										<?php $hidden( 'resend', $seat->id ); ?>
										<button type="submit" class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Resend', 'exprime-team-access' ); ?></button>
									</form>
								<?php endif; ?>

								<details class="exprime-team-replace">
									<summary class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Replace', 'exprime-team-access' ); ?></summary>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-replace__form">
										<?php $hidden( 'replace', $seat->id ); ?>
										<label for="exprime-team-replace-<?php echo esc_attr( (string) $seat->id ); ?>"><?php esc_html_e( 'New guest email address', 'exprime-team-access' ); ?></label>
										<input type="email" name="email" id="exprime-team-replace-<?php echo esc_attr( (string) $seat->id ); ?>" class="input-text" required autocomplete="off">
										<button type="submit" class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Replace and invite', 'exprime-team-access' ); ?></button>
									</form>
								</details>

								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-inline-form" data-exprime-team-confirm="<?php echo esc_attr( $seat->is_pending() ? __( 'Cancel this invitation?', 'exprime-team-access' ) : __( 'Remove this guest? They will immediately lose the access shared by your subscription.', 'exprime-team-access' ) ); ?>">
									<?php $hidden( 'remove', $seat->id ); ?>
									<button type="submit" class="woocommerce-button button exprime-team-button exprime-team-button--danger">
										<?php echo esc_html( $seat->is_pending() ? __( 'Cancel', 'exprime-team-access' ) : __( 'Remove', 'exprime-team-access' ) ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="exprime-team-empty"><?php esc_html_e( 'You have not invited anyone yet.', 'exprime-team-access' ); ?></p>
			<?php endif; ?>

			<?php if ( $summary['can_invite'] ) : ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="woocommerce-form exprime-team-invite-form">
					<?php $hidden( 'invite' ); ?>
					<h4 class="exprime-team-invite-form__title"><?php esc_html_e( 'Invite a user', 'exprime-team-access' ); ?></h4>
					<p class="woocommerce-form-row form-row">
						<label for="exprime-team-invite-<?php echo esc_attr( (string) $sub_id ); ?>"><?php esc_html_e( 'Email address of the person to invite', 'exprime-team-access' ); ?></label>
						<span class="exprime-team-invite-form__row">
							<input type="email" name="email" id="exprime-team-invite-<?php echo esc_attr( (string) $sub_id ); ?>" class="woocommerce-Input input-text" required autocomplete="off" maxlength="100">
							<button type="submit" class="woocommerce-button button alt"><?php esc_html_e( 'Invite a user', 'exprime-team-access' ); ?></button>
						</span>
					</p>
					<p class="exprime-team-invite-form__help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of days. */
								_n( 'The invitation link is valid for %d day. A pending invitation reserves a seat.', 'The invitation link is valid for %d days. A pending invitation reserves a seat.', (int) \Exprime\TeamAccess\Settings::get( 'invitation_expiry_days' ), 'exprime-team-access' ),
								(int) \Exprime\TeamAccess\Settings::get( 'invitation_expiry_days' )
							)
						);
						?>
					</p>
				</form>
			<?php elseif ( $summary['invitable'] && 0 === $summary['over'] ) : ?>
				<p class="exprime-team-full"><?php esc_html_e( 'All the seats of your subscription are used. Remove or replace a guest to invite someone else.', 'exprime-team-access' ); ?></p>
			<?php endif; ?>

			<?php if ( $summary['history'] ) : ?>
				<details class="exprime-team-history">
					<summary><?php esc_html_e( 'Previous guests and invitations', 'exprime-team-access' ); ?></summary>
					<ul>
						<?php foreach ( $summary['history'] as $seat ) : ?>
							<li>
								<?php echo esc_html( $seat->email ); ?> — <span class="exprime-team-status exprime-team-status--<?php echo esc_attr( Labels::display_status( $seat ) ); ?>"><?php echo esc_html( Labels::status( $seat ) ); ?></span>
								<small><?php echo esc_html( Dates::display( $seat->ended_at ) ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
