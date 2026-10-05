<?php
namespace ApSemplice\Admin;

use ApSemplice\Calendar;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Calendario mensile di corsi ed eventi, con il collegamento a Google Calendar. */
final class CalendarPage {

	public static function render(): void {
		$month = Ui::get_str( 'm', substr( current_time( 'Y-m-d' ), 0, 7 ) );
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$month = substr( current_time( 'Y-m-d' ), 0, 7 );
		}
		$first = new \DateTimeImmutable( $month . '-01' );
		$prev  = $first->modify( '-1 month' )->format( 'Y-m' );
		$next  = $first->modify( '+1 month' )->format( 'Y-m' );
		$last  = $first->modify( 'last day of this month' );
		$grid0 = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );
		$items = array();
		foreach ( Calendar::occurrences( $grid0->format( 'Y-m-d' ), $grid0->modify( '+41 days' )->format( 'Y-m-d' ) ) as $o ) {
			$items[ $o['date'] ][] = $o;
		}
		$today = current_time( 'Y-m-d' );

		Ui::header( 'Calendario di corsi ed eventi' );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'apse-calendar', array( 'm' => $prev ) ) ) . '">‹</a> <strong style="margin:0 12px">' . esc_html( Ui::month( $month ) ) . '</strong> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-calendar', array( 'm' => $next ) ) ) . '">›</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-calendar' ) ) . '">Oggi</a></p>';
		echo '<table class="widefat apse-cal" style="table-layout:fixed"><thead><tr>';
		foreach ( array( 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom' ) as $d ) {
			echo '<th>' . esc_html( $d ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		for ( $w = 0; $w < 6; $w++ ) {
			$day0 = $grid0->modify( '+' . ( $w * 7 ) . ' days' );
			if ( $day0 > $last ) {
				break;
			}
			echo '<tr>';
			for ( $i = 0; $i < 7; $i++ ) {
				$day   = $day0->modify( '+' . $i . ' days' );
				$ymd   = $day->format( 'Y-m-d' );
				$other = $day->format( 'Y-m' ) !== $month;
				echo '<td style="vertical-align:top;height:90px;' . ( $other ? 'opacity:.45;' : '' ) . ( $ymd === $today ? 'background:#f0f6fc;' : '' ) . '"><strong>' . (int) $day->format( 'j' ) . '</strong>';
				foreach ( $items[ $ymd ] ?? array() as $o ) {
					$url = Ui::url( 'apse-activity', array( 'id' => $o['activity_id'] ) );
					echo '<div style="font-size:12px;margin-top:3px"><a href="' . esc_url( $url ) . '">' . ( $o['start'] ? esc_html( $o['start'] ) . ' ' : '' ) . esc_html( $o['title'] ) . '</a>'
						. ( $o['location'] ? '<br><span class="description">' . esc_html( $o['location'] ) . '</span>' : '' ) . '</div>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">I corsi compaiono ogni settimana nel giorno e all\'orario indicati nei loro dati (con le date di inizio e fine, se ci sono); gli eventi nelle loro date.</p>';

		self::google_card();
		Ui::footer();
	}

	private static function google_card(): void {
		$on = Calendar::enabled();
		echo '<div class="apse-card"><h2>Collegamento a Google Calendar</h2>';
		Ui::form_open( 'apse_save_ical', Ui::url( 'apse-calendar' ) );
		echo '<p><label><input type="checkbox" name="ical_enabled" value="1"' . checked( $on, true, false ) . '> <strong>Pubblica il calendario con un indirizzo segreto</strong></label><br>'
			. '<span class="description">Spento di default. L\'indirizzo contiene solo nomi, giorni, orari e luoghi di corsi ed eventi (nessun dato di persone), ma chiunque lo conosca può leggerlo.</span></p>';
		submit_button( 'Salva', 'primary', 'submit', false );
		Ui::form_close();
		if ( $on ) {
			echo '<p>Indirizzo del calendario:<br><input type="text" readonly class="large-text" value="' . esc_attr( Calendar::feed_url() ) . '" onclick="this.select()"></p>'
				. '<ol class="description"><li>In Google Calendar (da computer) apri <em>Altri calendari → + → Da URL</em>.</li><li>Incolla l\'indirizzo qui sopra e conferma.</li>'
				. '<li>Funziona anche con Apple Calendar, Outlook e Thunderbird (iscrizione a un calendario da indirizzo).</li></ol>'
				. '<p class="description">È un collegamento <strong>in sola lettura</strong>: le modifiche si fanno qui e Google le aggiorna da solo, di solito entro qualche ora (a volte fino a un giorno).</p>';
			Ui::form_open( 'apse_regen_ical', Ui::url( 'apse-calendar' ), false, 'apse-inline' );
			echo '<button class="button" data-confirm="Cambiare l\'indirizzo? Chi è già collegato smetterà di vedere gli aggiornamenti e dovrà usare il nuovo.">Cambia l\'indirizzo</button> <span class="description">Da usare se l\'indirizzo è stato diffuso per errore.</span>';
			Ui::form_close();
		}
		echo '</div>';
	}
}
