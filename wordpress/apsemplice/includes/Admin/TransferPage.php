<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class TransferPage {

	public static function render(): void {
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		Ui::header( 'Giroconto tra conti' );
		echo '<p class="description">Per spostamenti tra i conti dell\'associazione (versamento di contanti in banca, accredito del POS…). Non conta come entrata né come uscita.</p>';
		Ui::form_open( 'apse_save_transfer', Ui::url( 'apse-transfer' ) );
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></td></tr>';
		echo '<tr><th>Da</th><td><select name="from_id" required>' . Ui::options( $accounts, null, '— conto di partenza —' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>A</th><td><select name="to_id" required>' . Ui::options( $accounts, null, '— conto di arrivo —' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Importo</th><td><input type="text" name="amount" inputmode="decimal" required> €</td></tr>';
		echo '<tr><th>Descrizione</th><td><input type="text" name="description" class="large-text"></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Registra giroconto' );
		Ui::form_close();
		Ui::footer();
	}
}
