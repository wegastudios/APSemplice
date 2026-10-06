<?php
namespace ApSemplice\Admin;

use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cassa per più persone: una persona paga eventi, corsi e quote per sé e per altri (familiari, altri soci, amici ospiti).
 * Un solo incasso, un solo totale e un solo resto; ogni voce resta intestata a chi ne beneficia.
 */
final class GroupCashPage {

	public static function render(): void {
		$ledger   = Plugin::ledger();
		$acts     = Plugin::activities();
		$accounts = array();
		$types    = array();
		foreach ( $ledger->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
			$types[ (int) $a['id'] ] = $a['type'];
		}
		$cats = array();
		foreach ( $ledger->categories() as $c ) {
			if ( in_array( $c['kind'], array( 'activity_fee', 'membership' ), true ) ) {
				$cats[ $c['kind'] ] = (int) $c['id'];
			}
		}
		$events = array();
		foreach ( $acts->upcoming_sessions( 60 ) as $s ) {
			$a        = $acts->get( (int) $s['activity_id'] );
			$events[] = array(
				'session_id' => (int) $s['id'], 'activity_id' => (int) $s['activity_id'],
				'label'      => $s['activity_name'] . ' · ' . Ui::date( $s['session_date'] ) . ( $s['start_time'] ? ' ' . $s['start_time'] : '' ),
				'fee'        => (int) $a['fee_cents'], 'guest_fee' => null === $a['guest_fee_cents'] || '' === $a['guest_fee_cents'] ? (int) $a['fee_cents'] : (int) $a['guest_fee_cents'],
			);
		}
		$courses = array();
		foreach ( $acts->for_year( Settings::social_year()->label() ) as $a ) {
			if ( 'course' === $a['kind'] ) {
				$courses[] = array( 'id' => (int) $a['id'], 'name' => $a['name'], 'fee' => (int) $a['fee_cents'], 'guest_fee' => null === $a['guest_fee_cents'] || '' === $a['guest_fee_cents'] ? (int) $a['fee_cents'] : (int) $a['guest_fee_cents'] );
			}
		}
		$people = array();
		foreach ( Plugin::people()->search() as $p ) {
			$people[] = array( 'id' => (int) $p['id'], 'label' => Ui::person_label( $p ), 'type' => $p['type'] );
		}
		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'apse_income' ), 'cats' => $cats, 'events' => $events, 'courses' => $courses,
			'people' => $people, 'accountTypes' => $types, 'membershipFee' => (int) Settings::get( 'membership_fee_cents' ), 'month' => Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) ),
		);
		$default = $ledger->default_account_for( 'cash' );

		Ui::header( 'Cassa per più persone' );
		echo '<p class="description">Una persona paga per sé e per altri (familiari, altri soci, amici ospiti): un solo incasso, un solo totale e un solo resto; ogni voce resta intestata a chi ne beneficia. '
			. 'Un socio può pagare un <strong>evento</strong> anche per un socio sospeso o con la tessera non in regola; per i <strong>corsi</strong> la tessera deve essere in regola (si può aggiungere la quota nello stesso incasso). Per la sola cassa rapida usa la Bacheca.</p>';
		echo '<script type="application/json" id="apse-group-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_open( 'apse_save_group_cash', Ui::url( 'apse-group' ), false, 'apse-group' );
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></td></tr>';
		echo '<tr><th>Conto</th><td><select name="account_id" id="apse-g-account">' . Ui::options( $accounts, $default ? $default['id'] : null ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Chi paga *</th><td>' . Ui::person_select( 'payer_id', Plugin::people()->search(), null, '— scegli chi paga —', 'apse-g-payer' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';
		echo '<h2>Per chi</h2><div id="apse-g-people"></div>';
		echo '<p><select id="apse-g-add-person"><option value="">+ Aggiungi una persona dell\'anagrafica…</option></select> <button type="button" class="button" id="apse-g-add-guest">+ Nuovo ospite</button></p>';
		echo '<p class="apse-total">Totale: <strong id="apse-g-total">0,00 €</strong></p>';
		echo '<div id="apse-g-cash" class="apse-card" style="display:none"><h3>Contanti</h3><p><label>Contanti ricevuti <input type="text" id="apse-g-tendered" inputmode="decimal" placeholder="importo esatto"> €</label></p><p id="apse-g-quick"></p><p id="apse-g-change"></p></div>';
		echo '<p><label>N. ricevuta (facoltativo) <input type="text" name="document_ref" maxlength="80"></label></p>';
		submit_button( 'Registra l\'incasso' );
		Ui::form_close();
		Ui::footer();
	}
}
