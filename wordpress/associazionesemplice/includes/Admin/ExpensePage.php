<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class ExpensePage {

	public static function render(): void {
		$ledger   = Plugin::ledger();
		$accounts = array();
		foreach ( $ledger->balances() as $a ) {
			$accounts[ $a['id'] ] = $a['name'] . ' (' . \AssociazioneSemplice\Money::format( $a['balance'] ) . ')';
		}
		$cats = array();
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][2] && 'adjustment' !== $c['kind'] ) {
				$cats[ $c['id'] ] = $c['name'];
			}
		}
		$acts = array();
		foreach ( Plugin::activities()->for_year( Settings::social_year()->label() ) as $a ) {
			$acts[ $a['id'] ] = $a['name'];
		}
		$default = $ledger->default_account_for( 'cash' );

		Ui::header( 'Nuova spesa / rimborso' );
		Ui::form_open( 'asem_save_expense', Ui::url( 'asem-expense' ), true );
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></td></tr>';
		echo '<tr><th>Pagato dal conto</th><td><select name="account_id">' . Ui::options( $accounts, $default ? $default['id'] : null ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Il saldo tra parentesi è quello attuale: controlla che il conto abbia fondi sufficienti.</p></td></tr>';
		echo '<tr><th>Voce</th><td><select name="category_id" required>' . Ui::options( $cats, null, '— scegli —' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Importo</th><td><input type="text" name="amount" inputmode="decimal" required> €</td></tr>';
		echo '<tr><th>Attività</th><td><select name="activity_id">' . Ui::options( $acts, null, 'Nessuna (costo generale)' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Beneficiario</th><td>' . Ui::person_select( 'person_id', Plugin::people()->search(), null, '— nessuno / fornitore —', 'asem-beneficiary' ) . '<p class="description">Es. il referente o il socio rimborsato.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Descrizione</th><td><input type="text" name="description" class="large-text"></td></tr>';
		echo Ui::vat_row( null, \AssociazioneSemplice\Fiscal::default_mode() ) . '<tr><th>N. fattura / scontrino</th><td><input type="text" name="document_ref" maxlength="80"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
		echo '<tr><th>Documenti</th><td><input type="file" name="docs[]" class="asem-doc-input" accept="image/*,application/pdf" multiple> '
			. '<label class="button asem-shot">📷 Scatta una foto<input type="file" name="shots[]" class="asem-doc-input" accept="image/*" capture="environment" hidden></label>'
			. '<p class="description">Scontrini e fatture, in PDF o foto (anche più file). Dal telefono puoi scattare la foto direttamente: viene ridotta prima dell\'invio. Restano in una cartella privata del sito, non nella libreria media.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Registra spesa' );
		Ui::form_close();
		Ui::footer();
	}
}
