<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

final class ReportsPage {

	public static function render(): void {
		$mode = 'social' === Ui::get_str( 'mode' ) ? 'social' : 'solar';
		Ui::header( 'Report' );
		echo '<h2 class="nav-tab-wrapper"><a class="nav-tab ' . ( 'solar' === $mode ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'apse-reports', array( 'mode' => 'solar' ) ) ) . '">Anno solare (commercialista)</a>'
			. '<a class="nav-tab ' . ( 'social' === $mode ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'apse-reports', array( 'mode' => 'social' ) ) ) . '">Anno sociale (attività)</a></h2>';
		if ( 'solar' === $mode ) {
			self::solar();
		} else {
			self::social();
		}
		Ui::footer();
	}

	private static function nav( string $mode, int $year, string $label ): void {
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'apse-reports', array( 'mode' => $mode, 'year' => $year - 1 ) ) ) . '">‹</a> <strong>' . esc_html( $label ) . '</strong> '
			. '<a class="button" href="' . esc_url( Ui::url( 'apse-reports', array( 'mode' => $mode, 'year' => $year + 1 ) ) ) . '">›</a></p>';
	}

	private static function solar(): void {
		$year = Ui::get_int( 'year', (int) current_time( 'Y' ) );
		$from = sprintf( '%04d-01-01', $year );
		$to   = sprintf( '%04d-12-31', $year );
		$r    = Plugin::reports()->period( $from, $to );
		self::nav( 'solar', $year, 'Anno ' . $year );
		echo '<p>' . Exports::link( 'period', array( 'from' => $from, 'to' => $to ), 'Esporta rendiconto (CSV)' ) . ' ' . Exports::link( 'ledger', array( 'from' => $from, 'to' => $to ), 'Esporta prima nota (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h3>Saldi dei conti</h3><table class="widefat striped"><thead><tr><th>Conto</th><th>Iniziale</th><th>Entrate</th><th>Uscite</th><th>Giroconti</th><th>Finale</th></tr></thead><tbody>';
		foreach ( $r['accounts'] as $a ) {
			echo '<tr><td>' . esc_html( $a['account']['name'] ) . '</td><td>' . Ui::money( $a['opening'] ) . '</td><td>' . Ui::money( $a['income'] ) . '</td><td>' . Ui::money( $a['expense'] ) . '</td><td>' . Ui::money( $a['transfers'] ) . '</td><td><strong>' . Ui::money( $a['closing'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale</strong></td><td>' . Ui::money( $r['opening_total'] ) . '</td><td>' . Ui::money( $r['total_income'] ) . '</td><td>' . Ui::money( $r['total_expense'] ) . '</td><td></td><td><strong>' . Ui::money( $r['closing_total'] ) . '</strong></td></tr><tr><td colspan="5">Fondi accantonati (rimborsi ai volontari)</td><td>− ' . Ui::money( $r['funds_total'] ) . '</td></tr><tr><td colspan="5"><strong>Disponibilità reale dell\'associazione</strong> <span class="description">(saldi meno fondi)</span></td><td><strong>' . Ui::money( $r['available'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput

		foreach ( array( 'Entrate' => array( $r['income'], $r['total_income'] ), 'Uscite' => array( $r['expenses'], $r['total_expense'] ) ) as $title => $pair ) {
			echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><thead><tr><th>Voce</th><th>Voce di rendiconto</th><th>Importo</th></tr></thead><tbody>';
			foreach ( $pair[0] as $x ) {
				echo '<tr><td>' . esc_html( $x['name'] ) . '</td><td>' . esc_html( (string) $x['fiscal_group'] ) . '</td><td>' . Ui::money( $x['cents'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<tr><td colspan="2"><strong>Totale</strong></td><td><strong>' . Ui::money( $pair[1] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><strong>Avanzo / disavanzo: ' . Ui::money( $r['result'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function social(): void {
		$start = Ui::get_int( 'year', (int) Settings::social_year()->start_year );
		$year  = new SocialYear( $start, Settings::start_month() );
		$r     = Plugin::reports()->social_year( $year );
		self::nav( 'social', $start, 'Anno sociale ' . $year->label() );
		echo '<p>' . Exports::link( 'social', array( 'year' => $start ), 'Esporta report attività (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h3>Soci iscritti nell\'anno</h3><table class="widefat striped"><tbody>';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>Attività</h3><table class="widefat striped"><thead><tr><th>Attività</th><th>Istruttore</th><th>Iscritti</th><th>Incassi</th><th>Costi</th><th>Resta all\'associazione</th></tr></thead><tbody>';
		foreach ( $r['activities'] as $a ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $a['activity']['id'] ) ) ) . '">' . esc_html( $a['activity']['name'] ) . '</a></td><td>' . esc_html( (string) $a['activity']['instructor_name'] ) . '</td><td>' . (int) $a['participants'] . '</td><td>' . Ui::money( $a['income'] ) . '</td><td>' . Ui::money( $a['cost'] ) . '</td><td><strong>' . Ui::money( $a['margin'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		foreach ( array( 'Entrate generali (non di attività)' => $r['general_income'], 'Uscite generali (non di attività)' => $r['general_expenses'] ) as $title => $list ) {
			echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( $list as $x ) {
				echo '<tr><td>' . esc_html( $x['name'] ) . '</td><td>' . Ui::money( $x['cents'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		echo '<p>Totale entrate ' . Ui::money( $r['total_income'] ) . ' · uscite ' . Ui::money( $r['total_expense'] ) . ' · <strong>risultato ' . Ui::money( $r['result'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
