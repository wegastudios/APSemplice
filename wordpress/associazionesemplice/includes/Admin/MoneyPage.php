<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Modules;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cassa: la cassa di tutti i giorni, per anno sociale. Incassi, spese, liquidità reale (conti e fondi) e gli ultimi movimenti.
 * La prima nota è una sola: qui si usa per cassa, in Contabilità per anno solare e adempimenti.
 */
final class MoneyPage {

	public static function render(): void {
		Ui::header( 'Cassa' );
		$year = Settings::social_year();
		$r    = Plugin::reports()->social_year( $year );
		echo '<p><a class="button button-primary" href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Nuovo incasso</a> '
			. ( \AssociazioneSemplice\Edition::has( 'funds' ) ? '<a class="button" href="' . esc_url( Ui::url( 'asem-group' ) ) . '">Cassa per più persone</a> ' : '' )
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-expense' ) ) . '">Nuova spesa</a>'
			. ( Modules::on( 'accounts' ) ? ' <a class="button" href="' . esc_url( Ui::url( 'asem-transfer' ) ) . '">Giroconto</a>' : '' ) . '</p>';

		echo '<div class="asem-grid"><div class="asem-card"><h2>Disponibilità reale</h2>';
		$avail    = Plugin::funds()->available();
		$balances = Plugin::ledger()->balances();
		echo '<p class="asem-big">' . Ui::money( $avail['available'] ) . '</p><p class="description">Saldi dei conti meno i fondi accantonati per i rimborsi.</p><table class="asem-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $balances as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale saldi</strong></td><td><strong>' . Ui::money( $avail['accounts'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( Plugin::funds()->all() as $f ) {
			echo '<tr><td>− ' . esc_html( $f['name'] ) . '</td><td>' . Ui::money( $f['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table>' . ( Modules::on( 'accounts' ) ? '<p><a href="' . esc_url( Ui::url( 'asem-accounts' ) ) . '">Conti, fondi e verifica saldi →</a></p>' : '' ) . '</div>';

		echo '<div class="asem-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="asem-kv">'
			. '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Resta all\'ente</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Per cassa: conta quando i soldi entrano ed escono, nell\'anno sociale. La contabilità per anno solare è in Contabilità.</p></div></div>';

		// Ultimi movimenti
		$to   = current_time( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', strtotime( $to . ' -60 days' ) );
		$rows = array_slice( Plugin::ledger()->rows( $from, $to ), 0, 10 );
		echo '<h2>Ultimi movimenti</h2>';
		if ( ! $rows ) {
			echo '<p class="description">Nessun movimento negli ultimi 60 giorni.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Voce</th><th>Conto</th><th>Importo</th></tr></thead><tbody>';
			foreach ( $rows as $m ) {
				$sign = Labels::sign( $m['type'] ) > 0 ? '+' : '−';
				echo '<tr><td>' . Ui::date( $m['tx_date'] ) . '</td><td>' . esc_html( Labels::is_transfer( $m['type'] ) ? 'Giroconto' : $m['category_name'] ) . ( '' !== (string) $m['person_name'] && ' ' !== $m['person_name'] ? ' <span class="description">' . esc_html( trim( (string) $m['person_name'] ) ) . '</span>' : '' ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
					. '<td>' . esc_html( $m['account_name'] ) . '</td><td>' . esc_html( $sign . ' ' . Money::format( (int) $m['amount_cents'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><a href="' . esc_url( Ui::url( 'asem-ledger' ) ) . '">Apri la prima nota →</a></p>';
		if ( ! \AssociazioneSemplice\Edition::has( 'funds' ) ) { // un solo promemoria, discreto, dove serve davvero
			echo '<p class="description">Più conti, pagamenti online, ricevute e report sono in AssociazioneSemplice Pro: <a href="' . esc_url( Ui::url( 'asem-pro' ) ) . '">scopri cosa comprende</a>.</p>';
		}
		Ui::footer();
	}
}
