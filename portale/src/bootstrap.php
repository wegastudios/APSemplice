<?php
// Caricamento automatico: Portale\Classe => src/Classe.php (nessuna dipendenza esterna).
spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'Portale\\' ) ) {
		return;
	}
	$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, 8 ) ) . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

/** Escape per l'HTML delle pagine. */
function e( $s ): string {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
