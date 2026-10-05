<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class AccountsPage {

	private static function card( array $a, string $back, string $today ): void {
		$closed = null !== $a['closed_at'];
		echo '<div class="apse-card"><h2>' . esc_html( $a['name'] ) . ( $closed ? ' <span class="description">(chiuso)</span>' : '' ) . '</h2>'
			. '<p class="description">' . esc_html( Labels::account_types()[ $a['type'] ] ?? $a['type'] ) . '</p>';
		echo '<p class="apse-big">' . Ui::money( $a['balance'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( ! $closed ) {
			echo '<details><summary>Verifica saldo</summary>';
			Ui::form_open( 'apse_cash_count', $back );
			echo Ui::hidden( 'account_id', $a['id'] ) . Ui::hidden( 'date', $today ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p>Saldo reale (contato / estratto conto): <input type="text" name="counted" inputmode="decimal" required> €</p>';
			echo '<p><label><input type="checkbox" name="adjust" value="1"> Registra una rettifica per riallineare l\'app</label></p>';
			echo '<button class="button">Verifica</button>';
			Ui::form_close();
			echo '</details>';
		}

		echo '<details><summary>Modifica</summary>';
		Ui::form_open( 'apse_update_account', $back );
		echo Ui::hidden( 'id', $a['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>'
			. '<tr><th>Nome</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr( $a['name'] ) . '"></td></tr>'
			. '<tr><th>Tipo</th><td><select name="type">' . Ui::options( Labels::account_types(), $a['type'] ) . '</select></td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><th>Saldo di partenza</th><td><input type="text" name="opening" inputmode="decimal" value="' . esc_attr( Money::plain( (int) $a['opening_cents'] ) ) . '"> €<p class="description">È il saldo da cui parte il conto, prima dei movimenti registrati: il saldo attuale si ricalcola.</p></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva', 'secondary', 'submit', false );
		Ui::form_close();
		echo '</details>';

		if ( $closed ) {
			Ui::form_open( 'apse_reopen_account', $back );
			echo Ui::hidden( 'id', $a['id'] ) . '<button class="button">Riapri il conto</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		} else {
			Ui::form_open( 'apse_close_account', $back );
			echo Ui::hidden( 'id', $a['id'] ) . '<button class="button-link" onclick="return confirm(\'Chiudere il conto? Non accetterà più movimenti (si può riaprire).\')">Chiudi il conto</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '</div>';
	}

	private static function fund_card( array $f, string $back, string $today ): void {
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		echo '<div class="apse-card"><h2>' . esc_html( $f['name'] ) . '</h2><p class="apse-big">' . Ui::money( $f['balance'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<details><summary>Libera una quota</summary><p class="description">Restituisce una parte all\'associazione: torna nella disponibilità reale. Non muove contanti né conti.</p>';
		Ui::form_open( 'apse_fund_release', $back );
		echo Ui::hidden( 'id', $f['id'] ) . Ui::hidden( 'date', $today ) . '<p><input type="text" name="amount" inputmode="decimal" required placeholder="0,00"> € <button class="button">Libera</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		echo '</details>';
		echo '<details><summary>Estingui e paga il rimborso</summary><p class="description">Registra in prima nota l\'uscita di ' . esc_html( Money::format( $f['balance'] ) ) . ' (rimborso al volontario) e chiude il fondo.</p>';
		Ui::form_open( 'apse_fund_settle', $back );
		echo Ui::hidden( 'id', $f['id'] ) . Ui::hidden( 'date', $today ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Pagato da <select name="account_id">' . Ui::options( $accounts, null ) . '</select> <select name="method">' . Ui::options( Labels::methods(), 'cash' ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button class="button" onclick="return confirm(\'Registrare il rimborso e chiudere il fondo?\')">Estingui il fondo</button>';
		Ui::form_close();
		echo '</details></div>';
	}

	public static function render(): void {
		$back  = Ui::url( 'apse-accounts' );
		$today = current_time( 'Y-m-d' );
		$avail = Plugin::funds()->available();
		Ui::header( 'Conti e cassa' );
		echo '<div class="apse-card"><h2>Disponibilità reale dell\'associazione</h2><p class="apse-big">' . Ui::money( $avail['available'] ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">Saldi dei conti ' . esc_html( Money::format( $avail['accounts'] ) ) . ' meno fondi accantonati ' . esc_html( Money::format( $avail['funds'] ) ) . '.</p></div>';
		echo '<p class="description">Il saldo dell\'app deve coincidere con la realtà: con "Verifica saldo" confronti il contante contato o l\'estratto conto, e se serve rettifichi.</p>';

		$open   = array();
		$closed = array();
		foreach ( Plugin::ledger()->balances( null, true ) as $a ) {
			if ( null !== $a['closed_at'] ) {
				$closed[] = $a;
			} else {
				$open[] = $a;
			}
		}
		echo '<h2>Conti</h2><div class="apse-grid">';
		foreach ( $open as $a ) {
			self::card( $a, $back, $today );
		}
		echo '</div>';

		$funds = Plugin::funds()->all();
		echo '<h2>Fondi per i rimborsi</h2><p class="description">Un corso o un evento può destinare una parte di ogni pagamento al rimborso del volontario. Il pagamento resta nel conto in cui è entrato; la quota è accantonata qui e non fa parte della disponibilità reale finché il fondo non è estinto o liberato.</p>';
		if ( $funds ) {
			echo '<div class="apse-grid">';
			foreach ( $funds as $f ) {
				self::fund_card( $f, $back, $today );
			}
			echo '</div>';
		} else {
			echo '<p>Nessun fondo aperto. Si creano da soli quando incassi un corso o un evento con una quota per il rimborso (si imposta nella scheda dell\'attività).</p>';
		}

		if ( $closed ) {
			echo '<h2>Conti chiusi</h2><div class="apse-grid">';
			foreach ( $closed as $a ) {
				self::card( $a, $back, $today );
			}
			echo '</div>';
		}

		echo '<div class="apse-card"><h2>Nuovo conto</h2>';
		Ui::form_open( 'apse_add_account', $back );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Conto POS"></td></tr>';
		echo '<tr><th>Tipo</th><td><select name="type">' . Ui::options( Labels::account_types(), 'bank' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Saldo iniziale attuale</th><td><input type="text" name="opening" inputmode="decimal" placeholder="0,00"> €</td></tr></tbody></table>';
		submit_button( 'Aggiungi conto' );
		Ui::form_close();
		echo '</div>';
		Ui::footer();
	}
}
