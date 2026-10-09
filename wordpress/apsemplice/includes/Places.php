<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ricerca dei luoghi con Google (Places): nei campi «Luogo» di corsi ed eventi compaiono i suggerimenti di Google mentre si scrive.
 * Spenta di default. Serve una chiave API di Google Maps dell'ente, limitata ai siti autorizzati (è una chiave per il browser: si vede nella
 * pagina, quindi va limitata dal pannello di Google). Il collegamento con Google avviene solo nelle pagine di amministrazione del plugin,
 * dal browser di chi sta compilando il campo; nessun dato personale dei soci viene inviato.
 */
final class Places {

	const URL = 'https://maps.googleapis.com/maps/api/js';

	/** Chiave valida: lettere, cifre, trattino e trattino basso (le chiavi di Google ne hanno circa 39). Altro => vuoto. */
	public static function clean_key( string $v ): string {
		$v = trim( $v );
		return preg_match( '/^[A-Za-z0-9_\-]{20,100}$/', $v ) ? $v : '';
	}

	public static function key(): string {
		return self::clean_key( (string) Settings::get( 'places_key' ) );
	}

	public static function enabled(): bool {
		return ! empty( Settings::get( 'places_enabled' ) ) && '' !== self::key();
	}

	/** Indirizzo dello script di Google, con la chiave e la funzione che lo collega ai campi (vuoto se la funzione è spenta). */
	public static function script_url(): string {
		if ( ! self::enabled() ) {
			return '';
		}
		return add_query_arg(
			array(
				'key'       => self::key(),
				'libraries' => 'places',
				'language'  => 'it',
				'callback'  => 'apsePlacesInit',
			),
			self::URL
		);
	}
}
