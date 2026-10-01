<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/**
 * Punto UNICO in cui il plugin decide se una funzione è disponibile.
 *
 * Oggi è in "standby": la chiave di licenza si salva ma non viene verificata e ogni funzione è consentita.
 * Quando servirà (autorizzazione dei domini, piani, funzioni avanzate) si cambia solo {@see License::allows()}
 * e il resto del plugin, che chiama già `License::allows( 'nome_funzione' )`, non si tocca.
 */
final class License {

	/** Funzioni che in futuro potranno dipendere dal piano. */
	const FEATURES = array(
		'online_payments',   // pagamenti online (WooCommerce)
		'member_area',       // area riservata soci/volontari
		'official_notices',  // avvisi ufficiali / notifiche push agli iscritti
		'pwa',               // app installabile
	);

	public static function key(): string {
		return (string) Settings::get( 'license_key' );
	}

	public static function allows( string $feature ): bool {
		unset( $feature ); // standby: nessuna restrizione
		return true;
	}

	/** Stato mostrabile nelle impostazioni. */
	public static function status(): array {
		$key = self::key();
		return array(
			'state'  => '' === $key ? 'none' : 'unchecked',
			'domain' => self::domain( function_exists( 'home_url' ) ? home_url() : '' ),
			'note'   => 'Verifica della licenza e del dominio non ancora attiva: tutte le funzioni sono disponibili.',
		);
	}

	/** Dominio "canonico" da autorizzare: senza schema, percorso e "www." — "https://www.Esempio.it/x" => "esempio.it". */
	public static function domain( string $url ): string {
		$host = parse_url( trim( $url ), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = preg_replace( '#^https?://#i', '', trim( $url ) );
			$host = explode( '/', (string) $host )[0];
		}
		$host = strtolower( (string) $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
