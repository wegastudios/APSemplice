<?php
namespace Portale;

/** Mostra una pagina dentro il layout comune. */
final class View {

	public static function render( string $name, array $vars = array() ): void {
		extract( $vars, EXTR_SKIP );
		ob_start();
		require __DIR__ . '/../views/' . $name . '.php';
		$content = ob_get_clean();
		self::headers( 'text/html; charset=utf-8' );
		require __DIR__ . '/../views/layout.php';
	}

	public static function headers( string $type ): void {
		header( 'Content-Type: ' . $type );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: same-origin' );
		if ( ! Config::get( 'indexable', false ) ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet' );
		}
	}
}
