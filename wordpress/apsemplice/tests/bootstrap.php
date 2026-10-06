<?php
/**
 * Bootstrap dei test unitari: carica solo le classi "pure" (nessuna dipendenza da WordPress).
 * Le classi che usano il database sono verificate da tests/smoke.php dentro un WordPress reale.
 */
define( 'APSE_TESTS', true );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'ApSemplice\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
