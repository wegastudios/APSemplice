<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

final class ActivitiesPage {

	public static function render_list(): void {
		$sy_start = Ui::get_int( 'year', Settings::social_year()->start_year );
		$year     = new SocialYear( $sy_start, Settings::start_month() );
		$report   = Plugin::reports()->social_year( $year );
		$back     = Ui::url( 'aps-activities', array( 'year' => $sy_start ) );

		Ui::header( 'Attività — anno sociale ' . $year->label() );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'aps-activities', array( 'year' => $sy_start - 1 ) ) ) . '">‹ ' . esc_html( $year->previous()->label() ) . '</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'aps-activities', array( 'year' => $sy_start + 1 ) ) ) . '">' . esc_html( $year->next()->label() ) . ' ›</a></p>';

		if ( ! $report['activities'] ) {
			echo '<p>Nessuna attività in questo anno sociale. Creane una qui sotto.</p>';
		}
		echo '<div class="aps-grid">';
		foreach ( $report['activities'] as $s ) {
			$a = $s['activity'];
			echo '<div class="aps-card"><h2><a href="' . esc_url( Ui::url( 'aps-activity', array( 'id' => $a['id'] ) ) ) . '">' . esc_html( $a['name'] ) . '</a></h2>';
			echo '<p class="description">Quota mensile ' . esc_html( Money::format( (int) $a['monthly_fee_cents'] ) ) . ' · ' . (int) $s['participants'] . ' iscritti attivi · '
				. ( $a['instructor_name'] ? 'tenuta da ' . esc_html( $a['instructor_name'] ) : 'senza istruttore' ) . '</p>';
			echo '<table class="aps-kv"><tr><td>Incassi</td><td>' . Ui::money( $s['income'] ) . '</td></tr><tr><td>Costi</td><td>' . Ui::money( $s['cost'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $s['margin'] ) . '</strong></td></tr></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		$volunteers = array_values( array_filter( Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) ) ) );
		echo '<div class="aps-card"><h2>Nuova attività</h2>';
		Ui::form_open( 'aps_save_activity', $back );
		echo Ui::hidden( 'social_year', $year->label() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Nome *</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Yoga"></td></tr>';
		echo '<tr><th>Quota mensile</th><td><input type="text" name="monthly_fee" inputmode="decimal" placeholder="0,00"> €</td></tr>';
		echo '<tr><th>Istruttore</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, null, '— nessuno —', 'aps-instructor' ) // phpcs:ignore WordPress.Security.EscapeOutput
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
		$year     = SocialYear::from_label( $activity['social_year'], Settings::start_month() );
		$back     = Ui::url( 'aps-activity', array( 'id' => $id ) );
		$statuses = $svc->status_for_activity( $id );
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

		Ui::header( $activity['name'], '<a class="page-title-action" href="' . esc_url( Ui::url( 'aps-activities', array( 'year' => $year->start_year ) ) ) . '">← Attività</a>' );
		echo '<div class="aps-cols"><div class="aps-col">';

		echo '<div class="aps-card"><h2>Riepilogo · anno sociale ' . esc_html( $activity['social_year'] ) . '</h2><table class="aps-kv">'
			. '<tr><td>Dovuto finora</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassato</td><td>' . Ui::money( $paid ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $to_collect ) . '</strong></td></tr></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="aps-card"><h2>Iscrivi un socio o un ospite</h2>';
		Ui::form_open( 'aps_enroll', $back );
		echo Ui::hidden( 'activity_id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		$enrolled_ids = array();
		foreach ( $statuses as $s ) {
			if ( null === $s['enrollment']['end_month'] ) {
				$enrolled_ids[] = (int) $s['enrollment']['person_id'];
			}
		}
		$candidates = array_values( array_filter( Plugin::people()->search(), function ( $p ) use ( $enrolled_ids ) {
			return ! in_array( (int) $p['id'], $enrolled_ids, true );
		} ) );
		echo '<p>' . Ui::person_select( 'person_id', $candidates, null, '— scegli —', 'aps-enroll-person' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Quota dovuta dal mese: <select name="start_month">' . Ui::month_options( $year->months(), $default_month ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Iscrivi', 'secondary' );
		Ui::form_close();
		echo '</div></div><div class="aps-col">';

		echo '<div class="aps-card"><h2>Dati dell\'attività</h2>';
		Ui::form_open( 'aps_save_activity', $back );
		echo Ui::hidden( 'id', $id ) . Ui::hidden( 'social_year', $activity['social_year'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		$volunteers = Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" value="' . esc_attr( $activity['name'] ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Quota mensile</th><td><input type="text" name="monthly_fee" value="' . esc_attr( Money::plain( (int) $activity['monthly_fee_cents'] ) ) . '"> €</td></tr>';
		echo '<tr><th>Istruttore</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, $activity['instructor_person_id'], '— nessuno —', 'aps-instructor' ) . '</td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Salva', 'secondary' );
		Ui::form_close();
		echo '</div></div></div>';

		echo '<h2>Iscritti e pagamenti</h2>';
		if ( ! $statuses ) {
			echo '<p>Nessun iscritto.</p>';
		}
		foreach ( $statuses as $s ) {
			$e      = $s['enrollment'];
			$active = null === $e['end_month'];
			$label  = trim( ( $e['card_number'] ? 'n.' . $e['card_number'] . ' · ' : '' ) . $e['first_name'] . ' ' . $e['last_name'] );
			echo '<details class="aps-detail"><summary><a href="' . esc_url( Ui::url( 'aps-person', array( 'id' => $e['person_id'] ) ) ) . '"><strong>' . esc_html( $label ) . '</strong></a> '
				. '<em>(' . esc_html( MemberType::label( $e['type'] ) ) . ')</em> — ' . Ui::pay_status( $s['summary'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $active ? '' : ' <span class="aps-warn">· cancellato dopo ' . esc_html( Ui::month( $e['end_month'] ) ) . '</span>' ) . '</summary>';
			echo Ui::months_table( $s['summary'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_open( $active ? 'aps_cancel_enrollment' : 'aps_enroll', $back );
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
		Ui::footer();
	}
}
