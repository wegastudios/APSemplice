<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Labels;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Incasso multi-voce (es. quota associativa + mensilità del corso) con calcolo del resto in contanti.
 * La parte dinamica è in assets/admin.js (blocco "asem-income").
 */
final class IncomePage {

	public static function register_ajax(): void {
		add_action( 'wp_ajax_asem_person_context', array( __CLASS__, 'ajax_context' ) );
	}

	/** Contesto di una persona per l'incasso: tessera, attività a cui è iscritta e primo mese da pagare. */
	public static function ajax_context(): void {
		check_ajax_referer( 'asem_income', 'nonce' );
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_send_json_error( 'Non autorizzato', 403 );
		}
		$person = Plugin::people()->get( (int) ( $_POST['person_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- valore verificato e ripulito da chi lo usa
		$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm ) || ! checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] ) ) {
			$date = current_time( 'Y-m-d' ); // data mancante o non valida: oggi
		}
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
		// Tutto ciò che la persona deve ancora versare: mensilità dei corsi (con l'importo mancante) e contributi degli eventi
		$dues = array();
		foreach ( Plugin::activities()->status_for_person( (int) $person['id'] ) as $st ) {
			foreach ( $st['summary']['unpaid_months'] as $m ) {
				if ( (int) $m['missing'] > 0 ) {
					$dues[] = array( 'activity_id' => (int) $st['enrollment']['activity_id'], 'name' => $st['activity']['name'], 'month' => $m['month'], 'amount' => (int) $m['missing'] );
				}
			}
		}
		wp_send_json_success(
			array(
				'person'           => array( 'id' => (int) $person['id'], 'type' => $person['type'], 'type_label' => \AssociazioneSemplice\Levels::label( $person ) ),
				'is_guest'         => MemberType::GUEST === $person['type'],
				'is_founder'       => MemberType::is_auto_renewed( $person['type'] ),
				'active_until'     => $until,
				'needs_membership' => in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) && ( ! $until || $until < $date ),
				'social_year'      => $sy,
				'activities'       => $acts,
				'bookings'         => $bookings,
				'dues'             => $dues,
				'membership'       => Plugin::people()->membership_plan( (int) $person['id'], $date ),
				'suspended'        => Plugin::people()->is_suspended( (int) $person['id'] ),
				'membership_fee'   => \AssociazioneSemplice\Levels::fee_for( $person ),
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
			'nonce'         => wp_create_nonce( 'asem_income' ),
			'categories'    => $cats,
			'activities'    => $acts,
			'membershipFee' => (int) Settings::get( 'membership_fee_cents' ),
			'socialYear'    => Settings::membership_year( $today )->label(),
			'nextYear'      => Settings::membership_year( $today )->next()->label(),
			'yearEnd'       => Settings::membership_year( $today )->end()->format( 'Y-m-d' ),
			'autofill'      => 1 === Ui::get_int( 'due' ),
			'accountTypes'  => $account_types,
		);

		Ui::header( 'Nuovo incasso' );
		echo '<script type="application/json" id="asem-income-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_open( 'asem_save_income', Ui::url( 'asem-income' ), false, 'asem-income' );
		echo '<table class="form-table asem-form"><tbody>';
		echo '<tr><th>Data</th><td><input type="date" name="date" id="asem-date" value="' . esc_attr( $today ) . '" required></td></tr>';
		echo '<tr><th>Conto</th><td>';
		$acc_map = array();
		foreach ( $accounts as $a ) {
			$acc_map[ $a['id'] ] = $a['name'];
		}
		echo '<select name="account_id" id="asem-account">' . Ui::options( $acc_map, $default_account ? $default_account['id'] : null ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Da chi</th><td>' . Ui::person_select( 'person_id', Plugin::people()->search(), Ui::get_int( 'person_id' ) ?: null, '— nessuno / anonimo —', 'asem-person-select' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' <a href="' . esc_url( Ui::url( 'asem-person', array( 'type' => 'ordinary' ) ) ) . '" target="_blank">+ nuovo socio</a>'
			. '<div id="asem-person-info" class="asem-info"></div></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Voci</h2><div id="asem-lines"></div>';
		echo '<p class="asem-addbar">'
			. '<button type="button" class="button" id="asem-add-membership">+ Quota associativa</button> '
			. '<select id="asem-add-activity-select"><option value="">+ Mensilità corso…</option></select> '
			. '<select id="asem-add-booking-select"><option value="">+ Contributo evento…</option></select> '
			. '<select id="asem-add-other-select"><option value="">+ Altra voce…</option></select></p>';

		echo '<p class="asem-total">Totale: <strong id="asem-total">0,00 €</strong></p>';
		echo '<div id="asem-cash" class="asem-card"><h3>Contanti</h3>'
			. '<p><label>Contanti ricevuti <input type="text" id="asem-tendered" inputmode="decimal" placeholder="importo esatto"> €</label></p>'
			. '<p id="asem-quick"></p><p id="asem-change" class="asem-change"></p></div>';
		$vat = Ui::vat_row( null, \AssociazioneSemplice\Fiscal::default_mode(), 'Gli importi', true );
		if ( '' !== $vat ) {
			echo '<table class="form-table"><tbody>' . $vat . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><label>N. ricevuta (facoltativo) <input type="text" name="document_ref" maxlength="80"></label></p>';
		submit_button( 'Registra incasso' );
		Ui::form_close();
		Ui::footer();
	}
}
