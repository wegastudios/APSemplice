<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	public static function render(): void {
		$balances = Plugin::ledger()->balances();
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
		echo '</table><p><a href="' . esc_url( Ui::url( 'apse-accounts' ) ) . '">Conti e verifica saldi →</a></p></div>';

		echo '<div class="apse-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="apse-kv">';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

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

		if ( $r['activities'] ) {
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
