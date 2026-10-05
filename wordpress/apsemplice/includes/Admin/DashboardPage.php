<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	public static function render(): void {
		$all      = Plugin::ledger()->balances();
		$balances = array_values( array_filter( $all, function ( $b ) {
			return 'fund' !== $b['kind'];
		} ) );
		$funds    = array_values( array_filter( $all, function ( $b ) {
			return 'fund' === $b['kind'];
		} ) );
		$year     = Settings::social_year();
		$r        = Plugin::reports()->social_year( $year );
		$name     = (string) Settings::get( 'association_name' );

		Ui::header( 'APSemplice' . ( $name ? ' — ' . $name : '' ) );
		echo '<p>'
			. '<a class="button button-primary" href="' . esc_url( Ui::url( 'apse-income' ) ) . '">Nuovo incasso</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-expense' ) ) . '">Nuova spesa</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-transfer' ) ) . '">Giroconto</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a></p>';

		echo '<div class="apse-grid"><div class="apse-card"><h2>Disponibilità</h2>';
		echo '<p class="apse-big">' . Ui::money( array_sum( array_column( $balances, 'balance' ) ) ) . '</p><table class="apse-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $balances as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table>';
		if ( $funds ) {
			echo '<p class="description" style="margin-bottom:2px">Fondi (soldi in cassa non dell\'associazione): ' . Ui::money( array_sum( array_column( $funds, 'balance' ) ) ) . '</p><table class="apse-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $funds as $b ) {
				echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</table>';
		}
		echo '<p><a href="' . esc_url( Ui::url( 'apse-accounts' ) ) . '">Conti e verifica saldi →</a></p></div>';

		echo '<div class="apse-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="apse-kv">';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

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
}
