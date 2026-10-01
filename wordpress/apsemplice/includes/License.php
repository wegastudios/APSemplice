<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/**
 * Punto UNICO in cui il plugin decide se una funzione è disponibile.
 *
 * Oggi è in "standby": la chiave di licenza si salva ma non viene verificata e ogni funzione è consentita.
 * Quando servirà si cambia solo {@see License::allows()}: il resto del plugin chiama già `License::allows( 'funzione' )`.
 *
 * Le regole (una licenza = un dominio, sottodomini compresi, al massimo 2 installazioni) sono in {@see LicenseRules},
 * già pronte e testate; manca solo il server che tiene l'elenco delle installazioni attive (vedi docs/LICENZE.md).
 */
final class License {

	const OPT_INSTALL_ID  = 'aps_install_id';
	const OPT_INSTALL_URL = 'aps_install_url';

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

	/**
	 * Identità di QUESTA installazione: un id casuale che resta finché il sito non cambia indirizzo.
	 * Se il sito viene copiato altrove (es. produzione -> staging) la copia porta con sé il database, quindi lo
	 * stesso id: lo si riconosce perché l'indirizzo non coincide più, e si genera un id nuovo. Così la copia
	 * è una installazione distinta e non "ruba" l'attivazione dell'originale.
	 *
	 * @return array ['id'=>string, 'url'=>string, 'domain'=>string (registrabile), 'moved'=>bool]
	 */
	public static function installation(): array {
		$url   = home_url();
		$host  = LicenseRules::host( $url );
		$id    = (string) get_option( self::OPT_INSTALL_ID, '' );
		$known = (string) get_option( self::OPT_INSTALL_URL, '' );
		$moved = false;
		if ( '' === $id || ( '' !== $known && LicenseRules::host( $known ) !== $host ) ) {
			$moved = '' !== $id;
			$id    = wp_generate_uuid4();
			update_option( self::OPT_INSTALL_ID, $id );
		}
		if ( $known !== $url ) {
			update_option( self::OPT_INSTALL_URL, $url );
		}
		return array( 'id' => $id, 'url' => $url, 'domain' => LicenseRules::registrable_domain( $url ), 'moved' => $moved );
	}

	/** Stato mostrabile nelle impostazioni. */
	public static function status(): array {
		$key  = self::key();
		$inst = self::installation();
		return array(
			'state'       => '' === $key ? 'none' : 'unchecked',
			'domain'      => $inst['domain'],
			'install_id'  => $inst['id'],
			'local'       => LicenseRules::is_local( $inst['url'] ),
			'max_installs' => LicenseRules::MAX_INSTALLS,
			'note'        => 'Verifica della licenza non ancora attiva: tutte le funzioni sono disponibili.',
		);
	}

	/** Dominio senza schema, percorso e "www." (per mostrarlo): "https://www.Esempio.it/x" => "esempio.it". */
	public static function domain( string $url ): string {
		$host = LicenseRules::host( $url );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
