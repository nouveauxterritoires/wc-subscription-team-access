<?php
/**
 * Plugin settings (a single serialized option).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\SeatAllocator;

/**
 * Typed access to settings, with default values and bounds.
 */
final class Settings {

	public const OPTION = 'wcsta_settings';

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'invitation_expiry_days'     => 7,
			'overage_policy'             => SeatAllocator::POLICY_SUSPEND_NEWEST,
			'max_invites_per_hour'       => 20,
			'resend_min_interval'        => 5,
			'max_resends'                => 5,
			'suppress_membership_emails' => 'yes',
			'notify_owner_on_accept'     => 'yes',
			'anonymize_after_days'       => 90,
			'invitation_slug'            => 'invitation-equipe',
			'account_endpoint'           => 'mes-invites',
		);
	}

	/**
	 * All values, normalized.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * A single value.
	 *
	 * @param string $key Key.
	 */
	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Invitation validity period, in seconds. */
	public static function expiry_seconds(): int {
		return (int) self::get( 'invitation_expiry_days' ) * DAY_IN_SECONDS;
	}

	/**
	 * Validates and bounds the values (used on save and on read).
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$out      = array();

		$out['invitation_expiry_days'] = self::bounded( $input['invitation_expiry_days'] ?? null, 1, 60, $defaults['invitation_expiry_days'] );
		$out['max_invites_per_hour']   = self::bounded( $input['max_invites_per_hour'] ?? null, 1, 500, $defaults['max_invites_per_hour'] );
		$out['resend_min_interval']    = self::bounded( $input['resend_min_interval'] ?? null, 0, 1440, $defaults['resend_min_interval'] );
		$out['max_resends']            = self::bounded( $input['max_resends'] ?? null, 0, 50, $defaults['max_resends'] );
		$out['anonymize_after_days']   = self::bounded( $input['anonymize_after_days'] ?? null, 0, 3650, $defaults['anonymize_after_days'] );

		$policy                = (string) ( $input['overage_policy'] ?? $defaults['overage_policy'] );
		$out['overage_policy'] = in_array( $policy, array( SeatAllocator::POLICY_SUSPEND_NEWEST, SeatAllocator::POLICY_KEEP_ALL ), true )
			? $policy
			: $defaults['overage_policy'];

		foreach ( array( 'suppress_membership_emails', 'notify_owner_on_accept' ) as $flag ) {
			$value        = $input[ $flag ] ?? $defaults[ $flag ];
			$out[ $flag ] = ( 'yes' === $value || true === $value || '1' === $value ) ? 'yes' : 'no';
		}

		foreach ( array( 'invitation_slug', 'account_endpoint' ) as $slug_key ) {
			$slug             = sanitize_title( (string) ( $input[ $slug_key ] ?? '' ) );
			$out[ $slug_key ] = '' !== $slug ? $slug : $defaults[ $slug_key ];
		}

		return $out;
	}

	/**
	 * Bounded integer.
	 *
	 * @param mixed $value   Value.
	 * @param int   $min     Minimum.
	 * @param int   $max     Maximum.
	 * @param int   $default Default if invalid.
	 */
	private static function bounded( mixed $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return min( $max, max( $min, (int) $value ) );
	}
}
