<?php
namespace ApSemplice\Admin;

use ApSemplice\Modules;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Strumenti: tutto ciò che riguarda importare, esportare, copie, calendari e integrazioni, ognuno con la sua funzione.
 * Per ora raggruppa; l'organizzazione interna si potrà riordinare più avanti.
 */
final class ToolsPage {

	/** Riquadri della panoramica: titolo, [pagina, etichetta, descrizione, solo amministratori, parte che la rende utile]. */
	private static function sections(): array {
		return array(
			'Importare' => array(
				array( 'apse-import', 'Importa da Excel o CSV', 'Carica soci da un file: anteprima e conferma prima di salvare.', false, 'import' ),
				array( 'apse-wpai', 'WP All Import', 'Importazione continua da WP All Import (soci e pagamenti).', true, 'import' ),
			),
			'Esportare' => array(
				array( 'apse-exports', 'Esportazioni CSV', 'Libro soci e prima nota in CSV, per il commercialista o per i tuoi archivi (con le funzioni avanzate anche rendiconto e report).', false, '' ),
			),
			'Calendari' => array(
				array( 'apse-calendar', 'Calendario di corsi ed eventi', 'Calendario interno di tutte le attività e indirizzo del calendario pubblicato (Google Calendar e simili).', false, 'activities' ),
			),
			'Configurazione' => array(
				array( 'apse-wizard', 'Configurazione guidata', 'Le domande iniziali per scegliere cosa usare e configurare solo quello. Si rifà quando vuoi, ad esempio per accendere o spegnere una parte.', true, '' ),
			),
			'Copie e collegamenti' => array(
				array( 'apse-backup', 'Copia di sicurezza', 'Scarica una copia dei dati e ripristinala.', true, '' ),
				array( 'apse-tech', 'Integrazioni', 'WP All Import, Elementor e Gutenberg (con le funzioni avanzate anche WooCommerce, Stripe, PayPal e Wallet).', true, '' ),
			),
		);
	}

	public static function render(): void {
		Ui::header( 'Strumenti' );
		echo '<p class="description">Importazioni, esportazioni, calendari, copie di sicurezza e integrazioni in un unico posto.</p>';
		$admin = current_user_can( Plugin::CAP );
		echo '<div class="apse-grid">';
		foreach ( self::sections() as $title => $items ) {
			$rows = '';
			foreach ( $items as $it ) {
				if ( ( $it[3] && ! $admin ) || ( '' !== $it[4] && ! Modules::on( $it[4] ) ) || in_array( $it[0], \ApSemplice\Edition::missing_pages(), true ) ) {
					continue;
				}
				$rows .= '<p><a href="' . esc_url( Ui::url( $it[0] ) ) . '"><strong>' . esc_html( $it[1] ) . '</strong></a><br><span class="description">' . esc_html( $it[2] ) . '</span></p>';
			}
			if ( '' !== $rows ) {
				echo '<div class="apse-card"><h2>' . esc_html( $title ) . '</h2>' . $rows . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</div>';
		Ui::footer();
	}

	/** Esportazioni CSV: ognuna con il suo periodo. Le voci contabili compaiono solo se la prima nota è in uso. */
	public static function render_exports(): void {
		$cur  = (int) current_time( 'Y' );
		$year = max( $cur - 10, min( $cur + 1, Ui::get_int( 'year', $cur ) ) );
		Ui::header( 'Esportazioni' );
		echo '<form method="get"><input type="hidden" name="page" value="apse-exports"><p><label>Anno <select name="year">';
		for ( $y = $cur + 1; $y >= $cur - 10; $y-- ) {
			echo '<option value="' . (int) $y . '"' . selected( $year, $y, false ) . '>' . (int) $y . '</option>';
		}
		echo '</select></label> <button class="button">Mostra</button></p></form>';
		$from = $year . '-01-01';
		$to   = $year . '-12-31';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Esportazione</th><th>Contenuto</th><th></th></tr></thead><tbody>';
		$rows = array(
			array( 'Soci e ospiti', 'Rubrica completa, con i dati anagrafici.', Exports::link( 'people', array(), 'Scarica CSV' ), true ),
			array( 'Prima nota (anno solare ' . $year . ')', 'Tutti i movimenti con conto, voce, attività e competenza.', Exports::link( 'ledger', array( 'from' => $from, 'to' => $to ), 'Scarica CSV' ), Modules::on( 'ledger' ) ),
			array( 'Rendiconto per cassa (anno solare ' . $year . ')', 'Entrate e uscite per voce, per il commercialista.', Exports::link( 'period', array( 'from' => $from, 'to' => $to ), 'Scarica CSV' ), Modules::on( 'reports' ) && \ApSemplice\Edition::has( 'fiscal' ) ),
			array( 'Report delle attività (anno sociale ' . $year . ')', 'Partecipazioni e incassi per attività.', Exports::link( 'social', array( 'year' => $year ), 'Scarica CSV' ), Modules::on( 'activities' ) && Modules::on( 'reports' ) && \ApSemplice\Edition::has( 'reports' ) ),
		);
		foreach ( $rows as $r ) {
			if ( $r[3] ) {
				echo '<tr><td><strong>' . esc_html( $r[0] ) . '</strong></td><td>' . esc_html( $r[1] ) . '</td><td>' . $r[2] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</tbody></table><p class="description">I file usano il punto e virgola e la virgola decimale: si aprono direttamente in Excel italiano.</p>';
		Ui::footer();
	}
}
