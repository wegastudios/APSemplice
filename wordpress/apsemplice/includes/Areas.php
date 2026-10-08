<?php
namespace ApSemplice;

use ApSemplice\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Le aree del sito: ogni vista (shortcode, blocco, widget) appartiene a un'area, così blocchi e widget si scelgono in ordine.
 *  - Soci: tessera, attività, pagamenti, ospiti, profilo;
 *  - Segreteria: il punto d'ingresso di chi lavora con la segreteria (anche presidente e vicepresidente);
 *  - Tesoriere: incassi, spese, iscrizioni e vendita eventi;
 *  - Eventi: gestione per il responsabile e lo staff (ingressi, avvisi, iscritti);
 *  - Pubblico: elenco attività, prossimi eventi, bonifico, donazioni, 5x1000, accesso.
 */
final class Areas {

	/** Area => [titolo, viste]. */
	const GROUPS = array(
		'soci'       => array( 'Soci', array( 'area_soci', 'tessera', 'mie_attivita', 'pagamenti', 'ricevute', 'regolamento', 'ospiti', 'profilo', 'avvisi', 'calendario', 'app' ) ),
		'segreteria' => array( 'Segreteria', array( 'segreteria' ) ),
		'tesoriere'  => array( 'Tesoriere', array( 'tesoriere' ) ),
		'eventi'     => array( 'Eventi', array( 'area_volontari', 'ingressi' ) ),
		'pubblico'   => array( 'Pubblico', array( 'attivita', 'prossimi_eventi', 'bonifico', 'donazioni', 'cinquepermille', 'accesso' ) ),
	);

	/** Viste che funzionano ma non si propongono più negli elenchi (nome vecchio di una vista). */
	const ALIASES = array( 'spese' => 'tesoriere' );

	public static function titles(): array {
		$out = array();
		foreach ( self::GROUPS as $key => $g ) {
			$out[ $key ] = $g[0];
		}
		return $out;
	}

	/** @return string|null chiave dell'area di una vista */
	public static function area_of( string $view ): ?string {
		$view = self::ALIASES[ $view ] ?? $view;
		foreach ( self::GROUPS as $key => $g ) {
			if ( in_array( $view, $g[1], true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Viste per blocchi e widget: slug => «Area · etichetta», in ordine per area.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		$out = array();
		foreach ( self::GROUPS as $g ) {
			foreach ( $g[1] as $view ) {
				if ( isset( Shortcodes::VIEWS[ $view ] ) ) {
					$out[ $view ] = $g[0] . ' · ' . Shortcodes::VIEWS[ $view ];
				}
			}
		}
		foreach ( self::ALIASES as $old => $new ) { // il nome precedente resta selezionabile, in fondo, per le pagine già fatte
			if ( isset( Shortcodes::VIEWS[ $old ] ) ) {
				$out[ $old ] = ( self::GROUPS[ self::area_of( $old ) ][0] ?? '' ) . ' · ' . Shortcodes::VIEWS[ $old ];
			}
		}
		return $out;
	}

	/** Viste dichiarate ma senza area: non devono esistere (controllato dai test). */
	public static function unassigned(): array {
		$out = array();
		foreach ( array_keys( Shortcodes::VIEWS ) as $view ) {
			if ( null === self::area_of( $view ) ) {
				$out[] = $view;
			}
		}
		return $out;
	}
}
