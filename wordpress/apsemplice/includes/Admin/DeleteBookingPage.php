<?php
namespace ApSemplice\Admin;

use ApSemplice\ActivityReset;
use ApSemplice\Money;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Cancellazione di una singola iscrizione (prenotazione o iscrizione a un corso), in due passaggi come l'eliminazione di un evento. Solo amministratori. */
final class DeleteBookingPage {

	public static function url( int $activity_id, int $person_id, int $session_id = 0, array $extra = array() ): string {
		return Ui::url( 'apse-booking-delete', array_merge( array( 'activity' => $activity_id, 'person' => $person_id, 'session' => $session_id ), $extra ) );
	}

	public static function render(): void {
		$aid = Ui::get_int( 'activity' );
		$pid = Ui::get_int( 'person' );
		$sid = Ui::get_int( 'session' );
		$p   = ActivityReset::registration_preview( $aid, $pid, $sid );
		Ui::header( 'Cancella l\'iscrizione' );
		if ( ! $p ) {
			echo '<p>L\'iscrizione non esiste (più).</p><p><a href="' . esc_url( Ui::url( 'apse-activities' ) ) . '">← Torna all\'elenco</a></p>';
			Ui::footer();
			return;
		}
		$back = Ui::url( 'apse-activity', array( 'id' => $aid ) );
		$who  = Plugin::people()->full_name( $p['person'] );
		$inc  = $p['income'];
		echo '<h2>' . esc_html( $who ) . ' — ' . esc_html( (string) $p['activity']['name'] ) . ( $p['session'] ? ' del ' . esc_html( Ui::date( $p['session']['session_date'] ) ) : '' ) . '</h2>';
		echo '<div class="notice notice-error inline"><p><strong>Operazione definitiva.</strong> L\'iscrizione (' . ( $p['session'] ? 'prenotazione a questa data' : 'iscrizione al corso, con le presenze' ) . ') viene cancellata e non si può ripristinare.</p></div>';
		echo '<h3>Soldi collegati all\'iscrizione</h3>';
		echo $inc['count'] ? '<p><strong>' . (int) $inc['count'] . ' incassi</strong> per ' . esc_html( Money::format( (int) $inc['cents'] ) ) . ( $inc['online_cents'] > 0 ? ' (di cui ' . esc_html( Money::format( (int) $inc['online_cents'] ) ) . ' pagati online)' : '' ) . '.</p>' : '<p>Nessun incasso registrato: la prima nota non cambia.</p>';

		if ( 0 === (int) $inc['count'] ) { // nessun incasso collegato: la prima nota non cambia, una sola conferma (il pulsante)
			Ui::form_open( 'apse_delete_booking', self::url( $aid, $pid, $sid ) );
			echo Ui::hidden( 'activity', $aid ) . Ui::hidden( 'person', $pid ) . Ui::hidden( 'session', $sid ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( is_email( (string) $p['person']['email'] ) ) {
				echo '<p><label><input type="checkbox" name="notify" value="1" checked> Avvisa ' . esc_html( $who ) . ' per email</label></p>';
			}
			echo '<p><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Cancella l\'iscrizione</button> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p>';
			Ui::form_close();
			Ui::footer();
			return;
		}
		if ( 2 !== Ui::get_int( 'step', 1 ) ) {
			echo '<form method="get"><input type="hidden" name="page" value="apse-booking-delete"><input type="hidden" name="activity" value="' . (int) $aid . '"><input type="hidden" name="person" value="' . (int) $pid . '"><input type="hidden" name="session" value="' . (int) $sid . '"><input type="hidden" name="step" value="2">';
			if ( $inc['count'] ) {
				echo '<h3>Cosa fare delle somme incassate</h3>'
					. '<p><label><input type="radio" name="mode" value="refund" checked> <strong>Restituisci le somme</strong></label><br><span class="description">Gli incassi restano in prima nota e per ciascuno si registra l\'uscita di restituzione (stesso conto e stessa modalità).</span></p>'
					. '<p><label><input type="radio" name="mode" value="void"> <strong>Annulla gli incassi, come se non fossero mai avvenuti</strong></label><br><span class="description">Spariscono da saldi, report, rendiconto e ricevute; restano tra gli annullamenti e nel registro azioni.</span></p>';
			}
			if ( is_email( (string) $p['person']['email'] ) ) {
				echo '<p><label><input type="checkbox" name="notify" value="1" checked> Avvisa ' . esc_html( $who ) . ' per email</label></p>';
			}
			echo '<p><button class="button button-primary">Continua</button> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p></form>';
			Ui::footer();
			return;
		}

		$mode    = 'void' === Ui::get_str( 'mode' ) ? ActivityReset::VOID : ActivityReset::REFUND;
		$notify  = '1' === Ui::get_str( 'notify' );
		$blocks  = ActivityReset::registration_blockers( $aid, $pid, $sid, $mode );
		echo '<div class="notice notice-warning inline" style="padding:12px 16px"><h3 style="margin-top:0">Cosa succede alla prima nota</h3>';
		if ( ! $inc['count'] ) {
			echo '<p>Nessun movimento da modificare: la prima nota non cambia.</p>';
		} elseif ( ActivityReset::REFUND === $mode ) {
			echo '<p><strong>Gli ' . (int) $inc['count'] . ' incassi (' . esc_html( Money::format( (int) $inc['cents'] ) ) . ') restano in prima nota</strong> e viene registrata, a oggi, <strong>un\'uscita di restituzione per ciascuno</strong> (sotto la voce «Restituzione quote»), sullo stesso conto e con la stessa modalità. Effetto netto sui saldi: zero.</p>'
				. '<p><strong>Il denaro non viene restituito dal sistema:</strong> va riconsegnato' . ( $inc['online_cents'] > 0 ? '; per i pagamenti online il rimborso va fatto a mano dal pannello del fornitore' : '' ) . '.</p>';
		} else {
			echo '<p><strong>Gli ' . (int) $inc['count'] . ' incassi (' . esc_html( Money::format( (int) $inc['cents'] ) ) . ') vengono ANNULLATI</strong>: <strong>spariscono da saldi, report, rendiconto e ricevute</strong>, come se non fossero mai avvenuti. Nessuna restituzione viene registrata e il denaro non viene restituito: il saldo dei conti scende di ' . esc_html( Money::format( (int) $inc['cents'] ) ) . '.</p>';
		}
		echo '</div>';
		if ( $blocks ) {
			echo '<div class="notice notice-error inline"><p><strong>Non si può procedere:</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $blocks as $b ) {
				echo '<li>' . esc_html( $b ) . '</li>';
			}
			echo '</ul></div><p><a class="button" href="' . esc_url( self::url( $aid, $pid, $sid ) ) . '">← Cambia scelta</a></p>';
			Ui::footer();
			return;
		}
		echo '<h3>Conferma</h3>';
		Ui::form_open( 'apse_delete_booking', self::url( $aid, $pid, $sid, array( 'step' => 2, 'mode' => $mode, 'notify' => $notify ? 1 : 0 ) ) );
		echo Ui::hidden( 'activity', $aid ) . Ui::hidden( 'person', $pid ) . Ui::hidden( 'session', $sid ) . Ui::hidden( 'mode', $mode ) . Ui::hidden( 'notify', $notify ? 1 : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label><input type="checkbox" name="confirm" value="1"> Ho letto e ho capito: la cancellazione è definitiva.</label></p>';
		echo '<p><label>Per confermare scrivi nome e cognome: <strong>' . esc_html( $who ) . '</strong><br><input type="text" name="typed" class="regular-text" autocomplete="off"></label></p>';
		echo '<p><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Cancella l\'iscrizione</button> <a class="button" href="' . esc_url( self::url( $aid, $pid, $sid ) ) . '">← Cambia scelta</a> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p>';
		Ui::form_close();
		Ui::footer();
	}
}
