<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class LedgerPage {

	public static function render(): void {
		$year   = Ui::get_int( 'year', (int) current_time( 'Y' ) );
		$acc    = Ui::get_int( 'account' );
		$from   = sprintf( '%04d-01-01', $year );
		$to     = sprintf( '%04d-12-31', $year );
		$rows   = Plugin::ledger()->rows( $from, $to, $acc ?: null );
		$here   = Ui::url( 'aps-ledger', array( 'year' => $year, 'account' => $acc ?: null ) );
		$income = 0;
		$expense = 0;
		foreach ( $rows as $r ) {
			if ( 'income' === $r['type'] ) {
				$income += (int) $r['amount_cents'];
			} elseif ( 'expense' === $r['type'] ) {
				$expense += (int) $r['amount_cents'];
			}
		}

		Ui::header( 'Prima nota — anno solare ' . $year, Exports::link( 'ledger', array( 'from' => $from, 'to' => $to ), 'Esporta CSV' ) );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'aps-ledger', array( 'year' => $year - 1, 'account' => $acc ?: null ) ) ) . '">‹ ' . ( $year - 1 ) . '</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'aps-ledger', array( 'year' => $year + 1, 'account' => $acc ?: null ) ) ) . '">' . ( $year + 1 ) . ' ›</a> ';
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		echo '<form method="get" style="display:inline"><input type="hidden" name="page" value="aps-ledger"><input type="hidden" name="year" value="' . esc_attr( (string) $year ) . '">'
			. '<select name="account" onchange="this.form.submit()">' . Ui::options( $accounts, $acc ?: null, 'Tutti i conti' ) . '</select></form></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Entrate <strong>' . Ui::money( $income ) . '</strong> · Uscite <strong>' . Ui::money( $expense ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Voce</th><th>Persona</th><th>Conto</th><th>Importo</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessun movimento.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$sign  = Labels::sign( $r['type'] ) > 0 ? '+' : '−';
			$cls   = Labels::is_transfer( $r['type'] ) ? '' : ( Labels::sign( $r['type'] ) > 0 ? 'aps-ok' : 'aps-neg' );
			$title = Labels::is_transfer( $r['type'] ) ? Labels::tx_types()[ $r['type'] ] : $r['category_name'];
			$extra = array_filter( array( $r['activity_name'], $r['competence_month'] ? 'competenza ' . $r['competence_month'] : null, $r['description'], $r['document_ref'] ? 'rif. ' . $r['document_ref'] : null ) );
			echo '<tr><td>' . Ui::date( $r['tx_date'] ) . '</td><td><strong>' . esc_html( $title ) . '</strong><br><span class="description">' . esc_html( implode( ' · ', $extra ) ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( trim( ( $r['person_card'] ? 'n.' . $r['person_card'] . ' ' : '' ) . $r['person_name'] ) ) . '</td>';
			echo '<td>' . esc_html( $r['account_name'] . ' · ' . ( Labels::methods()[ $r['method'] ] ?? $r['method'] ) ) . '</td>';
			echo '<td class="' . esc_attr( $cls ) . '">' . esc_html( $sign . ' ' . Money::format( (int) $r['amount_cents'] ) ) . '</td><td>';
			echo '<details><summary>Annulla</summary>';
			Ui::form_open( 'aps_void_tx', $here, false, 'aps-confirm' );
			echo Ui::hidden( 'id', $r['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<input type="text" name="reason" placeholder="Motivo"> <button class="button button-small" data-confirm="Annullare questo movimento? Resta tracciato ma non conta più nei saldi.">Annulla movimento</button>';
			Ui::form_close();
			echo '</details></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">I movimenti non si modificano: per correggere si annulla e si registra di nuovo. L\'annullamento resta tracciato.</p>';
		Ui::footer();
	}
}
