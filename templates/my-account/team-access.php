<?php
/**
 * Mon compte > Mes invités.
 *
 * Surcharge : votre-theme/wc-subscription-team-access/my-account/team-access.php
 *
 * @package WCSubscriptionTeamAccess
 *
 * @var array<int, array{subscription: WC_Subscription, name: string, status: string, summary: array, nonce: string}> $blocks
 * @var string $action_url URL de l'endpoint.
 */

use WCSubscriptionTeamAccess\Labels;
use WCSubscriptionTeamAccess\Support\Dates;

defined( 'ABSPATH' ) || exit;

if ( ! $blocks ) : ?>
	<div class="woocommerce-info"><?php esc_html_e( 'None of your subscriptions allows inviting guests.', 'wc-subscription-team-access' ); ?></div>
	<?php
	return;
endif;
?>
<div class="wcsta-team">
	<p class="wcsta-team__intro"><?php esc_html_e( 'Invite people to share the premium access of your subscription. Each guest uses their own account. You can remove or replace a guest at any time.', 'wc-subscription-team-access' ); ?></p>

	<?php foreach ( $blocks as $block ) : ?>
		<?php
		$subscription = $block['subscription'];
		$summary      = $block['summary'];
		$sub_id       = (int) $subscription->get_id();
		$percent      = $summary['quota'] > 0 ? min( 100, (int) round( 100 * $summary['used'] / $summary['quota'] ) ) : 100;
		$hidden       = static function ( string $action, int $seat_id = 0 ) use ( $block, $sub_id ): void {
			echo '<input type="hidden" name="_wcsta_nonce" value="' . esc_attr( $block['nonce'] ) . '">';
			echo '<input type="hidden" name="wcsta_action" value="' . esc_attr( $action ) . '">';
			echo '<input type="hidden" name="subscription_id" value="' . esc_attr( (string) $sub_id ) . '">';
			if ( $seat_id > 0 ) {
				echo '<input type="hidden" name="seat_id" value="' . esc_attr( (string) $seat_id ) . '">';
			}
		};
		?>
		<section class="wcsta-subscription" id="wcsta-subscription-<?php echo esc_attr( (string) $sub_id ); ?>" aria-labelledby="wcsta-title-<?php echo esc_attr( (string) $sub_id ); ?>">
			<header class="wcsta-subscription__header">
				<h3 class="wcsta-subscription__title" id="wcsta-title-<?php echo esc_attr( (string) $sub_id ); ?>"><?php echo esc_html( $block['name'] ); ?></h3>
				<p class="wcsta-subscription__meta">
					<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>">
						<?php
						/* translators: %s: subscription number. */
						echo esc_html( sprintf( __( 'Subscription #%s', 'wc-subscription-team-access' ), $subscription->get_order_number() ) );
						?>
					</a>
					<span class="wcsta-subscription__status subscription-status status-<?php echo esc_attr( $subscription->get_status() ); ?>"><?php echo esc_html( $block['status'] ); ?></span>
				</p>
			</header>

			<dl class="wcsta-counters">
				<div class="wcsta-counters__item">
					<dt><?php esc_html_e( 'Maximum guests', 'wc-subscription-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['quota'] ); ?></dd>
				</div>
				<div class="wcsta-counters__item">
					<dt><?php esc_html_e( 'Seats used', 'wc-subscription-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['used'] ); ?></dd>
				</div>
				<div class="wcsta-counters__item">
					<dt><?php esc_html_e( 'Seats available', 'wc-subscription-team-access' ); ?></dt>
					<dd><?php echo esc_html( (string) $summary['available'] ); ?></dd>
				</div>
			</dl>
			<div class="wcsta-meter<?php echo $summary['over'] > 0 ? ' wcsta-meter--over' : ''; ?>" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span></div>

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
								'wc-subscription-team-access'
							),
							$summary['over']
						)
					);
					?>
				</div>
			<?php elseif ( ! $summary['invitable'] ) : ?>
				<div class="woocommerce-info" role="status"><?php esc_html_e( 'Your subscription is not active: guests cannot access premium content and new invitations are not possible.', 'wc-subscription-team-access' ); ?></div>
			<?php endif; ?>

			<?php if ( $summary['seats'] ) : ?>
				<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders wcsta-seats">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Guest', 'wc-subscription-team-access' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wc-subscription-team-access' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'wc-subscription-team-access' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wc-subscription-team-access' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $summary['seats'] as $seat ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Guest', 'wc-subscription-team-access' ); ?>" class="wcsta-seats__email"><?php echo esc_html( $seat->email ); ?></td>
							<td data-title="<?php esc_attr_e( 'Status', 'wc-subscription-team-access' ); ?>">
								<span class="wcsta-status wcsta-status--<?php echo esc_attr( Labels::display_status( $seat ) ); ?>"><?php echo esc_html( Labels::status( $seat ) ); ?></span>
								<?php $reason = Labels::reason( $seat ); ?>
								<?php if ( '' !== $reason ) : ?>
									<small class="wcsta-status__reason"><?php echo esc_html( $reason ); ?></small>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'wc-subscription-team-access' ); ?>">
								<?php
								if ( $seat->is_accepted() ) {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Joined on %s', 'wc-subscription-team-access' ), Dates::display( $seat->accepted_at ) ) );
								} else {
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Invited on %s', 'wc-subscription-team-access' ), Dates::display( $seat->last_sent_at ?? $seat->created_at ) ) );
									echo '<br><small>';
									/* translators: %s: date. */
									echo esc_html( sprintf( __( 'Expires on %s', 'wc-subscription-team-access' ), Dates::display( $seat->token_expires_at ) ) );
									echo '</small>';
								}
								?>
							</td>
							<td class="wcsta-seats__actions" data-title="<?php esc_attr_e( 'Actions', 'wc-subscription-team-access' ); ?>">
								<?php if ( $seat->is_pending() ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wcsta-inline-form">
										<?php $hidden( 'resend', $seat->id ); ?>
										<button type="submit" class="woocommerce-button button wcsta-button"><?php esc_html_e( 'Resend', 'wc-subscription-team-access' ); ?></button>
									</form>
								<?php endif; ?>

								<details class="wcsta-replace">
									<summary class="woocommerce-button button wcsta-button"><?php esc_html_e( 'Replace', 'wc-subscription-team-access' ); ?></summary>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wcsta-replace__form">
										<?php $hidden( 'replace', $seat->id ); ?>
										<label for="wcsta-replace-<?php echo esc_attr( (string) $seat->id ); ?>"><?php esc_html_e( 'New guest email address', 'wc-subscription-team-access' ); ?></label>
										<input type="email" name="email" id="wcsta-replace-<?php echo esc_attr( (string) $seat->id ); ?>" class="input-text" required autocomplete="off">
										<button type="submit" class="woocommerce-button button wcsta-button"><?php esc_html_e( 'Replace and invite', 'wc-subscription-team-access' ); ?></button>
									</form>
								</details>

								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wcsta-inline-form" data-wcsta-confirm="<?php echo esc_attr( $seat->is_pending() ? __( 'Cancel this invitation?', 'wc-subscription-team-access' ) : __( 'Remove this guest? They will immediately lose the access shared by your subscription.', 'wc-subscription-team-access' ) ); ?>">
									<?php $hidden( 'remove', $seat->id ); ?>
									<button type="submit" class="woocommerce-button button wcsta-button wcsta-button--danger">
										<?php echo esc_html( $seat->is_pending() ? __( 'Cancel', 'wc-subscription-team-access' ) : __( 'Remove', 'wc-subscription-team-access' ) ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="wcsta-empty"><?php esc_html_e( 'You have not invited anyone yet.', 'wc-subscription-team-access' ); ?></p>
			<?php endif; ?>

			<?php if ( $summary['can_invite'] ) : ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="woocommerce-form wcsta-invite-form">
					<?php $hidden( 'invite' ); ?>
					<h4 class="wcsta-invite-form__title"><?php esc_html_e( 'Invite a user', 'wc-subscription-team-access' ); ?></h4>
					<p class="woocommerce-form-row form-row">
						<label for="wcsta-invite-<?php echo esc_attr( (string) $sub_id ); ?>"><?php esc_html_e( 'Email address of the person to invite', 'wc-subscription-team-access' ); ?></label>
						<span class="wcsta-invite-form__row">
							<input type="email" name="email" id="wcsta-invite-<?php echo esc_attr( (string) $sub_id ); ?>" class="woocommerce-Input input-text" required autocomplete="off" maxlength="100">
							<button type="submit" class="woocommerce-button button alt"><?php esc_html_e( 'Invite a user', 'wc-subscription-team-access' ); ?></button>
						</span>
					</p>
					<p class="wcsta-invite-form__help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of days. */
								_n( 'The invitation link is valid for %d day. A pending invitation reserves a seat.', 'The invitation link is valid for %d days. A pending invitation reserves a seat.', (int) \WCSubscriptionTeamAccess\Settings::get( 'invitation_expiry_days' ), 'wc-subscription-team-access' ),
								(int) \WCSubscriptionTeamAccess\Settings::get( 'invitation_expiry_days' )
							)
						);
						?>
					</p>
				</form>
			<?php elseif ( $summary['invitable'] && 0 === $summary['over'] ) : ?>
				<p class="wcsta-full"><?php esc_html_e( 'All the seats of your subscription are used. Remove or replace a guest to invite someone else.', 'wc-subscription-team-access' ); ?></p>
			<?php endif; ?>

			<?php if ( $summary['history'] ) : ?>
				<details class="wcsta-history">
					<summary><?php esc_html_e( 'Previous guests and invitations', 'wc-subscription-team-access' ); ?></summary>
					<ul>
						<?php foreach ( $summary['history'] as $seat ) : ?>
							<li>
								<?php echo esc_html( $seat->email ); ?> — <span class="wcsta-status wcsta-status--<?php echo esc_attr( Labels::display_status( $seat ) ); ?>"><?php echo esc_html( Labels::status( $seat ) ); ?></span>
								<small><?php echo esc_html( Dates::display( $seat->ended_at ) ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
