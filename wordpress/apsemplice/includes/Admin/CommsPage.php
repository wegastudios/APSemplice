<?php
namespace ApSemplice\Admin;

use ApSemplice\Plugin;
use ApSemplice\Privacy;
use ApSemplice\Reminders;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Promemoria, privacy e ricevute. */
final class CommsPage {

	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Promemoria, privacy e ricevute' );
		Ui::form_open( 'apse_save_comms', Ui::url( 'apse-comms' ) );
		echo '<h2>Promemoria per email</h2><p class="description">Ogni giorno il sito invia i promemoria ai soci. Sono disattivati finché non li attivi. Chi non ha un indirizzo email (un ospite) riceve il messaggio tramite il socio che lo ospita; ogni promemoria viene inviato una sola volta.</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Promemoria attivi</th><td><label><input type="checkbox" name="reminders_enabled" value="1"' . checked( ! empty( $s['reminders_enabled'] ), true, false ) . '> Invia i promemoria ogni giorno</label></td></tr>';
		echo '<tr><th>Tessera</th><td><label><input type="checkbox" name="reminders_membership" value="1"' . checked( ! empty( $s['reminders_membership'] ), true, false ) . '> Tessera in scadenza e scaduta da poco</label> '
			. '— avvisa <input type="number" min="1" max="120" name="reminders_membership_days" value="' . (int) $s['reminders_membership_days'] . '" style="width:70px"> giorni prima</td></tr>';
		echo '<tr><th>Mensilità dei corsi</th><td><label><input type="checkbox" name="reminders_dues" value="1"' . checked( ! empty( $s['reminders_dues'] ), true, false ) . '> Rinnovo dei corsi mensili</label> <span class="description">Dopo l\'ultima lezione del mese si ricorda a chi non ha ancora pagato il mese dopo (i corsi si rinnovano a inizio mese). Senza orari di lezione, dal 25 del mese. Un messaggio per persona, corso e mese.</span></td></tr>';
		echo '<tr><th>Eventi</th><td><label><input type="checkbox" name="reminders_events" value="1"' . checked( ! empty( $s['reminders_events'] ), true, false ) . '> Ricordo il giorno prima a chi è prenotato</label></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Privacy</h2><table class="form-table"><tbody>';
		echo '<tr><th>Pagina dell\'informativa</th><td><input type="url" name="privacy_url" value="' . esc_attr( (string) $s['privacy_url'] ) . '" class="regular-text" placeholder="https://…/privacy">'
			. '<p class="description">Se la indichi, chi attiva il proprio accesso deve accettarla e il consenso viene registrato. Per gli altri soci registri il consenso dalla loro scheda (modulo cartaceo, a voce…).</p></td></tr>';
		echo '<tr><th>Ex soci da anonimizzare</th><td>dopo <input type="number" min="1" max="30" name="privacy_retention_years" value="' . (int) $s['privacy_retention_years'] . '" style="width:70px"> anni di inattività<p class="description">Tempo oltre il quale proponi di togliere i dati personali di chi non partecipa più. Decidi tu caso per caso: i movimenti contabili restano.</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Ricevute</h2><table class="form-table"><tbody>';
		echo '<tr><th>Riga in fondo alla ricevuta</th><td><textarea name="receipt_footer" rows="2" class="large-text" maxlength="300">' . esc_textarea( (string) $s['receipt_footer'] ) . '</textarea>'
			. '<p class="description">Facoltativa: ad esempio il riferimento normativo che il tuo consulente ti indica per le quote associative. Le ricevute le trovi accanto a ogni incasso della Prima nota e nella scheda del socio; i soci le scaricano dalla loro area.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();

		// Promemoria: anteprima e invio manuale
		echo '<h2>Promemoria di oggi</h2>';
		$prev = Reminders::run( null, false );
		echo '<p>Da mandare adesso: <strong>' . (int) $prev['membership'] . '</strong> per la tessera · <strong>' . (int) $prev['dues'] . '</strong> per le mensilità · <strong>' . (int) $prev['events'] . '</strong> per gli eventi di domani. ';
		$last = Reminders::last_run();
		echo $last ? '<span class="description">Ultimo invio: ' . esc_html( mysql2date( 'd/m/Y H:i', $last['at'] ) ) . '.</span>' : '<span class="description">Nessun invio finora.</span>';
		echo '</p>';
		Ui::form_open( 'apse_reminders_run', Ui::url( 'apse-comms' ), false, 'apse-inline' );
		echo '<button class="button"' . ( Reminders::enabled() ? '' : ' disabled' ) . ' data-confirm="Mandare ora i promemoria?">Invia ora</button>';
		Ui::form_close();
		if ( ! Reminders::enabled() ) {
			echo ' <span class="description">Attiva i promemoria e salva per poterli inviare.</span>';
		}

		// Privacy: consensi e anonimizzazione
		$people = Plugin::people()->search( array( 'status' => 'noconsent' ) );
		echo '<h2>Consenso privacy</h2><p>Persone senza consenso registrato: <strong>' . count( $people ) . '</strong> '
			. '<a href="' . esc_url( Ui::url( 'apse-people', array( 'status' => 'noconsent' ) ) ) . '">vedi l\'elenco</a></p>';
		$cands = Privacy::retention_candidates();
		echo '<h2>Ex soci da anonimizzare (' . count( $cands ) . ')</h2>';
		if ( ! $cands ) {
			echo '<p class="description">Nessuno: non ci sono persone inattive da più di ' . (int) $s['privacy_retention_years'] . ' anni (o hanno ancora prenotazioni, iscrizioni o ospiti).</p>';
		} else {
			echo '<p class="description">Persone senza attività da oltre ' . (int) $s['privacy_retention_years'] . ' anni. «Anonimizza» toglie nome, contatti, codice fiscale, tessera e note; i movimenti contabili restano. Non si può annullare.</p><ul>';
			foreach ( $cands as $c ) {
				echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $c['id'] ) ) ) . '">' . esc_html( $c['name'] ) . '</a> <span class="description">ultima attività ' . esc_html( Ui::date( $c['last_seen'] ) ) . '</span> ';
				Ui::form_open( 'apse_privacy_anonymize', Ui::url( 'apse-comms' ), false, 'apse-inline' );
				echo Ui::hidden( 'id', $c['id'] ) . '<button class="button button-small" data-confirm="Anonimizzare ' . esc_attr( $c['name'] ) . '? I suoi dati personali vengono tolti e non si possono recuperare.">Anonimizza</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul>';
		}
		Ui::footer();
	}
}
