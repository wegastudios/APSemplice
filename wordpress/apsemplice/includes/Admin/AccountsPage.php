<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class AccountsPage {

	public static function render(): void {
		$back = Ui::url( 'aps-accounts' );
		$today = current_time( 'Y-m-d' );
		Ui::header( 'Conti e cassa' );
		echo '<p class="description">Il saldo dell\'app deve coincidere con la realtà: con "Verifica saldo" confronti il contante contato o l\'estratto conto, e se serve rettifichi.</p>';
		echo '<div class="aps-grid">';
		foreach ( Plugin::ledger()->balances() as $a ) {
			echo '<div class="aps-card"><h2>' . esc_html( $a['name'] ) . '</h2><p class="description">' . esc_html( Labels::account_types()[ $a['type'] ] ?? $a['type'] ) . '</p>';
			echo '<p class="aps-big">' . Ui::money( $a['balance'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<details><summary>Verifica saldo</summary>';
			Ui::form_open( 'aps_cash_count', $back );
			echo Ui::hidden( 'account_id', $a['id'] ) . Ui::hidden( 'date', $today ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p>Saldo reale (contato / estratto conto): <input type="text" name="counted" inputmode="decimal" required> €</p>';
			echo '<p><label><input type="checkbox" name="adjust" value="1"> Registra una rettifica per riallineare l\'app</label></p>';
			echo '<button class="button">Verifica</button>';
			Ui::form_close();
			echo '</details></div>';
		}
		echo '</div>';

		echo '<div class="aps-card"><h2>Nuovo conto</h2>';
		Ui::form_open( 'aps_add_account', $back );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Conto POS"></td></tr>';
		echo '<tr><th>Tipo</th><td><select name="type">' . Ui::options( Labels::account_types(), 'bank' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Saldo iniziale attuale</th><td><input type="text" name="opening" inputmode="decimal" placeholder="0,00"> €</td></tr></tbody></table>';
		submit_button( 'Aggiungi conto' );
		Ui::form_close();
		echo '</div>';
		Ui::footer();
	}
}
