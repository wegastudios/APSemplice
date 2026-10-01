<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Incasso multi-voce (es. quota associativa + mensilità del corso) con calcolo del resto in contanti.
 * La parte dinamica è in assets/admin.js (blocco "aps-income").
 */
final class IncomePage {

	public static function register_ajax(): void {
		add_action( 'wp_ajax_aps_person_context', array( __CLASS__, 'ajax_context' ) );
	}

	/** Contesto di una persona per l'incasso: tessera, attività a cui è iscritta e primo mese da pagare. */
	public static function ajax_context(): void {
		check_ajax_referer( 'aps_income', 'nonce' );
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_send_json_error( 'Non autorizzato', 403 );
		}
		$person = Plugin::people()->get( (int) ( $_POST['person_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : current_time( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $person ) {
			wp_send_json_success( array( 'person' => null ) );
		}
		$sy    = Settings::social_year( $date )->label();
		$until = MemberType::GUEST === $person['type'] ? null : Plugin::people()->active_until( (int) $person['id'], $date );
		$acts  = array();
		foreach ( Plugin::activities()->active_activity_ids( (int) $person['id'] ) as $aid ) {
			$a = Plugin::activities()->get( $aid );
			if ( $a && $a['social_year'] === $sy ) {
				$next   = Plugin::activities()->first_unpaid_month( $aid, (int) $person['id'] );
				$acts[] = array( 'id' => (int) $a['id'], 'name' => $a['name'], 'fee' => (int) $a['monthly_fee_cents'], 'month' => $next ?: substr( $date, 0, 7 ) );
			}
		}
		wp_send_json_success(
			array(
				'person'           => array( 'id' => (int) $person['id'], 'type' => $person['type'], 'type_label' => MemberType::label( $person['type'] ) ),
				'is_guest'         => MemberType::GUEST === $person['type'],
				'is_founder'       => MemberType::is_auto_renewed( $person['type'] ),
				'active_until'     => $until,
				'needs_membership' => in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) && ( ! $until || $until < $date ),
				'social_year'      => $sy,
				'activities'       => $acts,
			)
		);
	}

	public static function render(): void {
		$ledger   = Plugin::ledger();
		$accounts = $ledger->accounts();
		$cats     = array();
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][1] && 'adjustment' !== $c['kind'] ) {
				$cats[] = array( 'id' => (int) $c['id'], 'name' => $c['name'], 'kind' => $c['kind'] );
			}
		}
		$today = current_time( 'Y-m-d' );
		$sy    = Settings::social_year( $today );
		$acts  = array();
		foreach ( Plugin::activities()->for_year( $sy->label() ) as $a ) {
			$acts[] = array( 'id' => (int) $a['id'], 'name' => $a['name'], 'fee' => (int) $a['monthly_fee_cents'] );
		}
		$default_account = $ledger->default_account_for( 'cash' );
		$accounts_by_method = array();
		foreach ( array_keys( Labels::methods() ) as $m ) {
			$d = $ledger->default_account_for( $m );
			$accounts_by_method[ $m ] = $d ? (int) $d['id'] : 0;
		}
		$data = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'aps_income' ),
			'categories'    => $cats,
			'activities'    => $acts,
			'membershipFee' => (int) Settings::get( 'membership_fee_cents' ),
			'socialYear'    => $sy->label(),
			'nextYear'      => $sy->next()->label(),
			'accountByMethod' => $accounts_by_method,
		);

		Ui::header( 'Nuovo incasso' );
		echo '<script type="application/json" id="aps-income-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_open( 'aps_save_income', Ui::url( 'aps-income' ), false, 'aps-income' );
		echo '<table class="form-table aps-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" id="aps-date" value="' . esc_attr( $today ) . '" required></td></tr>';
		echo '<tr><th>Pagamento</th><td><select name="method" id="aps-method">' . Ui::options( Labels::methods(), 'cash' ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		$acc_map = array();
		foreach ( $accounts as $a ) {
			$acc_map[ $a['id'] ] = $a['name'];
		}
		echo 'sul conto <select name="account_id" id="aps-account">' . Ui::options( $acc_map, $default_account ? $default_account['id'] : null ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Da chi</th><td>' . Ui::person_select( 'person_id', Plugin::people()->search(), null, '— nessuno / anonimo —', 'aps-person-select' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' <a href="' . esc_url( Ui::url( 'aps-person', array( 'type' => 'ordinary' ) ) ) . '" target="_blank">+ nuovo socio</a>'
			. '<div id="aps-person-info" class="aps-info"></div></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Voci</h2><div id="aps-lines"></div>';
		echo '<p class="aps-addbar">'
			. '<button type="button" class="button" id="aps-add-membership">+ Quota associativa</button> '
			. '<select id="aps-add-activity-select"><option value="">+ Mensilità attività…</option></select> '
			. '<select id="aps-add-other-select"><option value="">+ Altra voce…</option></select></p>';

		echo '<p class="aps-total">Totale: <strong id="aps-total">0,00 €</strong></p>';
		echo '<div id="aps-cash" class="aps-card"><h3>Contanti</h3>'
			. '<p><label>Contanti ricevuti <input type="text" id="aps-tendered" inputmode="decimal" placeholder="importo esatto"> €</label></p>'
			. '<p id="aps-quick"></p><p id="aps-change" class="aps-change"></p></div>';
		echo '<p><label>N. ricevuta (facoltativo) <input type="text" name="document_ref" maxlength="80"></label></p>';
		submit_button( 'Registra incasso' );
		Ui::form_close();
		Ui::footer();
	}
}
