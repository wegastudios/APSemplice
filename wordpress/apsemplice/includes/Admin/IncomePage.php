<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Incasso multi-voce (es. quota associativa + mensilità del corso) con calcolo del resto in contanti.
 * La parte dinamica è in assets/admin.js (blocco "apse-income").
 */
final class IncomePage {

	public static function register_ajax(): void {
		add_action( 'wp_ajax_apse_person_context', array( __CLASS__, 'ajax_context' ) );
	}

	/** Contesto di una persona per l'incasso: tessera, attività a cui è iscritta e primo mese da pagare. */
	public static function ajax_context(): void {
		check_ajax_referer( 'apse_income', 'nonce' );
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
				$acts[] = array( 'id' => (int) $a['id'], 'name' => $a['name'], 'fee' => Plugin::activities()->fee_for( $a, $person['type'] ), 'month' => $next ?: substr( $date, 0, 7 ) );
			}
		}
		// Eventi a cui è prenotata e il cui contributo non è ancora stato pagato
		$bookings = array();
		foreach ( Plugin::activities()->unpaid_bookings_for_person( (int) $person['id'] ) as $b ) {
			$bookings[] = array(
				'session_id'  => (int) $b['session_id'],
				'activity_id' => (int) $b['activity_id'],
				'label'       => $b['activity_name'] . ' · ' . ( new \DateTimeImmutable( $b['session_date'] ) )->format( 'd/m/Y' ),
				'amount'      => (int) $b['remaining'],
			);
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
				'bookings'         => $bookings,
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
		foreach ( array_filter( Plugin::activities()->for_year( $sy->label() ), function ( $x ) {
			return 'course' === $x['kind'];
		} ) as $a ) {
			$acts[] = array( 'id' => (int) $a['id'], 'name' => $a['name'], 'fee' => (int) $a['fee_cents'] );
		}
		$default_account = $ledger->default_account_for( 'cash' );
		$account_types = array();
		foreach ( $accounts as $a ) {
			$account_types[ (int) $a['id'] ] = $a['type'];
		}
		$data = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'apse_income' ),
			'categories'    => $cats,
			'activities'    => $acts,
			'membershipFee' => (int) Settings::get( 'membership_fee_cents' ),
			'socialYear'    => Settings::membership_year( $today )->label(),
			'nextYear'      => Settings::membership_year( $today )->next()->label(),
			'yearEnd'       => Settings::membership_year( $today )->end()->format( 'Y-m-d' ),
			'accountTypes'  => $account_types,
		);

		Ui::header( 'Nuovo incasso' );
		echo '<script type="application/json" id="apse-income-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_open( 'apse_save_income', Ui::url( 'apse-income' ), false, 'apse-income' );
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" id="apse-date" value="' . esc_attr( $today ) . '" required></td></tr>';
		echo '<tr><th>Conto</th><td>';
		$acc_map = array();
		foreach ( $accounts as $a ) {
			$acc_map[ $a['id'] ] = $a['name'];
		}
		echo '<select name="account_id" id="apse-account">' . Ui::options( $acc_map, $default_account ? $default_account['id'] : null ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Da chi</th><td>' . Ui::person_select( 'person_id', Plugin::people()->search(), Ui::get_int( 'person_id' ) ?: null, '— nessuno / anonimo —', 'apse-person-select' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' <a href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'ordinary' ) ) ) . '" target="_blank">+ nuovo socio</a>'
			. '<div id="apse-person-info" class="apse-info"></div></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Voci</h2><div id="apse-lines"></div>';
		echo '<p class="apse-addbar">'
			. '<button type="button" class="button" id="apse-add-membership">+ Quota associativa</button> '
			. '<select id="apse-add-activity-select"><option value="">+ Mensilità corso…</option></select> '
			. '<select id="apse-add-booking-select"><option value="">+ Contributo evento…</option></select> '
			. '<select id="apse-add-other-select"><option value="">+ Altra voce…</option></select></p>';

		echo '<p class="apse-total">Totale: <strong id="apse-total">0,00 €</strong></p>';
		echo '<div id="apse-cash" class="apse-card"><h3>Contanti</h3>'
			. '<p><label>Contanti ricevuti <input type="text" id="apse-tendered" inputmode="decimal" placeholder="importo esatto"> €</label></p>'
			. '<p id="apse-quick"></p><p id="apse-change" class="apse-change"></p></div>';
		echo '<p><label>N. ricevuta (facoltativo) <input type="text" name="document_ref" maxlength="80"></label></p>';
		submit_button( 'Registra incasso' );
		Ui::form_close();
		Ui::footer();
	}
}
