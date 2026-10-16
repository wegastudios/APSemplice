<?php
namespace Portale;

/** Dominio nel formato richiesto dal server licenze: minuscolo, punycode, senza schema/porta/percorso/www, niente IP. */
final class Domain {

	/** @return string dominio normalizzato, '' se non valido */
	public static function normalize( string $input ): string {
		$d = strtolower( trim( $input ) );
		$d = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $d );
		$d = preg_replace( '#[/?\#].*$#', '', $d );
		$d = preg_replace( '#:\d+$#', '', $d );
		$d = rtrim( (string) $d, '.' );
		if ( 0 === strpos( $d, 'www.' ) ) {
			$d = substr( $d, 4 );
		}
		if ( '' === $d || false !== strpos( $d, '*' ) || filter_var( $d, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( preg_match( '/[^\x00-\x7f]/', $d ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return '';
			}
			$d = (string) idn_to_ascii( $d, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
		}
		return self::ascii_ok( $d ) ? $d : '';
	}

	/** Almeno due etichette alfanumeriche (con trattino interno); i suffissi pubblici li rifiuta il server. */
	private static function ascii_ok( string $d ): bool {
		return strlen( $d ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $d );
	}
}
