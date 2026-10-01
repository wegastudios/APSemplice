<?php
namespace ApSemplice\Admin;

use ApSemplice\ActivityKind;
use ApSemplice\CancelPolicy;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

final class ActivitiesPage {


	/** Righe del modulo: cancellabile e termine (solo per eventi ed eventi ricorrenti). */
	private static function cancel_rows( ?array $a, string $row_class ): string {
		$on      = $a && ! empty( $a['cancellable'] );
		$pol     = $a ? (string) $a['cancel_policy'] : '';
		$default = CancelPolicy::labels()[ Settings::get( 'cancel_policy_default' ) ] ?? '';
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Cancellazione</th><td><label><input type="checkbox" name="cancellable" value="1"' . checked( $on, true, false ) . '> Cancellabile anche se a pagamento</label>'
			. '<p>Termine: <select name="cancel_policy">' . Ui::options( array( '' => 'Predefinito (' . $default . ')' ) + CancelPolicy::labels(), $pol ) . '</select></p>'
			. '<p class="description">Gratuito: si può sempre annullare. A pagamento: non si annulla mai, ma si può cambiare nominativo (se il nuovo partecipante è un ospite con contributo maggiore si integra la differenza), a meno che l\'evento sia cancellabile entro il termine scelto.</p></td></tr>';
	}
	/** Riga del modulo: biglietto QR per le prenotazioni (solo eventi ed eventi ricorrenti, spento di default). */
	private static function qr_row( ?array $a, string $row_class ): string {
		$on = $a && ! empty( $a['booking_qr'] );
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Biglietto QR</th><td><label><input type="checkbox" name="booking_qr" value="1"' . checked( $on, true, false ) . '> Genera un QR per ogni prenotazione</label>'
			. '<p class="description">Chi prenota (e ogni suo ospite) trova nell\'area riservata un QR: scansionandolo all\'ingresso si vede subito se la prenotazione è valida, per quale data e se il contributo è stato versato. Facoltativo, scelta per singolo evento.</p></td></tr>';
	}

	/** "Contributo 5,00 € a evento · ospiti 8,00 €" oppure "Gratuito". */
	private static function fee_text( array $a ): string {
		$fee   = (int) $a['fee_cents'];
		$guest = null === $a['guest_fee_cents'] || '' === $a['guest_fee_cents'] ? null : (int) $a['guest_fee_cents'];
		$unit  = ActivityKind::fee_unit( $a['kind'] );
		$txt   = 0 === $fee ? 'Gratuito per i soci' : 'Soci ' . Money::format( $fee ) . ' ' . $unit;
		if ( null !== $guest ) {
			$txt .= ' · ' . ( 0 === $guest ? 'ospiti: gratuito' : 'ospiti ' . Money::format( $guest ) . ' ' . $unit );
		} elseif ( $fee > 0 ) {
			$txt .= ' · ospiti: uguale';
		}
		return $txt;
	}

	public static function render_list(): void {
		$sy_start = Ui::get_int( 'year', Settings::social_year()->start_year );
		$year     = new SocialYear( $sy_start, Settings::start_month() );
		$report   = Plugin::reports()->social_year( $year );
		$back     = Ui::url( 'apse-activities', array( 'year' => $sy_start ) );
		$today    = current_time( 'Y-m-d' );

		Ui::header( 'Attività — anno sociale ' . $year->label() );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'apse-activities', array( 'year' => $sy_start - 1 ) ) ) . '">‹ ' . esc_html( $year->previous()->label() ) . '</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-activities', array( 'year' => $sy_start + 1 ) ) ) . '">' . esc_html( $year->next()->label() ) . ' ›</a></p>';

		if ( ! $report['activities'] ) {
			echo '<p>Nessuna attività in questo anno sociale. Creane una qui sotto.</p>';
		}
		echo '<div class="apse-grid">';
		foreach ( $report['activities'] as $s ) {
			$a = $s['activity'];
			echo '<div class="apse-card"><p class="description" style="margin-bottom:0">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</p>';
			echo '<h2 style="margin-top:2px"><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $a['id'] ) ) ) . '">' . esc_html( $a['name'] ) . '</a></h2>';
			echo '<p class="description">' . esc_html( self::fee_text( $a ) ) . '<br>' . (int) $s['participants'] . ' partecipanti · '
				. ( $a['instructor_name'] ? 'tenuta da ' . esc_html( $a['instructor_name'] ) : 'senza istruttore' ) . '</p>';
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = Plugin::activities()->sessions( (int) $a['id'] );
				$next     = null;
				foreach ( $sessions as $x ) {
					if ( empty( $x['cancelled_at'] ) && $x['session_date'] >= $today ) {
						$next = $x;
						break;
					}
				}
				echo '<p class="description">' . count( $sessions ) . ( 1 === count( $sessions ) ? ' data' : ' date' ) . ( $next ? ' · prossima: ' . Ui::date( $next['session_date'] ) . ( $next['start_time'] ? ' ore ' . esc_html( $next['start_time'] ) : '' ) : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<table class="apse-kv"><tr><td>Incassi</td><td>' . Ui::money( $s['income'] ) . '</td></tr><tr><td>Costi</td><td>' . Ui::money( $s['cost'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $s['margin'] ) . '</strong></td></tr></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		$volunteers = Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="apse-card"><h2>Nuova attività</h2>';
		Ui::form_open( 'apse_save_activity', $back );
		echo Ui::hidden( 'social_year', $year->label() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Tipo *</th><td><select name="kind" id="apse-kind">' . Ui::options( ActivityKind::labels(), ActivityKind::COURSE ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description" id="apse-kind-hint"></p></td></tr>';
		echo '<tr><th>Nome *</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Yoga, Serata di giochi"></td></tr>';
		echo '<tr class="apse-row-event"><th>Data *</th><td><input type="date" name="session_date"> ore <input type="time" name="start_time"></td></tr>';
		echo '<tr class="apse-row-event"><th>Luogo</th><td><input type="text" name="location" class="regular-text"></td></tr>';
		echo '<tr class="apse-row-event"><th>Posti disponibili</th><td><input type="number" min="1" name="capacity" class="small-text"> <span class="description">vuoto = nessun limite</span></td></tr>';
		echo self::cancel_rows( null, 'apse-row-sessions' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::qr_row( null, 'apse-row-sessions' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th><span class="apse-fee-label">Contributo soci</span></th><td><input type="text" name="fee" inputmode="decimal" placeholder="0,00"> € <span class="description">0 o vuoto = gratuito</span></td></tr>';
		echo '<tr><th>Contributo ospiti</th><td><input type="text" name="guest_fee" inputmode="decimal" placeholder="uguale ai soci"> € <span class="description">vuoto = come i soci · 0 = gratuito per gli ospiti</span></td></tr>';
		echo '<tr><th>Istruttore</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, null, '— nessuno —', 'apse-instructor' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">Le attività possono essere tenute solo da soci e volontari.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Crea attività' );
		Ui::form_close();
		echo '</div>';
		Ui::footer();
	}

	public static function render_detail(): void {
		$id       = Ui::get_int( 'id' );
		$svc      = Plugin::activities();
		$activity = $svc->get( $id );
		if ( ! $activity ) {
			wp_die( 'Attività non trovata.' );
		}
		$year = SocialYear::from_label( $activity['social_year'], Settings::start_month() );
		$back = Ui::url( 'apse-activity', array( 'id' => $id ) );

		Ui::header( $activity['name'], '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-activities', array( 'year' => $year->start_year ) ) ) . '">← Attività</a>' );
		echo '<p class="description">' . esc_html( ActivityKind::labels()[ $activity['kind'] ] ?? $activity['kind'] ) . ' · ' . esc_html( self::fee_text( $activity ) ) . '</p>';
		echo '<div class="apse-cols"><div class="apse-col">';
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			self::sessions_left( $activity, $back );
		} else {
			self::course_left( $activity, $year, $back );
		}
		echo '</div><div class="apse-col">';
		self::edit_card( $activity, $back );
		echo '</div></div>';

		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			self::sessions_list( $activity, $back );
		} else {
			self::course_enrolled( $activity, $year, $back );
		}
		Ui::footer();
	}

	private static function edit_card( array $activity, string $back ): void {
		$volunteers = Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="apse-card"><h2>Dati dell\'attività</h2>';
		Ui::form_open( 'apse_save_activity', $back );
		echo Ui::hidden( 'id', $activity['id'] ) . Ui::hidden( 'social_year', $activity['social_year'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" value="' . esc_attr( $activity['name'] ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Contributo soci (' . esc_html( ActivityKind::fee_unit( $activity['kind'] ) ) . ')</th><td><input type="text" name="fee" value="' . esc_attr( Money::plain( (int) $activity['fee_cents'] ) ) . '"> €</td></tr>';
		$guest = null === $activity['guest_fee_cents'] ? '' : Money::plain( (int) $activity['guest_fee_cents'] );
		echo '<tr><th>Contributo ospiti</th><td><input type="text" name="guest_fee" value="' . esc_attr( $guest ) . '" placeholder="uguale ai soci"> €<p class="description">Vuoto = come i soci · 0 = gratuito. Vale per le nuove prenotazioni/mensilità: quelle già fatte tengono l\'importo di allora (eventi) o seguono la nuova quota (corsi).</p></td></tr>';
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			echo self::cancel_rows( $activity, '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::qr_row( $activity, '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><th>Istruttore</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, $activity['instructor_person_id'], '— nessuno —', 'apse-instructor' ) . '</td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Salva', 'secondary' );
		Ui::form_close();
		echo '</div>';
	}

	// ---------- Corsi ----------

	private static function course_left( array $activity, SocialYear $year, string $back ): void {
		$id       = (int) $activity['id'];
		$statuses = Plugin::activities()->status_for_activity( $id );
		$due      = array_sum( array_map( function ( $s ) {
			return $s['summary']['total_due'];
		}, $statuses ) );
		$paid     = array_sum( array_map( function ( $s ) {
			return $s['summary']['total_paid'];
		}, $statuses ) );
		$to_collect = array_sum( array_map( function ( $s ) {
			return max( 0, -$s['summary']['balance'] );
		}, $statuses ) );
		$default_month = $year->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );

		echo '<div class="apse-card"><h2>Riepilogo · anno sociale ' . esc_html( $activity['social_year'] ) . '</h2><table class="apse-kv">'
			. '<tr><td>Dovuto finora</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassato</td><td>' . Ui::money( $paid ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $to_collect ) . '</strong></td></tr></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$enrolled_ids = array();
		foreach ( $statuses as $s ) {
			if ( null === $s['enrollment']['end_month'] ) {
				$enrolled_ids[] = (int) $s['enrollment']['person_id'];
			}
		}
		$candidates = array_values( array_filter( Plugin::people()->search(), function ( $p ) use ( $enrolled_ids ) {
			return ! in_array( (int) $p['id'], $enrolled_ids, true );
		} ) );
		echo '<div class="apse-card"><h2>Iscrivi un socio o un ospite</h2>';
		Ui::form_open( 'apse_enroll', $back );
		echo Ui::hidden( 'activity_id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>' . Ui::person_select( 'person_id', $candidates, null, '— scegli —', 'apse-enroll-person' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Quota dovuta dal mese: <select name="start_month">' . Ui::month_options( $year->months(), $default_month ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Iscrivi', 'secondary' );
		Ui::form_close();
		echo '</div>';
	}

	private static function course_enrolled( array $activity, SocialYear $year, string $back ): void {
		$id            = (int) $activity['id'];
		$statuses      = Plugin::activities()->status_for_activity( $id );
		$default_month = $year->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );
		echo '<h2>Iscritti e pagamenti</h2>';
		if ( ! $statuses ) {
			echo '<p>Nessun iscritto.</p>';
		}
		foreach ( $statuses as $s ) {
			$e      = $s['enrollment'];
			$active = null === $e['end_month'];
			$label  = trim( ( $e['card_number'] ? 'n.' . $e['card_number'] . ' · ' : '' ) . $e['first_name'] . ' ' . $e['last_name'] );
			echo '<details class="apse-detail"><summary><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $e['person_id'] ) ) ) . '"><strong>' . esc_html( $label ) . '</strong></a> '
				. '<em>(' . esc_html( MemberType::label( $e['type'] ) ) . ')</em> — ' . Ui::pay_status( $s['summary'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $active ? '' : ' <span class="apse-warn">· cancellato dopo ' . esc_html( Ui::month( $e['end_month'] ) ) . '</span>' ) . '</summary>';
			echo Ui::months_table( $s['summary'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_open( $active ? 'apse_cancel_enrollment' : 'apse_enroll', $back );
			echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $e['person_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $active ) {
				echo '<p>Ultimo mese dovuto: <select name="last_month">' . Ui::month_options( $year->months(), $default_month ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<button class="button">Cancella dall\'attività</button></p><p class="description">I mesi successivi non saranno più dovuti. I pagamenti già fatti restano registrati.</p>';
			} else {
				echo '<p>Riattiva dal mese: <select name="start_month">' . Ui::month_options( $year->months(), $default_month ) . '</select> <button class="button">Riattiva iscrizione</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			Ui::form_close();
			echo '</details>';
		}
	}

	// ---------- Eventi (una tantum e ricorrenti) ----------

	private static function sessions_left( array $activity, string $back ): void {
		$id       = (int) $activity['id'];
		$sessions = Plugin::activities()->sessions( $id );
		$due      = 0;
		$paid     = 0;
		foreach ( $sessions as $s ) {
			if ( ! empty( $s['cancelled_at'] ) ) {
				continue;
			}
			foreach ( Plugin::activities()->bookings_for_session( (int) $s['id'] ) as $b ) {
				if ( $b['active'] ) {
					$due  += (int) $b['fee_due_cents'];
					$paid += min( $b['paid'], (int) $b['fee_due_cents'] );
				}
			}
		}
		echo '<div class="apse-card"><h2>Riepilogo</h2><table class="apse-kv">'
			. '<tr><td>Date</td><td>' . count( $sessions ) . '</td></tr><tr><td>Partecipanti (persone distinte)</td><td>' . (int) Plugin::activities()->active_participants( $id ) . '</td></tr>'
			. '<tr><td>Contributi dovuti dalle prenotazioni</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassati</td><td>' . Ui::money( $paid ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $due - $paid ) . '</strong></td></tr></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		self::staff_card( $activity, $back );

		if ( ActivityKind::RECURRING === $activity['kind'] ) {
			echo '<div class="apse-card"><h2>Aggiungi date</h2>';
			Ui::form_open( 'apse_add_session', $back );
			echo Ui::hidden( 'activity_id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p>Una data: <input type="date" name="session_date" required> ore <input type="time" name="start_time"> luogo <input type="text" name="location"> posti <input type="number" min="1" name="capacity" class="small-text"> '
				. '<button class="button">Aggiungi</button></p>';
			Ui::form_close();
			Ui::form_open( 'apse_generate_sessions', $back );
			echo Ui::hidden( 'activity_id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><strong>Ogni settimana</strong> dal <input type="date" name="from" required> al <input type="date" name="to" required> ore <input type="time" name="start_time"> '
				. 'luogo <input type="text" name="location"> posti <input type="number" min="1" name="capacity" class="small-text"> <button class="button">Genera date</button></p>'
				. '<p class="description">Una data ogni 7 giorni (massimo 120). Le date già presenti non vengono duplicate.</p>';
			Ui::form_close();
			echo '</div>';
		}
	}

	/** Prenotazione sul posto: chi si presenta senza aver prenotato. Prenota, incassa il contributo e registra l'ingresso in un colpo solo. */
	private static function walk_in_form( array $activity, array $s, array $candidates, string $back ): void {
		$ledger   = Plugin::ledger();
		$accounts = array();
		foreach ( $ledger->balances() as $a ) {
			$accounts[ (int) $a['id'] ] = $a['name'];
		}
		$default = $ledger->default_account_for( 'cash' );
		$members = array_values( array_filter( Plugin::people()->search(), function ( $p ) {
			return MemberType::is_member( $p['type'] );
		} ) );
		$sid   = (int) $s['id'];
		$fee   = (int) $activity['fee_cents'];
		$guest = null === $activity['guest_fee_cents'] || '' === $activity['guest_fee_cents'] ? $fee : (int) $activity['guest_fee_cents'];
		echo '<details class="apse-detail" style="margin-top:8px"><summary><strong>Ingresso senza prenotazione (sul posto)</strong></summary>';
		Ui::form_open( 'apse_walk_in', $back );
		echo Ui::hidden( 'activity_id', $activity['id'] ) . Ui::hidden( 'session_id', $sid ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Persona già in anagrafica: ' . Ui::person_select( 'person_id', $candidates, null, '— scegli socio o ospite —', 'apse-walk-' . $sid ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><strong>oppure</strong> nuovo ospite: nome <input type="text" name="new_first_name"> cognome <input type="text" name="new_last_name"> del socio '
			. Ui::person_select( 'host_person_id', $members, null, '— socio che lo ospita —', 'apse-walkhost-' . $sid ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><label><input type="checkbox" name="pay" value="1" checked> Incassa ora il contributo</label> (soci ' . esc_html( Money::format( $fee ) ) . ', ospiti ' . esc_html( Money::format( $guest ) ) . ') — '
			. '<select name="method">' . Ui::options( array_diff_key( \ApSemplice\Labels::methods(), array( 'stripe' => 1, 'paypal' => 1 ) ), 'cash' ) . '</select> sul conto '
			. '<select name="account_id">' . Ui::options( $accounts, $default ? (int) $default['id'] : null ) . '</select></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><label><input type="checkbox" name="checkin" value="1" checked> Registra subito l\'ingresso</label> <button class="button button-primary">Prenota sul posto</button></p>'
			. '<p class="description">Il nuovo ospite viene creato (senza email) e collegato al socio che lo ospita. L\'incasso entra in prima nota, sul conto scelto, con la data di oggi.</p>';
		Ui::form_close();
		echo '</details>';
	}

	/** Cella "Ingresso" di una prenotazione: ora di ingresso e pulsante per registrarlo o annullarlo. */
	private static function checkin_cell( array $b, int $session_id, int $activity_id, string $back, bool $session_cancelled ): void {
		if ( ! $b['active'] || $session_cancelled ) {
			echo '—';
			return;
		}
		$in = ! empty( $b['checked_in_at'] );
		echo $in ? '<strong class="apse-ok">✔ ' . esc_html( mysql2date( 'H:i', $b['checked_in_at'] ) ) . '</strong> ' : '';
		Ui::form_open( 'apse_checkin', $back, false, 'apse-inline' );
		echo Ui::hidden( 'activity_id', $activity_id ) . Ui::hidden( 'session_id', $session_id ) . Ui::hidden( 'person_id', $b['person_id'] ) . ( $in ? Ui::hidden( 'undo', 1 ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<button class="button button-small">' . ( $in ? 'Annulla' : 'Registra ingresso' ) . '</button>';
		Ui::form_close();
	}

	/** Soci abilitati a gestire l'evento (lista prenotati e registrazione ingressi dall'area riservata), oltre all'istruttore. */
	private static function staff_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$svc   = Plugin::activities();
		$staff = $svc->staff( $id );
		$in    = array();
		echo '<div class="apse-card"><h2>Gestori dell\'evento</h2>';
		echo '<p class="description">Vedono i prenotati e registrano gli ingressi (anche scansionando il QR) dall\'area riservata, solo per questo evento. L\'istruttore e gli amministratori lo possono già fare.</p>';
		if ( $staff ) {
			echo '<ul>';
			foreach ( $staff as $m ) {
				$in[] = (int) $m['person_id'];
				echo '<li>' . esc_html( $m['first_name'] . ' ' . $m['last_name'] ) . ' <span class="description">' . esc_html( MemberType::label( $m['type'] ) ) . '</span> ';
				Ui::form_open( 'apse_event_staff_remove', $back, false, 'apse-inline' );
				echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $m['person_id'] ) . '<button class="button-link" data-confirm="Togliere questo gestore?">togli</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul>';
		}
		$candidates = array_values( array_filter( Plugin::people()->search(), function ( $p ) use ( $in, $activity ) {
			return MemberType::is_member( $p['type'] ) && ! in_array( (int) $p['id'], $in, true ) && (int) $p['id'] !== (int) $activity['instructor_person_id'];
		} ) );
		Ui::form_open( 'apse_event_staff_add', $back );
		echo Ui::hidden( 'activity_id', $id ) . Ui::person_select( 'person_id', $candidates, null, '— scegli un socio —', 'apse-staff-' . $id ) . ' <button class="button">Aggiungi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		echo '</div>';
	}

	private static function sessions_list( array $activity, string $back ): void {
		$id       = (int) $activity['id'];
		$sessions = Plugin::activities()->sessions( $id );
		$all      = Plugin::people()->search();
		echo '<h2>' . ( ActivityKind::EVENT === $activity['kind'] ? 'Data e prenotazioni' : 'Date e prenotazioni' ) . '</h2>';
		if ( ! $sessions ) {
			echo '<p>Nessuna data. ' . ( ActivityKind::RECURRING === $activity['kind'] ? 'Aggiungine qui sopra.' : '' ) . '</p>';
		}
		foreach ( $sessions as $s ) {
			$cancelled = ! empty( $s['cancelled_at'] );
			$bookings  = Plugin::activities()->bookings_for_session( (int) $s['id'] );
			$gcounts   = Plugin::activities()->participation_counts( array_column( array_filter( $bookings, function ( $x ) {
				return MemberType::GUEST === $x['type'] && $x['active'];
			} ), 'person_id' ) );
			$glimit    = Plugin::activities()->guest_limit();
			$booked    = array();
			foreach ( $bookings as $b ) {
				if ( $b['active'] ) {
					$booked[] = (int) $b['person_id'];
				}
			}
			$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
			$title = Ui::date( $s['session_date'] ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . ( $s['location'] ? ' · ' . esc_html( $s['location'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<details class="apse-detail"' . ( ActivityKind::EVENT === $activity['kind'] ? ' open' : '' ) . '><summary><strong>' . $title . '</strong> — ' // phpcs:ignore WordPress.Security.EscapeOutput
				. count( $booked ) . ( null === $cap ? ' prenotati' : ' / ' . $cap . ' posti' ) . ( $cancelled ? ' <span class="apse-neg">· ANNULLATA</span>' : '' ) . '</summary>';

			if ( $bookings ) {
				echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>Tipo</th><th>Contributo</th><th>Stato</th><th>Ingresso</th><th></th></tr></thead><tbody>';
				foreach ( $bookings as $b ) {
					$label = trim( ( $b['card_number'] ? 'n.' . $b['card_number'] . ' · ' : '' ) . $b['first_name'] . ' ' . $b['last_name'] );
					echo '<tr><td><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $b['person_id'] ) ) ) . '">' . esc_html( $label ) . '</a></td><td>' . esc_html( MemberType::label( $b['type'] ) ) . ( MemberType::GUEST === $b['type'] && $b['active'] ? '<br>' . PeoplePage::guest_badge( (int) ( $gcounts[ (int) $b['person_id'] ] ?? 0 ), $glimit ) : '' ) . '</td>'
						. '<td>' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . '</td><td>' . ( $b['active'] ? Ui::booking_state( $b ) : ( 'transferred' === $b['status'] ? '<span class="apse-warn">trasferita ad altra persona' : '<span class="apse-warn">prenotazione annullata' ) . ( $b['paid'] > 0 ? ' · versati ' . esc_html( Money::format( $b['paid'] ) ) . ' da rimborsare' : '' ) . '</span>' ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
					self::checkin_cell( $b, (int) $s['id'], $id, $back, $cancelled );
					echo '</td><td>';
					if ( $b['active'] ) {
						Ui::form_open( 'apse_cancel_booking', $back, false, 'apse-confirm' );
						echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						echo '<button class="button button-small" data-confirm="Annullare la prenotazione? Gli eventuali pagamenti restano registrati.">Annulla prenotazione</button>';
						Ui::form_close();
						$others = array_values( array_filter( $all, function ( $x ) use ( $b, $booked ) {
							return (int) $x['id'] !== (int) $b['person_id'] && ! in_array( (int) $x['id'], $booked, true );
						} ) );
						echo '<details style="margin-top:6px"><summary>Cambia nominativo</summary>';
						Ui::form_open( 'apse_transfer_booking', $back );
						echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						echo Ui::person_select( 'to_person_id', $others, null, '— scegli la nuova persona —', 'apse-tr-' . (int) $s['id'] . '-' . (int) $b['person_id'] ) . ' <button class="button button-small">Cambia</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
						echo '<p class="description">Il pagamento già fatto passa alla nuova persona; se il suo contributo è maggiore (es. ospite) resta da pagare la differenza.</p>';
						Ui::form_close();
						echo '</details>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}

			if ( ! $cancelled ) {
				$candidates = array_values( array_filter( $all, function ( $p ) use ( $booked ) {
					return ! in_array( (int) $p['id'], $booked, true );
				} ) );
				Ui::form_open( 'apse_book', $back );
				echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p>Prenota: ' . Ui::person_select( 'person_id', $candidates, null, '— scegli socio o ospite —', 'apse-book-' . (int) $s['id'] ) . ' <button class="button button-primary">Prenota</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				self::walk_in_form( $activity, $s, $candidates, $back );

				Ui::form_open( 'apse_update_session', $back );
				echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p class="description">Modifica la data: <input type="date" name="session_date" value="' . esc_attr( $s['session_date'] ) . '"> ore <input type="time" name="start_time" value="' . esc_attr( (string) $s['start_time'] ) . '"> '
					. 'luogo <input type="text" name="location" value="' . esc_attr( (string) $s['location'] ) . '"> posti <input type="number" min="1" name="capacity" class="small-text" value="' . esc_attr( null === $cap ? '' : (string) $cap ) . '"> <button class="button button-small">Salva</button></p>';
				Ui::form_close();

				Ui::form_open( 'apse_cancel_session', $back, false, 'apse-confirm' );
				echo Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<button class="button button-link-delete" data-confirm="Annullare questa data? Le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano.">Annulla questa data</button>';
				Ui::form_close();
			}
			echo '</details>';
		}
	}
}
