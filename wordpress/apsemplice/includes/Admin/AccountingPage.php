<?php
namespace ApSemplice\Admin;

use ApSemplice\Fiscal;
use ApSemplice\FiscalYears;
use ApSemplice\FivePerMille;
use ApSemplice\Modules;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contabilità: per anno solare e per gli adempimenti. Usa la stessa prima nota di «Cassa»: qui si guarda il periodo,
 * si chiude l'anno, si preparano rendiconto e adempimenti (5x1000, e con la partita IVA l'esportazione per il commercialista).
 */
final class AccountingPage {

	public static function render(): void {
		Ui::header( 'Contabilità' );
		$cur = FiscalYears::current();
		$fy  = FiscalYears::get( $cur );
		echo '<div class="apse-grid"><div class="apse-card"><h2>Anno solare ' . (int) $cur . '</h2>';
		echo '<p>' . ( $fy && 'open' !== $fy['status'] ? '<strong class="apse-neg">Anno chiuso</strong>' : '<span class="apse-ok">Anno aperto</span>' ) . '</p>';
		echo '<p><a href="' . esc_url( Ui::url( 'apse-ledger', array( 'year' => $cur ) ) ) . '">Prima nota dell\'anno →</a><br>'
			. ( Modules::on( 'reports' ) ? '<a href="' . esc_url( Ui::url( 'apse-statement' ) ) . '">Rendiconto →</a><br><a href="' . esc_url( Ui::url( 'apse-reports' ) ) . '">Report →</a><br>' : '' )
			. '<a href="' . esc_url( Ui::url( 'apse-years' ) ) . '">Apertura e chiusura degli anni solari →</a></p>';
		echo '<p class="description">È la stessa prima nota di «Cassa»: qui si legge per anno solare, per chiudere l\'anno e preparare i documenti.</p></div>';

		echo '<div class="apse-card"><h2>Adempimenti</h2>';
		$any = false;
		if ( FivePerMille::enabled() ) {
			$any = true;
			$fp  = FivePerMille::alerts();
			echo '<p><a href="' . esc_url( Ui::url( 'apse-fivepm' ) ) . '"><strong>5x1000</strong></a><br><span class="description">' . ( $fp ? esc_html( count( $fp ) . ( 1 === count( $fp ) ? ' contributo da rendicontare.' : ' contributi da rendicontare.' ) ) : 'Nessuna scadenza in vista.' ) . '</span></p>';
		}
		if ( Fiscal::vat_applies() ) {
			$any = true;
			echo '<p><strong>IVA</strong> — partita IVA ' . esc_html( (string) Settings::get( 'vat_number' ) ) . '<br><span class="description">La prima nota registra aliquota e IVA contenuta di ogni riga: <a href="' . esc_url( Ui::url( 'apse-exports', array( 'year' => $cur ) ) ) . '">esportala per il commercialista</a>, che completa i quadri.</span></p>';
		}
		if ( ! $any ) {
			echo '<p class="description">Nessun adempimento attivo. Il 5x1000 si attiva dalla sua scheda; con la partita IVA compare l\'esportazione per il commercialista (Dati e fiscalità).</p>';
		}
		echo '</div></div>';
		Ui::footer();
	}
}
