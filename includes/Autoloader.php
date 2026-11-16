<?php
/**
 * Chargeur de classes PSR-4 minimal (aucune dépendance Composer en production).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

/**
 * Charge les classes de l'espace de noms WCSubscriptionTeamAccess depuis includes/.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Enregistre le chargeur.
	 *
	 * @param string $base_dir Répertoire includes/ avec barre oblique finale.
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
