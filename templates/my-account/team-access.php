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
<div class="exprime-team">
	<p class="exprime-team__intro"><?php esc_html_e( 'Invite people to share the premium access of your subscription. Each guest uses their own account. You can remove or replace a guest at any time.', 'exprime-team-access' ); ?></p>

	<?php foreach ( $blocks as $exprime_team_block ) : ?>
		<?php
		$exprime_team_subscription = $exprime_team_block['subscription'];
		$exprime_team_summary      = $exprime_team_block['summary'];
		$exprime_team_sub_id       = (int) $exprime_team_subscription->get_id();
		$exprime_team_percent      = $exprime_team_summary['quota'] > 0 ? min( 100, (int) round( 100 * $exprime_team_summary['used'] / $exprime_team_summary['quota'] ) ) : 100;
		$exprime_team_hidden       = static function ( string $action, int $seat_id = 0 ) use ( $exprime_team_block, $exprime_team_sub_id ): void {
			echo '<input type="hidden" name="_exprime_team_nonce" value="' . esc_attr( $exprime_team_block['nonce'] ) . '">';
			echo '<input type="hidden" name="exprime_team_action" value="' . esc_attr( $action ) . '">';
			echo '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $exprime_team_sub_id ) . '">';
			if ( $seat_id > 0 ) {
				echo '<input type="hidden" name="seat_id" value="' . esc_attr( (string) $seat_id ) . '">';
			}
		};
		?>
		<section class="exprime-team-subscription" id="exprime-team-subscription-<?php echo esc_attr( (string) $exprime_team_sub_id ); ?>" aria-labelledby="exprime-team-title-<?php echo esc_attr( (string) $exprime_team_sub_id ); ?>">
			<header class="exprime-team-subscription__header">
				<h3 class="exprime-team-subscription__title" id="exprime-team-title-<?php echo esc_attr( (string) $exprime_team_sub_id ); ?>"><?php echo esc_html( $exprime_team_block['name'] ); ?></h3>
				<p class="exprime-team-subscription__meta">
					<a href="<?php echo esc_url( $exprime_team_subscription->get_view_order_url() ); ?>">
						<?php
						/* translators: %s: subscription number. */
						echo esc_html( sprintf( __( 'Subscription #%s', 'exprime-team-access' ), $exprime_team_subscription->get_order_number() ) );
						?>
					</a>
					<span class="exprime-team-subscription__status subscription-status status-<?php echo esc_attr( $exprime_team_subscription->get_status() ); ?>"><?php echo esc_html( $exprime_team_block['status'] ); ?></span>
				</p>
			</header>

			<dl class="exprime-team-counters">
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Maximum guests', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $exprime_team_summary['quota'] ); ?></dd>
				</div>
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Seats used', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $exprime_team_summary['used'] ); ?></dd>
				</div>
				<div class="exprime-team-counters__item">
					<dt><?php esc_html_e( 'Seats available', 'exprime-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $exprime_team_summary['available'] ); ?></dd>
				</div>
			</dl>
			<div class="exprime-team-meter<?php echo $exprime_team_summary['over'] > 0 ? ' exprime-team-meter--over' : ''; ?>" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $exprime_team_percent ); ?>%"></span></div>

			<?php if ( $exprime_team_summary['over'] > 0 ) : ?>
				<div class="woocommerce-error" role="alert">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of guests over quota. */
							_n(
								'Your subscription now allows fewer guests: %d guest is over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.',
								'Your subscription now allows fewer guests: %d guests are over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.',
								$exprime_team_summary['over'],
								'exprime-team-access'
							),
							$exprime_team_summary['over']
						)
					);
					?>
				</div>
			<?php elseif ( ! $exprime_team_summary['invitable'] ) : ?>
				<div class="woocommerce-info" role="status"><?php esc_html_e( 'Your subscription is not active: guests cannot access premium content and new invitations are not possible.', 'exprime-team-access' ); ?></div>
			<?php endif; ?>

			<?php if ( $exprime_team_summary['seats'] ) : ?>
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
					<?php foreach ( $exprime_team_summary['seats'] as $exprime_team_seat ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Guest', 'exprime-team-access' ); ?>" class="exprime-team-seats__email"><?php echo esc_html( $exprime_team_seat->email ); ?></td>
							<td data-title="<?php esc_attr_e( 'Status', 'exprime-team-access' ); ?>">
								<span class="exprime-team-status exprime-team-status--<?php echo esc_attr( Labels::display_status( $exprime_team_seat ) ); ?>"><?php echo esc_html( Labels::status( $exprime_team_seat ) ); ?></span>
								<?php $exprime_team_reason = Labels::reason( $exprime_team_seat ); ?>
								<?php if ( '' !== $exprime_team_reason ) : ?>
									<small class="exprime-team-status__reason"><?php echo esc_html( $exprime_team_reason ); ?></small>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'exprime-team-access' ); ?>">
								<?php
								if ( $exprime_team_seat->is_accepted() ) {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Joined on %s', 'exprime-team-access' ), Dates::display( $exprime_team_seat->accepted_at ) ) );
								} else {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Invited on %s', 'exprime-team-access' ), Dates::display( $exprime_team_seat->last_sent_at ?? $exprime_team_seat->created_at ) ) );
									echo '<br><small>';
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Expires on %s', 'exprime-team-access' ), Dates::display( $exprime_team_seat->token_expires_at ) ) );
									echo '</small>';
								}
								?>
							</td>
							<td class="exprime-team-seats__actions" data-title="<?php esc_attr_e( 'Actions', 'exprime-team-access' ); ?>">
								<?php if ( $exprime_team_seat->is_pending() ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-inline-form">
										<?php $exprime_team_hidden( 'resend', $exprime_team_seat->id ); ?>
										<button type="submit" class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Resend', 'exprime-team-access' ); ?></button>
									</form>
								<?php endif; ?>

								<details class="exprime-team-replace">
									<summary class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Replace', 'exprime-team-access' ); ?></summary>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-replace__form">
										<?php $exprime_team_hidden( 'replace', $exprime_team_seat->id ); ?>
										<label for="exprime-team-replace-<?php echo esc_attr( (string) $exprime_team_seat->id ); ?>"><?php esc_html_e( 'New guest email address', 'exprime-team-access' ); ?></label>
										<input type="email" name="email" id="exprime-team-replace-<?php echo esc_attr( (string) $exprime_team_seat->id ); ?>" class="input-text" required autocomplete="off">
										<button type="submit" class="woocommerce-button button exprime-team-button"><?php esc_html_e( 'Replace and invite', 'exprime-team-access' ); ?></button>
									</form>
								</details>

								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="exprime-team-inline-form" data-exprime-team-confirm="<?php echo esc_attr( $exprime_team_seat->is_pending() ? __( 'Cancel this invitation?', 'exprime-team-access' ) : __( 'Remove this guest? They will immediately lose the access shared by your subscription.', 'exprime-team-access' ) ); ?>">
									<?php $exprime_team_hidden( 'remove', $exprime_team_seat->id ); ?>
									<button type="submit" class="woocommerce-button button exprime-team-button exprime-team-button--danger">
										<?php echo esc_html( $exprime_team_seat->is_pending() ? __( 'Cancel', 'exprime-team-access' ) : __( 'Remove', 'exprime-team-access' ) ); ?>
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

			<?php if ( $exprime_team_summary['can_invite'] ) : ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="woocommerce-form exprime-team-invite-form">
					<?php $exprime_team_hidden( 'invite' ); ?>
					<h4 class="exprime-team-invite-form__title"><?php esc_html_e( 'Invite a user', 'exprime-team-access' ); ?></h4>
					<p class="woocommerce-form-row form-row">
						<label for="exprime-team-invite-<?php echo esc_attr( (string) $exprime_team_sub_id ); ?>"><?php esc_html_e( 'Email address of the person to invite', 'exprime-team-access' ); ?></label>
						<span class="exprime-team-invite-form__row">
							<input type="email" name="email" id="exprime-team-invite-<?php echo esc_attr( (string) $exprime_team_sub_id ); ?>" class="woocommerce-Input input-text" required autocomplete="off" maxlength="100">
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
			<?php elseif ( $exprime_team_summary['invitable'] && 0 === $exprime_team_summary['over'] ) : ?>
				<p class="exprime-team-full"><?php esc_html_e( 'All the seats of your subscription are used. Remove or replace a guest to invite someone else.', 'exprime-team-access' ); ?></p>
			<?php endif; ?>

			<?php if ( $exprime_team_summary['history'] ) : ?>
				<details class="exprime-team-history">
					<summary><?php esc_html_e( 'Previous guests and invitations', 'exprime-team-access' ); ?></summary>
					<ul>
						<?php foreach ( $exprime_team_summary['history'] as $exprime_team_seat ) : ?>
							<li>
								<?php echo esc_html( $exprime_team_seat->email ); ?> — <span class="exprime-team-status exprime-team-status--<?php echo esc_attr( Labels::display_status( $exprime_team_seat ) ); ?>"><?php echo esc_html( Labels::status( $exprime_team_seat ) ); ?></span>
								<small><?php echo esc_html( Dates::display( $exprime_team_seat->ended_at ) ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
