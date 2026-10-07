<?php
namespace ApSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode `[apsemplice_*]`: funzionano in qualunque punto (Gutenberg blocco "Shortcode", Elementor widget
 * "Shortcode", editor classico, template). Blocchi Gutenberg e widget Elementor usano le stesse viste.
 *
 *  [apsemplice_area_soci sezioni="tessera,attivita,ospiti,profilo,volontario"]
 *  [apsemplice_tessera] [apsemplice_mie_attivita] [apsemplice_ospiti] [apsemplice_profilo] [apsemplice_area_volontari]
 *  [apsemplice_attivita anno="2025/2026" tipo="corso|evento|ricorrente" id="12" date="5"]
 *  [apsemplice_prossimi_eventi limite="5" prenotazione="si|no"]
 *  [apsemplice_accesso]
 *  [apsemplice_riservato accesso="soci|volontari|attivita" attivita="12,13"]…[/apsemplice_riservato]   (vedi Restrict)
 */
final class Shortcodes {

	/** Viste disponibili: slug => etichetta. */
	const VIEWS = array(
		'area_soci'       => 'Area soci (tessera, attività, ospiti, profilo)',
		'tessera'         => 'Tessera digitale',
		'mie_attivita'    => 'Le mie attività e prenotazioni',
		'pagamenti'       => 'Pagamenti da fare (paga online)',
		'ricevute'        => 'Le mie ricevute e attestazioni (PDF)',
		'regolamento'     => 'Regolamento da accettare',
		'spese'           => 'Spese del tesoriere (con foto dello scontrino)',
		'ingressi'        => 'Ingressi agli eventi (prenotati e QR, per chi gestisce)',
		'avvisi'          => 'Avvisi dei volontari agli iscritti (bacheca)',
		'ospiti'          => 'I miei ospiti',
		'profilo'         => 'Il mio profilo',
		'area_volontari'  => 'Area volontari (le attività che tengo)',
		'calendario'      => 'Calendario di corsi ed eventi (soci)',
		'attivita'        => 'Elenco attività ed eventi (pubblico)',
		'prossimi_eventi' => 'Prossimi eventi (pubblico)',
		'app'             => 'App e notifiche (installazione e notifiche sul telefono)',
		'cinquepermille'  => '5x1000 (messaggio pubblico con il codice fiscale)',
		'bonifico'        => 'Coordinate per il bonifico (pubblico, se attivo)',
		'accesso'         => 'Accesso / login',
	);

	public static function register(): void {
		foreach ( array_keys( self::VIEWS ) as $view ) {
			add_shortcode(
				'apsemplice_' . $view,
				function ( $atts ) use ( $view ) {
					return self::render_view( $view, (array) $atts );
				}
			);
		}
	}

	/** Disegna una vista. Usato da shortcode, blocco e widget. */
	public static function render_view( string $view, array $atts = array() ): string {
		switch ( $view ) {
			case 'area_soci':
				return Views::area( shortcode_atts( array( 'sezioni' => 'regolamento,tessera,attivita,calendario,avvisi,pagamenti,ospiti,profilo,ricevute,volontario,ingressi,spese,app' ), $atts ) );
			case 'tessera':
				return Views::card();
			case 'calendario':
				return Views::calendar();
			case 'avvisi':
				return Views::notices();
			case 'ingressi':
				return Views::checkin();
			case 'spese':
				return Views::expenses();
			case 'regolamento':
				return Views::rules();
			case 'ricevute':
				return Views::receipts();
			case 'pagamenti':
				return Views::pay();
			case 'mie_attivita':
				return Views::my_activities();
			case 'ospiti':
				return Views::guests();
			case 'profilo':
				return Views::profile();
			case 'area_volontari':
				return Views::volunteer();
			case 'attivita':
				return Views::activities( shortcode_atts( array( 'anno' => '', 'tipo' => '', 'id' => '', 'date' => '5' ), $atts ) );
			case 'prossimi_eventi':
				return Views::upcoming( shortcode_atts( array( 'limite' => '5', 'prenotazione' => 'si' ), $atts ) );
			case 'app':
				return Views::app();
			case 'cinquepermille':
				return Views::five_per_mille();
			case 'bonifico':
				return Views::bank_public();
			case 'accesso':
				return is_user_logged_in() ? '' : Views::login_prompt();
		}
		return '';
	}
}
