<?php
/**
 * Loads the plugin's pure classes (no WordPress needed).
 *
 * Signature tests need ext-sodium (bundled in the official php Docker images). Without it, set
 * SODIUM_COMPAT=/path/to/sodium_compat/autoload.php (WordPress ships the same library in
 * wp-includes/sodium_compat), otherwise those tests are skipped.
 */

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'IntentsCheckout\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$path = __DIR__ . '/../plugin/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $path ) ) {
				require $path;
			}
		}
	}
);

if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) && getenv( 'SODIUM_COMPAT' ) ) {
	require getenv( 'SODIUM_COMPAT' );
}
