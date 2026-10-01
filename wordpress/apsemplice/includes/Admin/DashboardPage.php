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
			. '<a class="button button-primary" href="' . esc_url( Ui::url( 'aps-income' ) ) . '">Nuovo incasso</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'aps-expense' ) ) . '">Nuova spesa</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'aps-transfer' ) ) . '">Giroconto</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'aps-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a></p>';

		echo '<div class="aps-grid"><div class="aps-card"><h2>Disponibilità</h2>';
		echo '<p class="aps-big">' . Ui::money( array_sum( array_column( $balances, 'balance' ) ) ) . '</p><table class="aps-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $balances as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table><p><a href="' . esc_url( Ui::url( 'aps-accounts' ) ) . '">Conti e verifica saldi →</a></p></div>';

		echo '<div class="aps-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="aps-kv">';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $r['activities'] ) {
			echo '<h2>Attività</h2><div class="aps-grid">';
			foreach ( $r['activities'] as $a ) {
				echo '<div class="aps-card"><h3><a href="' . esc_url( Ui::url( 'aps-activity', array( 'id' => $a['activity']['id'] ) ) ) . '">' . esc_html( $a['activity']['name'] ) . '</a></h3>'
					. '<p>' . (int) $a['participants'] . ' iscritti · resta ' . Ui::money( $a['margin'] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		}
		Ui::footer();
	}
}
