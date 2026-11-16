<?php
/**
 * Minimal PSR-4 class autoloader (no Composer dependency in production).
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess;

/**
 * Loads classes from the Exprime\TeamAccess namespace out of includes/.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir The includes/ directory with a trailing slash.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $base_dir ): void {
				if ( 0 !== strncmp( self::PREFIX, $class, strlen( self::PREFIX ) ) ) {
					return;
				}
				$relative = substr( $class, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
