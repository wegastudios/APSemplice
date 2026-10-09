<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Pagine del sito che servono al plugin (area soci, volontari, attività…): si creano con gli shortcode già inseriti. */
final class Pages {

	const OPTION = 'apse_pages';

	/** Area di ogni pagina (vedi Areas). */
	const AREA = array(
		'area' => 'soci', 'calendario' => 'soci', 'volontari' => 'eventi', 'ingressi' => 'eventi', 'attivita' => 'pubblico',
		'bonifico' => 'pubblico', 'privacy' => 'pubblico', 'cinquemille' => 'pubblico', 'segreteria' => 'segreteria', 'tesoriere' => 'tesoriere',
	);

	/** @return array<string,array> area => [chiave pagina => definizione], nell'ordine delle aree */
	public static function by_area(): array {
		$out = array();
		foreach ( array_keys( Areas::GROUPS ) as $area ) {
			foreach ( self::defs() as $key => $d ) {
				if ( ( self::AREA[ $key ] ?? '' ) === $area ) {
					$out[ $area ][ $key ] = $d;
				}
			}
		}
		return $out;
	}

	/** Chiave => titolo, shortcode, accesso riservato (meta della pagina), descrizione, predefinita. */
	public static function defs(): array {
		return array(
			'area'      => array( 'title' => 'Area soci', 'content' => '[apsemplice_area_soci]', 'access' => '', 'hint' => 'Tessera, attività, pagamenti, ospiti e profilo dei soci: è la pagina principale.', 'default' => true ),
			'volontari' => array( 'title' => 'Area volontari', 'content' => '[apsemplice_area_volontari]', 'access' => 'volunteers', 'hint' => 'Le attività di cui i volontari sono referenti (visibile solo a loro).', 'default' => true ),
			'attivita'  => array( 'title' => 'Attività ed eventi', 'content' => '[apsemplice_attivita]', 'access' => '', 'hint' => 'L\'elenco pubblico di corsi ed eventi, con la prenotazione.', 'default' => true ),
			'calendario' => array( 'title' => 'Calendario', 'content' => '[apsemplice_calendario]', 'access' => 'members', 'hint' => 'Il calendario di corsi ed eventi per i soli soci.', 'default' => false ),
			'privacy'   => array( 'title' => 'Informativa privacy', 'content' => '[apsemplice_privacy]', 'access' => '', 'hint' => 'L\'informativa sul trattamento dei dati personali, compilata con i dati dell\'ente (si aggiorna da sola).', 'default' => true ),
			'bonifico'  => array( 'title' => 'Dona con bonifico', 'content' => '[apsemplice_bonifico]', 'access' => '', 'hint' => 'Le coordinate bancarie per chi vuole sostenere l\'associazione (servono il bonifico attivo e almeno un IBAN).', 'default' => false ),
			'segreteria' => array( 'title' => 'Area segreteria', 'content' => '[apsemplice_segreteria]', 'access' => '', 'hint' => 'Il punto d\'ingresso di chi lavora con la segreteria (anche presidente e vicepresidente): richieste di accesso e collegamenti alla gestione.', 'default' => false ),
			'tesoriere' => array( 'title' => 'Area tesoriere', 'content' => '[apsemplice_tesoriere]', 'access' => '', 'hint' => 'Per il tesoriere: incassi, spese con foto dello scontrino, nuove iscrizioni e vendita degli eventi.', 'default' => false ),
			'ingressi'  => array( 'title' => 'Ingressi agli eventi', 'content' => '[apsemplice_ingressi]', 'access' => '', 'hint' => 'Per responsabili e staff: prenotati, QR e registrazione degli ingressi.', 'default' => false ),
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
		if ( ! empty( $have['privacy'] ) && '' === (string) Settings::get( 'privacy_url' ) ) {
			Settings::update( array( 'privacy_url' => (string) get_permalink( (int) $have['privacy'] ) ) ); // l'informativa creata diventa quella richiesta a chi attiva l'accesso
		}
		if ( in_array( 'Area soci', $made, true ) ) {
			self::add_to_menu( (int) $have['area'] ); // la voce «Area riservata» compare nel menu del sito
		}
		return $made;
	}

	/**
	 * Aggiunge al menu di navigazione del sito la voce «Area riservata» che porta all'area soci (con le funzioni nella versione per il sito,
	 * non quella di amministrazione). Sceglie il menu della posizione principale; non duplica la voce.
	 *
	 * @return bool true se la voce c'è (già presente o aggiunta), false se il sito non ha un menu a cui aggiungerla
	 */
	public static function add_to_menu( int $page_id ): bool {
		if ( $page_id <= 0 || ! function_exists( 'wp_update_nav_menu_item' ) ) {
			return false;
		}
		$menu_id = 0;
		$locs    = (array) get_nav_menu_locations();
		foreach ( array( 'primary', 'main', 'menu-1', 'header', 'main-menu' ) as $loc ) {
			if ( ! empty( $locs[ $loc ] ) ) {
				$menu_id = (int) $locs[ $loc ];
				break;
			}
		}
		if ( ! $menu_id && $locs ) {
			$menu_id = (int) reset( $locs );
		}
		if ( ! $menu_id ) {
			$menus   = wp_get_nav_menus();
			$menu_id = $menus ? (int) $menus[0]->term_id : 0;
		}
		if ( ! $menu_id ) {
			return false;
		}
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( (int) $item->object_id === $page_id && 'page' === $item->object ) {
				return true;
			}
		}
		$id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Area riservata',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		return ! is_wp_error( $id );
	}
}
