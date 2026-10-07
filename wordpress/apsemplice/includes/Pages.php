<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Pagine del sito che servono al plugin (area soci, volontari, attività…): si creano con gli shortcode già inseriti. */
final class Pages {

	const OPTION = 'apse_pages';

	/** Chiave => titolo, shortcode, accesso riservato (meta della pagina), descrizione, predefinita. */
	public static function defs(): array {
		return array(
			'area'      => array( 'title' => 'Area soci', 'content' => '[apsemplice_area_soci]', 'access' => '', 'hint' => 'Tessera, attività, pagamenti, ospiti e profilo dei soci: è la pagina principale.', 'default' => true ),
			'volontari' => array( 'title' => 'Area volontari', 'content' => '[apsemplice_area_volontari]', 'access' => 'volunteers', 'hint' => 'Le attività che tengono i volontari (visibile solo a loro).', 'default' => true ),
			'attivita'  => array( 'title' => 'Attività ed eventi', 'content' => '[apsemplice_attivita]', 'access' => '', 'hint' => 'L\'elenco pubblico di corsi ed eventi, con la prenotazione.', 'default' => true ),
			'calendario' => array( 'title' => 'Calendario', 'content' => '[apsemplice_calendario]', 'access' => 'members', 'hint' => 'Il calendario di corsi ed eventi per i soli soci.', 'default' => false ),
			'bonifico'  => array( 'title' => 'Dona con bonifico', 'content' => '[apsemplice_bonifico]', 'access' => '', 'hint' => 'Le coordinate bancarie per chi vuole sostenere l\'associazione (servono il bonifico attivo e almeno un IBAN).', 'default' => false ),
			'cinquemille' => array( 'title' => '5x1000', 'content' => '[apsemplice_cinquepermille]', 'access' => '', 'hint' => 'Il messaggio con il codice fiscale per il 5x1000 (serve la funzione attiva).', 'default' => false ),
		);
	}

	/** @return array<string,int> pagine già create (chiave => id), solo quelle ancora esistenti */
	public static function existing(): array {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $k => $id ) {
			if ( (int) $id && get_post_status( (int) $id ) && 'trash' !== get_post_status( (int) $id ) ) {
				$out[ (string) $k ] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Crea le pagine richieste che non esistono già.
	 *
	 * @param string[] $keys chiavi di defs()
	 * @return string[] titoli delle pagine create
	 */
	public static function create( array $keys ): array {
		$defs  = self::defs();
		$saved = (array) get_option( self::OPTION, array() );
		$have  = self::existing();
		$made  = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $defs[ $key ] ) || isset( $have[ $key ] ) ) {
				continue;
			}
			$d  = $defs[ $key ];
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $d['title'], 'post_content' => $d['content'] ) );
			if ( $id && ! is_wp_error( $id ) ) {
				$saved[ $key ] = (int) $id;
				$made[]        = $d['title'];
				if ( '' !== $d['access'] ) {
					update_post_meta( $id, '_aps_access', $d['access'] );
				}
			}
		}
		update_option( self::OPTION, $saved );
		$have = self::existing();
		if ( ! empty( $have['area'] ) && 0 === (int) Settings::get( 'member_area_page_id' ) ) {
			Settings::update( array( 'member_area_page_id' => (int) $have['area'] ) );
		}
		return $made;
	}
}
