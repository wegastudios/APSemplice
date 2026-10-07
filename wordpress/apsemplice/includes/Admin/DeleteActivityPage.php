<?php
namespace ApSemplice\Admin;

use ApSemplice\ActivityReset;
use ApSemplice\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Eliminazione completa di un evento, in due passaggi: 1) cosa fare delle somme incassate; 2) riepilogo e conferma scritta.
 * Solo amministratori.
 */
final class DeleteActivityPage {

	/** Parametri del passaggio 2, validati. @return array{0:string,1:bool,2:bool} modalità, avvisa, annulla le spese */
	private static function choices(): array {
		$mode = Ui::get_str( 'mode', ActivityReset::REFUND );
		$mode = ActivityReset::VOID === $mode ? ActivityReset::VOID : ActivityReset::REFUND;
		return array( $mode, '1' === Ui::get_str( 'notify' ), '1' === Ui::get_str( 'void_costs' ) );
	}

	public static function review_url( int $id, string $mode, bool $notify, bool $void_costs ): string {
		return Ui::url( 'apse-activity-delete', array( 'id' => $id, 'step' => 2, 'mode' => $mode, 'notify' => $notify ? 1 : 0, 'void_costs' => $void_costs ? 1 : 0 ) );
	}

	public static function render(): void {
		$id = Ui::get_int( 'id' );
		$p  = ActivityReset::preview( $id );
		Ui::header( 'Elimina l\'evento' );
		if ( ! $p ) {
			echo '<p>L\'evento non esiste (più).</p><p><a href="' . esc_url( Ui::url( 'apse-activities' ) ) . '">← Torna all\'elenco</a></p>';
			Ui::footer();
			return;
		}
		$a    = $p['activity'];
		$back = Ui::url( 'apse-activity', array( 'id' => $id ) );
		echo '<h2>' . esc_html( $a['name'] ) . '</h2>';
		echo '<div class="notice notice-error inline"><p><strong>Operazione definitiva.</strong> L\'eliminazione cancella l\'evento e tutti i suoi dati e non si può annullare. Prima scarica una <a href="' . esc_url( Ui::url( 'apse-backup' ) ) . '">copia di sicurezza</a>.</p></div>';

		echo '<h3>Cosa verrà eliminato</h3><ul style="list-style:disc;margin-left:20px">'
			. '<li>' . (int) $p['sessions'] . ( 1 === $p['sessions'] ? ' data' : ' date' ) . ' e tutte le prenotazioni (' . (int) $p['bookings'] . ' attive), la lista d\'attesa e le presenze</li>'
			. '<li>' . (int) $p['enrollments'] . ' iscrizioni in corso e ' . (int) $p['staff'] . ' persone dello staff</li>'
			. '<li>' . (int) $p['notices'] . ' avvisi inviati e i collegamenti (WooCommerce, pagine riservate)</li>'
			. ( $p['fund_cents'] > 0 ? '<li>le quote accantonate per il rimborso del referente (' . esc_html( Money::format( (int) $p['fund_cents'] ) ) . ')</li>' : '' )
			. '</ul>';

		echo '<h3>Soldi collegati all\'evento</h3>';
		if ( ! $p['income']['count'] && ! $p['expense']['count'] ) {
			echo '<p>Nessun incasso né spesa registrati per questo evento: la prima nota non cambia.</p>';
		} else {
			echo '<ul style="list-style:disc;margin-left:20px"><li><strong>' . (int) $p['income']['count'] . ' incassi</strong> per ' . esc_html( Money::format( (int) $p['income']['cents'] ) )
				. ( $p['income']['online_cents'] > 0 ? ' (di cui ' . esc_html( Money::format( (int) $p['income']['online_cents'] ) ) . ' pagati online)' : '' ) . '</li>'
				. '<li>' . (int) $p['expense']['count'] . ' spese per ' . esc_html( Money::format( (int) $p['expense']['cents'] ) ) . '</li></ul>';
		}

		if ( ActivityReset::activity_is_free( $id ) ) { // nessun movimento in prima nota: una sola conferma (il pulsante)
			Ui::form_open( 'apse_delete_activity', $back );
			echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $p['recipients'] > 0 ) {
				echo '<p><label><input type="checkbox" name="notify" value="1" checked> Avvisa per email le ' . (int) $p['recipients'] . ' persone prenotate o iscritte con un indirizzo email</label></p>';
			}
			echo '<p><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Elimina l\'evento</button> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p>';
			Ui::form_close();
			Ui::footer();
			return;
		}
		$step = Ui::get_int( 'step', 1 );
		if ( 2 === $step ) {
			self::review( $p, $back );
		} else {
			self::choose( $p, $back );
		}
		Ui::footer();
	}

	private static function choose( array $p, string $back ): void {
		$id = (int) $p['activity']['id'];
		echo '<form method="get"><input type="hidden" name="page" value="apse-activity-delete"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="step" value="2">';
		if ( $p['income']['count'] ) {
			echo '<h3>Cosa fare delle somme incassate</h3>';
			echo '<p><label><input type="radio" name="mode" value="refund" checked> <strong>Restituisci le somme</strong></label><br><span class="description">Gli incassi restano in prima nota e per ciascuno si registra l\'uscita di restituzione (stesso conto e stessa modalità). Il denaro va restituito davvero a chi ha pagato.</span></p>';
			echo '<p><label><input type="radio" name="mode" value="void"> <strong>Annulla gli incassi, come se non fossero mai avvenuti</strong></label><br><span class="description">Gli incassi vengono annullati: spariscono da saldi, report e rendiconto (restano nell\'elenco degli annullamenti e nel registro azioni). Nessuna restituzione viene registrata.</span></p>';
			echo '<p style="margin-left:24px"><label><input type="checkbox" name="void_costs" value="1"> Annulla anche le spese registrate per questo evento <span class="description">(solo con «Annulla gli incassi»; di norma le spese sono costi veri e restano)</span></label></p>';
		}
		if ( $p['recipients'] > 0 ) {
			echo '<p><label><input type="checkbox" name="notify" value="1" checked> Avvisa per email le ' . (int) $p['recipients'] . ' persone prenotate o iscritte con un indirizzo email</label></p>';
		}
		echo '<p><button class="button button-primary">Continua</button> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p></form>';
	}

	private static function review( array $p, string $back ): void {
		$id                       = (int) $p['activity']['id'];
		list( $mode, $notify, $void_costs ) = self::choices();
		$blockers                 = ActivityReset::blockers( $id, $mode, $void_costs );
		$inc                      = ActivityReset::REFUND === $mode ? array_merge( $p['income'], $p['income_open'] ) : $p['income']; // si restituisce solo ciò che non è già stato restituito

		echo '<div class="notice notice-warning inline" style="padding:12px 16px"><h3 style="margin-top:0">Cosa succede alla prima nota</h3>';
		if ( ! $inc['count'] && ! ( $void_costs && $p['expense']['count'] ) ) {
			echo '<p>Nessun movimento da modificare: la prima nota non cambia.</p>';
		} elseif ( ActivityReset::REFUND === $mode ) {
			echo '<p><strong>Gli ' . (int) $inc['count'] . ' incassi (' . esc_html( Money::format( (int) $inc['cents'] ) ) . ') restano in prima nota</strong> e viene registrata, a oggi, <strong>un\'uscita di restituzione per ciascuno</strong> (' . esc_html( Money::format( (int) $inc['cents'] ) ) . ' in tutto, sotto la voce «Restituzione quote»), sullo stesso conto e con la stessa modalità. Nei saldi l\'effetto netto è zero.</p>';
			echo '<p><strong>Il denaro non viene restituito dal sistema:</strong> va riconsegnato a chi ha pagato' . ( $inc['online_cents'] > 0 ? '; per i pagamenti online (' . esc_html( Money::format( (int) $inc['online_cents'] ) ) . ') il rimborso va fatto a mano dal pannello del fornitore (Stripe, PayPal, negozio)' : '' ) . '.</p>';
		} else {
			echo '<p><strong>Gli ' . (int) $inc['count'] . ' incassi (' . esc_html( Money::format( (int) $inc['cents'] ) ) . ') vengono ANNULLATI</strong>: <strong>spariscono da saldi, report, rendiconto e ricevute</strong>, come se non fossero mai avvenuti. Restano consultabili solo tra gli annullamenti e nel registro azioni.</p>';
			if ( $p['refund_exp']['count'] ) {
				echo '<p>Vengono annullate anche le <strong>' . (int) $p['refund_exp']['count'] . ' restituzioni</strong> già registrate per singole iscrizioni (' . esc_html( Money::format( (int) $p['refund_exp']['cents'] ) ) . '), perché compensavano incassi che ora si annullano.</p>';
			}
			if ( $void_costs && $p['expense']['count'] ) {
				echo '<p>Vengono annullate anche le <strong>' . (int) $p['expense']['count'] . ' spese</strong> (' . esc_html( Money::format( (int) $p['expense']['cents'] ) ) . ').</p>';
			}
			echo '<p><strong>Nessuna restituzione viene registrata e il denaro già incassato non viene restituito:</strong> se va restituito, va gestito a parte' . ( $inc['online_cents'] > 0 ? ' (per i pagamenti online anche dal pannello del fornitore)' : '' ) . '. Il saldo dei conti scende di ' . esc_html( Money::format( (int) $inc['cents'] ) ) . '.</p>';
		}
		if ( ! empty( $inc['receipts'] ) ) {
			echo '<p>Le ricevute già emesse per questo evento (' . count( $inc['receipts'] ) . ') ' . ( ActivityReset::VOID === $mode ? 'non risultano più valide.' : 'restano valide e a esse si affianca la restituzione.' ) . '</p>';
		}
		echo '<p>I movimenti che restano in prima nota non sono più collegati all\'evento e riportano nella descrizione «[Evento eliminato: ' . esc_html( (string) $p['activity']['name'] ) . ']».</p></div>';

		if ( $blockers ) {
			echo '<div class="notice notice-error inline"><p><strong>Non si può procedere:</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $blockers as $b ) {
				echo '<li>' . esc_html( $b ) . '</li>';
			}
			echo '</ul></div><p><a class="button" href="' . esc_url( Ui::url( 'apse-activity-delete', array( 'id' => $id ) ) ) . '">← Cambia scelta</a></p>';
			return;
		}

		echo '<h3>Conferma</h3>';
		Ui::form_open( 'apse_delete_activity', self::review_url( $id, $mode, $notify, $void_costs ) );
		echo Ui::hidden( 'id', $id ) . Ui::hidden( 'mode', $mode ) . Ui::hidden( 'notify', $notify ? 1 : 0 ) . Ui::hidden( 'void_costs', $void_costs ? 1 : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label><input type="checkbox" name="confirm" value="1"> Ho letto e ho capito: l\'eliminazione è definitiva e non si può annullare.</label></p>';
		echo '<p><label>Per confermare scrivi il nome dell\'evento: <strong>' . esc_html( (string) $p['activity']['name'] ) . '</strong><br><input type="text" name="typed" class="regular-text" autocomplete="off"></label></p>';
		echo '<p><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Elimina definitivamente</button> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-activity-delete', array( 'id' => $id ) ) ) . '">← Cambia scelta</a> <a class="button" href="' . esc_url( $back ) . '">Annulla</a></p>';
		Ui::form_close();
		unset( $notify );
	}
}
