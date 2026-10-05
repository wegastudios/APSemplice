<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class AccountsPage {

	private static function cents_input( int $cents ): string {
		return number_format( $cents / 100, 2, ',', '' );
	}

	private static function card( array $a, string $back, string $today ): void {
		$closed = null !== $a['closed_at'];
		$fund   = 'fund' === $a['kind'];
		echo '<div class="apse-card"><h2>' . esc_html( $a['name'] ) . ( $fund ? ' <span class="description">(fondo)</span>' : '' ) . ( $closed ? ' <span class="description">(chiuso)</span>' : '' ) . '</h2>'
			. '<p class="description">' . esc_html( Labels::account_types()[ $a['type'] ] ?? $a['type'] ) . ( $fund ? ' · soldi che non sono dell\'associazione' : '' ) . '</p>';
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
			. '<tr><th>Natura</th><td><select name="kind">' . Ui::options( Labels::account_kinds(), $a['kind'] ) . '</select></td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><th>Saldo di partenza</th><td><input type="text" name="opening" inputmode="decimal" value="' . esc_attr( self::cents_input( (int) $a['opening_cents'] ) ) . '"> €<p class="description">Il saldo di partenza è quello da cui parte il conto, prima dei movimenti registrati: il saldo attuale si ricalcola.</p></td></tr>'
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

	public static function render(): void {
		$back  = Ui::url( 'apse-accounts' );
		$today = current_time( 'Y-m-d' );
		Ui::header( 'Conti e cassa' );
		echo '<p class="description">Il saldo dell\'app deve coincidere con la realtà: con "Verifica saldo" confronti il contante contato o l\'estratto conto, e se serve rettifichi. Un <strong>fondo</strong> è denaro che hai in cassa ma non è dell\'associazione (ad esempio le quote raccolte per rimborsare un socio): non entra nelle disponibilità né nel rendiconto.</p>';

		$open   = array();
		$funds  = array();
		$closed = array();
		foreach ( Plugin::ledger()->balances( null, true ) as $a ) {
			if ( null !== $a['closed_at'] ) {
				$closed[] = $a;
			} elseif ( 'fund' === $a['kind'] ) {
				$funds[] = $a;
			} else {
				$open[] = $a;
			}
		}
		echo '<h2>Conti dell\'associazione</h2><div class="apse-grid">';
		foreach ( $open as $a ) {
			self::card( $a, $back, $today );
		}
		echo '</div>';
		if ( $funds ) {
			echo '<h2>Fondi (soldi di altri)</h2><div class="apse-grid">';
			foreach ( $funds as $a ) {
				self::card( $a, $back, $today );
			}
			echo '</div>';
		}
		if ( $closed ) {
			echo '<h2>Conti chiusi</h2><div class="apse-grid">';
			foreach ( $closed as $a ) {
				self::card( $a, $back, $today );
			}
			echo '</div>';
		}

		echo '<div class="apse-card"><h2>Nuovo conto o fondo</h2>';
		Ui::form_open( 'apse_add_account', $back );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Conto POS"></td></tr>';
		echo '<tr><th>Tipo</th><td><select name="type">' . Ui::options( Labels::account_types(), 'bank' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Natura</th><td><select name="kind">' . Ui::options( Labels::account_kinds(), 'real' ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Saldo iniziale attuale</th><td><input type="text" name="opening" inputmode="decimal" placeholder="0,00"> €</td></tr></tbody></table>';
		submit_button( 'Aggiungi' );
		Ui::form_close();
		echo '</div>';
		Ui::footer();
	}
}
