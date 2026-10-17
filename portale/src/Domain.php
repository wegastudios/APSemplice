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

	/** Suffissi pubblici a più etichette più comuni (come nel plugin). */
	const MULTI_LABEL_SUFFIXES = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'com.br', 'com.mx',
		'com.ar', 'co.za', 'com.tr', 'com.cn', 'co.in', 'com.pl', 'com.es', 'org.es', 'com.pt',
	);

	/** Dominio registrabile: «staging.esempio.it» → «esempio.it», «www.esempio.co.uk» → «esempio.co.uk». La licenza vale per lui e per tutti i suoi sottodomini. */
	public static function registrable( string $normalized ): string {
		if ( '' === $normalized ) {
			return '';
		}
		$labels = explode( '.', $normalized );
		if ( count( $labels ) <= 2 ) {
			return $normalized;
		}
		$take = in_array( implode( '.', array_slice( $labels, -2 ) ), self::MULTI_LABEL_SUFFIXES, true ) ? 3 : 2;
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/** Dominio a cui si lega una licenza partendo da quello che scrive il cliente (valido, oppure stringa vuota). */
	public static function forLicense( string $input ): string {
		return self::registrable( self::normalize( $input ) );
	}

	/** Almeno due etichette alfanumeriche (con trattino interno); i suffissi pubblici li rifiuta il server. */
	private static function ascii_ok( string $d ): bool {
		return strlen( $d ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $d );
	}
}
