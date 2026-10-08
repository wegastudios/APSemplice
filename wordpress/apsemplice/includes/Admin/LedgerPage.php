<?php
namespace ApSemplice\Admin;

use ApSemplice\AttachmentRules;
use ApSemplice\Attachments;
use ApSemplice\Labels;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class LedgerPage {

	/** Allegati di un movimento: elenco con apertura/rimozione e aggiunta di altri file. */
	private static function attachments_cell( int $tx_id, array $list, string $here ): void {
		echo '<details class="apse-attach"><summary>📎 Allegati' . ( $list ? ' (' . count( $list ) . ')' : '' ) . '</summary>';
		if ( $list ) {
			echo '<ul>';
			foreach ( $list as $a ) {
				echo '<li><a href="' . esc_url( Attachments::url( (int) $a['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $a['original_name'] ) . '</a> <span class="description">' . esc_html( AttachmentRules::format_size( (int) $a['size_bytes'] ) ) . '</span> ';
				Ui::form_open( 'apse_remove_attachment', $here, false, 'apse-inline' );
				echo Ui::hidden( 'id', $a['id'] ) . '<button class="button-link" data-confirm="Togliere questo allegato dall\'elenco? Resta nel registro azioni.">togli</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul>';
		}
		Ui::form_open( 'apse_add_attachment', $here, true );
		echo Ui::hidden( 'transaction_id', $tx_id ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<input type="file" name="docs[]" class="apse-doc-input" accept="image/*,application/pdf" multiple> '
			. '<label class="button button-small apse-shot">📷 Foto<input type="file" name="shots[]" class="apse-doc-input" accept="image/*" capture="environment" hidden></label> '
			. '<button class="button button-small">Allega</button>';
		Ui::form_close();
		echo '</details>';
	}

	public static function render(): void {
		$year   = Ui::get_int( 'year', (int) current_time( 'Y' ) );
		$acc    = Ui::get_int( 'account' );
		$from   = sprintf( '%04d-01-01', $year );
		$to     = sprintf( '%04d-12-31', $year );
		$rows   = Plugin::ledger()->rows( $from, $to, $acc ?: null );
		$here   = Ui::url( 'apse-ledger', array( 'year' => $year, 'account' => $acc ?: null ) );
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
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'apse-ledger', array( 'year' => $year - 1, 'account' => $acc ?: null ) ) ) . '">‹ ' . ( $year - 1 ) . '</a> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-ledger', array( 'year' => $year + 1, 'account' => $acc ?: null ) ) ) . '">' . ( $year + 1 ) . ' ›</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		echo '<form method="get" style="display:inline"><input type="hidden" name="page" value="apse-ledger"><input type="hidden" name="year" value="' . esc_attr( (string) $year ) . '">'
			. '<select name="account" onchange="this.form.submit()">' . Ui::options( $accounts, $acc ?: null, 'Tutti i conti' ) . '</select></form></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Entrate <strong>' . Ui::money( $income ) . '</strong> · Uscite <strong>' . Ui::money( $expense ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Voce</th><th>Persona</th><th>Conto</th><th>Importo</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessun movimento.</td></tr>';
		}
		$att = Attachments::map_for( array_column( $rows, 'id' ) );
		foreach ( $rows as $r ) {
			$sign  = Labels::sign( $r['type'] ) > 0 ? '+' : '−';
			$cls   = Labels::is_transfer( $r['type'] ) ? '' : ( Labels::sign( $r['type'] ) > 0 ? 'apse-ok' : 'apse-neg' );
			$title = Labels::is_transfer( $r['type'] ) ? Labels::tx_types()[ $r['type'] ] : $r['category_name'];
			$extra = array_filter( array( $r['activity_name'], $r['competence_month'] ? 'competenza ' . $r['competence_month'] : null, $r['description'], $r['document_ref'] ? 'rif. ' . $r['document_ref'] : null ) );
			echo '<tr><td>' . Ui::date( $r['tx_date'] ) . '</td><td><strong>' . esc_html( $title ) . '</strong><br><span class="description">' . esc_html( implode( ' · ', $extra ) ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( trim( ( $r['person_card'] ? 'n.' . $r['person_card'] . ' ' : '' ) . $r['person_name'] ) ) . ( ! empty( $r['payer_name'] ) ? ' <span class="description">(pagato da ' . esc_html( $r['payer_name'] ) . ')</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $r['account_name'] . ' · ' . ( Labels::methods()[ $r['method'] ] ?? $r['method'] ) ) . '</td>';
			echo '<td class="' . esc_attr( $cls ) . '">' . esc_html( $sign . ' ' . Money::format( (int) $r['amount_cents'] ) ) . ( (int) $r['vat_cents'] > 0 ? '<br><span class="description">di cui IVA ' . (int) $r['vat_rate'] . '%: ' . esc_html( Money::format( (int) $r['vat_cents'] ) ) . '</span>' : '' ) . '</td><td>';
			if ( ! Labels::is_transfer( $r['type'] ) ) {
				self::attachments_cell( (int) $r['id'], $att[ (int) $r['id'] ] ?? array(), $here );
			}
			if ( 'income' === $r['type'] ) {
				$rkey = \ApSemplice\Receipts::key_of( $r );
				echo '<a class="button button-small" target="_blank" href="' . esc_url( \ApSemplice\Receipts::url( $rkey ) ) . '">Ricevuta PDF</a> ';
				Ui::form_open( 'apse_receipt_email', $here, false, 'apse-inline' );
				echo Ui::hidden( 'key', $rkey ) . '<button class="button-link" data-confirm="Mandare la ricevuta per email a chi ha pagato?">invia per email</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			echo '<details><summary>Annulla</summary>';
			Ui::form_open( 'apse_void_tx', $here, false, 'apse-confirm' );
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
