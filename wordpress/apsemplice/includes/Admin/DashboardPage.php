<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	public static function render(): void {
		$balances = Plugin::ledger()->balances();
		$funds    = Plugin::funds()->all();
		$avail    = Plugin::funds()->available();
		$year     = Settings::social_year();
		$r        = Plugin::reports()->social_year( $year );
		$name     = (string) Settings::get( 'association_name' );

		Ui::header( 'APSemplice' . ( $name ? ' — ' . $name : '' ) );
		echo '<p>'
			. '<a class="button button-primary" href="' . esc_url( Ui::url( 'apse-income' ) ) . '">Nuovo incasso</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-expense' ) ) . '">Nuova spesa</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a></p>';

		echo '<div class="apse-grid"><div class="apse-card"><h2>Disponibilità reale</h2>';
		echo '<p class="apse-big">' . Ui::money( $avail['available'] ) . '</p><p class="description">Saldi dei conti meno i fondi accantonati per i rimborsi.</p><table class="apse-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $balances as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale saldi</strong></td><td><strong>' . Ui::money( $avail['accounts'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $funds as $f ) {
			echo '<tr><td>− ' . esc_html( $f['name'] ) . '</td><td>' . Ui::money( $f['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table><p><a href="' . esc_url( Ui::url( 'apse-accounts' ) ) . '">Conti, fondi e verifica saldi →</a></p></div>';

		echo '<div class="apse-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="apse-kv">';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		self::quick_cash();
		self::quick_enroll();
		self::course_dues();
		self::renewals();
		self::expected_guests();

		$requests = \ApSemplice\AccessRequests::pending();
		if ( $requests ) {
			echo '<div class="apse-card"><h2>Richieste di accesso (' . count( $requests ) . ')</h2><p class="description">Dal "Primo accesso" del sito: chi non è stato riconosciuto, chi chiede di cambiare email e chi si è attivato col solo cellulare.</p><ul>';
			foreach ( $requests as $rq ) {
				$wa     = \ApSemplice\Phone::whatsapp( (string) $rq['phone'] );
				$who    = $rq['person'] ? $rq['person']['first_name'] . ' ' . $rq['person']['last_name'] : (string) $rq['name'];
				$label  = array( 'unknown' => 'non riconosciuto', 'change' => 'chiede di cambiare email', 'review' => 'attivato col cellulare: controlla' );
				echo '<li>' . ( $rq['person'] ? '<a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $rq['person']['id'] ) ) ) . '">' . esc_html( $who ) . '</a>' : '<strong>' . esc_html( $who ) . '</strong>' )
					. ' <span class="description">' . esc_html( $label[ $rq['kind'] ] ) . ' · ' . esc_html( (string) $rq['email'] ) . ' · ' . esc_html( (string) $rq['phone'] ) . ' · ' . esc_html( mysql2date( 'd/m H:i', gmdate( 'Y-m-d H:i:s', (int) $rq['at'] ) ) ) . '</span> ';
				if ( 'unknown' === $rq['kind'] && '' !== $wa ) {
					$text = 'Ciao ' . $who . ', ho ricevuto la tua richiesta di primo accesso. Per attivarti confermami nome, cognome ed email con cui sei iscritto/a.';
					echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( $text ) ) . '">💬 Scrivi su WhatsApp</a> ';
				}
				if ( 'change' === $rq['kind'] ) {
					Ui::form_open( 'apse_access_approve', Ui::url( 'apse' ), false, 'apse-inline' );
					echo Ui::hidden( 'id', $rq['id'] ) . '<button class="button button-small">Approva nuova email</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					echo ' ';
				}
				Ui::form_open( 'apse_access_done', Ui::url( 'apse' ), false, 'apse-inline' );
				echo Ui::hidden( 'id', $rq['id'] ) . '<button class="button-link">' . ( 'change' === $rq['kind'] ? 'rifiuta' : 'fatto' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul></div>';
		}

		$at_limit = \ApSemplice\Plugin::people()->guests_to_invite();
		if ( $at_limit ) {
			echo '<div class="apse-card"><h2>Ospiti da invitare a iscriversi (' . count( $at_limit ) . ')</h2><p class="description">Hanno raggiunto la soglia di partecipazioni per i non soci (sommando le registrazioni dello stesso cellulare, email o nome). Nessun blocco: decidi tu.</p><ul>';
			foreach ( array_slice( $at_limit, 0, 10 ) as $g ) {
				$ov = $g['overview'];
				echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $g['id'] ) ) ) . '">' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</a> <span class="description">ospite di ' . esc_html( (string) $g['host_name'] ) . ' · ' . (int) $ov['total'] . ' partecipazioni'
					. ( $ov['twins'] ? ' (registrato più volte)' : '' ) . '</span></li>';
			}
			echo '</ul><p><a href="' . esc_url( Ui::url( 'apse-people', array( 'type' => 'guest', 'at_limit' => 1 ) ) ) . '">Vedi tutti →</a></p></div>';
		}

		if ( ! empty( $r['activities'] ) ) {
			echo '<h2>Attività</h2><div class="apse-grid">';
			foreach ( $r['activities'] as $a ) {
				echo '<div class="apse-card"><h3><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $a['activity']['id'] ) ) ) . '">' . esc_html( $a['activity']['name'] ) . '</a></h3>'
					. '<p>' . (int) $a['participants'] . ' iscritti · resta ' . Ui::money( $a['margin'] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		}
		Ui::footer();
	}

	/** Cassa rapida: un incasso o una spesa semplici in pochi campi (per il resto c'è la Contabilità). */
	private static function quick_cash(): void {
		$ledger   = Plugin::ledger();
		$accounts = array();
		foreach ( $ledger->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		$cats         = array();
		$income_ids   = array();
		$account_types = array();
		foreach ( $ledger->accounts() as $a ) {
			$account_types[ (int) $a['id'] ] = $a['type'];
		}
		foreach ( array( 'donation', 'other_income', 'general_cost' ) as $k ) {
			$id = $ledger->category_id_of_kind( $k );
			if ( $id && ! \ApSemplice\Labels::category_kinds()[ $k ][2] ) {
				$income_ids[] = (int) $id;
			}
			if ( $id ) {
				$cats[ $id ] = ( \ApSemplice\Labels::category_kinds()[ $k ][2] ? 'Spesa: ' : 'Incasso: ' ) . \ApSemplice\Labels::category_kinds()[ $k ][0];
			}
		}
		$default = $ledger->default_account_for( 'cash' );
		echo '<div class="apse-card"><h2>Cassa rapida</h2>';
		Ui::form_open( 'apse_quick_cash', Ui::url( 'apse' ) );
		echo '<div data-apse-change data-types="' . esc_attr( wp_json_encode( $account_types ) ) . '" data-income="' . esc_attr( wp_json_encode( $income_ids ) ) . '">';
		echo '<p><select name="category_id" required>' . Ui::options( $cats, null ) . '</select> ' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<input type="text" name="amount" inputmode="decimal" placeholder="0,00" size="8" required> € </p>'
			. '<p><input type="text" name="description" class="regular-text" placeholder="Descrizione" required></p>'
			. '<p>Sul conto <select name="account_id">' . Ui::options( $accounts, $default ? $default['id'] : null ) . '</select></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<div class="apse-cashbox" style="display:none"><p>Contanti ricevuti <input type="text" class="apse-tendered" inputmode="decimal" placeholder="importo esatto" size="8"> € <span class="apse-quick"></span></p><p class="apse-change-out"></p></div></div>'
			. '<p><button class="button button-primary">Registra</button> <a href="' . esc_url( Ui::url( 'apse-income' ) ) . '">Incasso completo (quote, attività)</a> · <a href="' . esc_url( Ui::url( 'apse-expense' ) ) . '">Spesa completa</a></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Iscrizione rapida a un corso (dal mese in corso) o prenotazione a una data di un evento. */
	private static function quick_enroll(): void {
		$acts    = Plugin::activities();
		$targets = array();
		foreach ( $acts->for_year( Settings::social_year()->label() ) as $a ) {
			if ( 'course' === $a['kind'] ) {
				$targets[ 'a:' . $a['id'] ] = 'Corso: ' . $a['name'];
			}
		}
		foreach ( $acts->upcoming_sessions( 30 ) as $s ) {
			$targets[ 's:' . $s['id'] ] = 'Evento: ' . $s['activity_name'] . ' — ' . Ui::date( $s['session_date'] );
		}
		echo '<div class="apse-card"><h2>Iscrizione a corsi ed eventi</h2>';
		if ( ! $targets ) {
			echo '<p>Nessun corso né evento in programma. <a href="' . esc_url( Ui::url( 'apse-activities' ) ) . '">Creane uno</a>.</p></div>';
			return;
		}
		Ui::form_open( 'apse_quick_enroll', Ui::url( 'apse' ) );
		echo '<p>' . Ui::person_select( 'person_id', Plugin::people()->search(), null, '— chi si iscrive —' ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><select name="target" required>' . Ui::options( $targets, null, '— a cosa —' ) . '</select></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p><button class="button button-primary">Iscrivi</button> <a href="' . esc_url( Ui::url( 'apse-income' ) ) . '">Poi incassa il contributo →</a></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Corsi: iscritti che hanno una mensilità già dovuta (prima lezione del mese passata) e non ancora pagata. */
	private static function course_dues(): void {
		$acts = Plugin::activities();
		$rows = array();
		foreach ( $acts->for_year( Settings::social_year()->label() ) as $a ) {
			if ( 'course' !== $a['kind'] ) {
				continue;
			}
			foreach ( $acts->status_for_activity( (int) $a['id'] ) as $s ) {
				if ( null === $s['enrollment']['end_month'] && $s['summary']['unpaid_months'] ) {
					$rows[] = array( 'activity' => $a, 'enrollment' => $s['enrollment'], 'summary' => $s['summary'] );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div class="apse-card"><h2>Mensilità da incassare (' . count( $rows ) . ')</h2><p class="description">Corsi che si rinnovano ogni mese: la mensilità è dovuta dalla prima lezione del mese.</p><ul>';
		foreach ( array_slice( $rows, 0, 12 ) as $r ) {
			$e       = $r['enrollment'];
			$missing = max( 0, -$r['summary']['balance'] );
			$months  = implode( ', ', array_map( function ( $m ) {
				return Ui::month( $m['month'] );
			}, $r['summary']['unpaid_months'] ) );
			echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $e['person_id'] ) ) ) . '">' . esc_html( $e['first_name'] . ' ' . $e['last_name'] ) . '</a> <span class="description">' . esc_html( $r['activity']['name'] . ' · ' . $months ) . '</span> <strong>' . esc_html( Money::format( $missing ) ) . '</strong> '
				. '<a class="button button-small" href="' . esc_url( Ui::url( 'apse-income', array( 'person_id' => $e['person_id'], 'due' => 1 ) ) ) . '">Incassa</a> ' . Ui::contact_links( $e ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>' . ( count( $rows ) > 12 ? '<p class="description">… e altri ' . ( count( $rows ) - 12 ) . '. Li trovi nelle schede dei corsi.</p>' : '' ) . '</div>';
	}

	/** Soci con la tessera scaduta o in scadenza nei prossimi 30 giorni. */
	private static function renewals(): void {
		$today = current_time( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$list  = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::GUEST === $p['type'] || empty( $p['active_until'] ) || MemberType::is_auto_renewed( $p['type'] ) ) {
				continue;
			}
			if ( $p['active_until'] <= $soon ) {
				$list[] = $p;
			}
		}
		if ( ! $list ) {
			return;
		}
		usort( $list, function ( $a, $b ) {
			return strcmp( $a['active_until'], $b['active_until'] );
		} );
		echo '<div class="apse-card"><h2>Soci da rinnovare (' . count( $list ) . ')</h2><p class="description">Tessera scaduta o in scadenza entro 30 giorni.</p><ul>';
		foreach ( array_slice( $list, 0, 10 ) as $p ) {
			$expired = $p['active_until'] < $today;
			echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $p['id'] ) ) ) . '">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</a> <span class="description">'
				. ( $expired ? 'scaduta il ' : 'scade il ' ) . esc_html( Ui::date( $p['active_until'] ) ) . '</span> ' . Ui::contact_links( $p ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>' . ( count( $list ) > 10 ? '<p><a href="' . esc_url( Ui::url( 'apse-people', array( 'status' => 'inactive' ) ) ) . '">Vedi tutti →</a></p>' : '' ) . '</div>';
	}

	/** Ospiti prenotati ai prossimi eventi che hanno già raggiunto la soglia: da invitare a iscriversi. */
	private static function expected_guests(): void {
		$acts = Plugin::activities();
		$ov   = Plugin::people()->guest_overview();
		$rows = array();
		foreach ( $acts->upcoming_sessions( 5 ) as $s ) {
			foreach ( $acts->bookings_for_session( (int) $s['id'] ) as $b ) {
				if ( MemberType::GUEST === $b['type'] && 'booked' === $b['status'] && ! empty( $ov[ (int) $b['person_id'] ]['flag'] ) ) {
					$rows[] = array( 'session' => $s, 'booking' => $b, 'overview' => $ov[ (int) $b['person_id'] ] );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div class="apse-card"><h2>Ospiti attesi che dovrebbero iscriversi (' . count( $rows ) . ')</h2><p class="description">Prenotati ai prossimi eventi, hanno già raggiunto la soglia di partecipazioni per i non soci.</p><ul>';
		foreach ( $rows as $r ) {
			$b = $r['booking'];
			echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $b['person_id'] ) ) ) . '">' . esc_html( $b['first_name'] . ' ' . $b['last_name'] ) . '</a> <span class="description">a '
				. esc_html( $r['session']['activity_name'] ) . ' il ' . esc_html( Ui::date( $r['session']['session_date'] ) ) . ' · ' . (int) $r['overview']['total'] . ' partecipazioni</span> ' . Ui::contact_links( $b ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';
	}
}
