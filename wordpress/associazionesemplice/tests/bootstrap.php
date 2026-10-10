<?php
/**
 * Bootstrap dei test unitari: carica solo le classi "pure" (nessuna dipendenza da WordPress).
 * Le classi che usano il database sono verificate da tests/smoke.php dentro un WordPress reale.
 */
define( 'ASEM_TESTS', true );
define( 'ASEM_DIR', dirname( __DIR__ ) . '/' );
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // le classi hanno la guardia contro l'accesso diretto

spl_autoload_register(
	function ( $class ) {
		$prefix = 'AssociazioneSemplice\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( ! function_exists( 'wp_delete_file' ) ) {
	/** Sostituto minimo di quello di WordPress per i test senza WordPress. */
	function wp_delete_file( $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
