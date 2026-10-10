<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

final class ReportsPage {

	public static function render(): void {
		if ( Admin::reports_off( 'Report' ) ) {
			return;
		}
		$mode = Ui::get_str( 'mode' );
		$fiscal = \AssociazioneSemplice\Edition::has( 'fiscal' ); // l'anno solare per il commercialista è della licenza fiscale
		$mode   = in_array( $mode, array( 'social', 'solar' ), true ) && ( $fiscal || 'solar' !== $mode ) ? $mode : 'liquidity';
		Ui::header( 'Report' );
		$tabs = array( 'liquidity' => 'Conti e liquidità', 'social' => 'Anno sociale (attività)' ) + ( $fiscal ? array( 'solar' => 'Anno solare (commercialista)' ) : array() );
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab ' . ( $key === $mode ? 'nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'asem-reports', array( 'mode' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
		if ( 'solar' === $mode ) {
			self::solar();
		} elseif ( 'social' === $mode ) {
			self::social();
		} else {
			self::liquidity();
		}
		Ui::footer();
	}

	/** Conti e liquidità: saldi di oggi, fondi accantonati (per anno solare) e disponibilità reale. */
	private static function liquidity(): void {
		$bal   = Plugin::ledger()->balances( null, true );
		$avail = Plugin::funds()->available();
		echo '<h3>Saldi dei conti</h3><table class="widefat striped"><thead><tr><th>Conto</th><th>Tipo</th><th>Saldo</th></tr></thead><tbody>';
		foreach ( $bal as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . ( null !== $b['closed_at'] ? ' <span class="description">(chiuso)</span>' : '' ) . '</td><td>' . esc_html( \AssociazioneSemplice\Labels::account_types()[ $b['type'] ] ?? $b['type'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td colspan="2"><strong>Totale saldi</strong></td><td><strong>' . Ui::money( $avail['accounts'] ) . '</strong></td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td colspan="2">Fondi accantonati (rimborsi ai volontari)</td><td>− ' . Ui::money( $avail['funds'] ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><td colspan="2"><strong>Disponibilità reale dell\'associazione</strong> <span class="description">(saldi meno fondi)</span></td><td><strong>' . Ui::money( $avail['available'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h3>Fondi accantonati</h3>';
		$funds = Plugin::funds()->all();
		if ( ! $funds ) {
			echo '<p class="description">Nessun fondo aperto.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Fondo</th><th>Da rimborsare</th></tr></thead><tbody>';
			foreach ( $funds as $f ) {
				echo '<tr><td>' . esc_html( $f['name'] ) . '</td><td>' . Ui::money( $f['balance'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		self::funds_by_year();
	}

	/** Per ogni anno solare: totale accantonato nei fondi, già rimborsato o liberato e ancora da rimborsare (finché è più di zero, l'anno non si chiude). */
	public static function funds_by_year(): void {
		$tot = Plugin::funds()->yearly()['totals'];
		echo '<h3>Fondi per anno solare</h3>';
		if ( ! $tot ) {
			echo '<p class="description">Nessun accantonamento registrato.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Anno solare</th><th>Accantonato</th><th>Rimborsato o liberato</th><th>Da rimborsare</th><th>Anno</th></tr></thead><tbody>';
		foreach ( $tot as $y => $r ) {
			$fy = \AssociazioneSemplice\FiscalYears::get( (int) $y );
			echo '<tr><td>' . (int) $y . '</td><td>' . Ui::money( $r['accrued'] ) . '</td><td>' . Ui::money( $r['settled'] ) . '</td><td>' . ( $r['unsettled'] > 0 ? '<strong class="asem-warn">' . Ui::money( $r['unsettled'] ) . '</strong>' : Ui::money( 0 ) ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . ( $fy ? ( 'open' === $fy['status'] ? 'aperto' : 'chiuso' ) : '—' ) . ( $r['unsettled'] > 0 && $fy && 'open' === $fy['status'] ? ' <span class="description">(non si può chiudere finché resta da rimborsare)</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Un rimborso o una liberazione copre prima gli accantonamenti più vecchi. Gli accantonamenti sono uscite dell\'anno solare in cui si registrano, anche se il rimborso non è ancora stato pagato.</p>';
	}

	private static function nav( string $mode, int $year, string $label ): void {
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'asem-reports', array( 'mode' => $mode, 'year' => $year - 1 ) ) ) . '">‹</a> <strong>' . esc_html( $label ) . '</strong> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-reports', array( 'mode' => $mode, 'year' => $year + 1 ) ) ) . '">›</a></p>';
	}

	private static function solar(): void {
		$year = Ui::get_int( 'year', (int) current_time( 'Y' ) );
		$from = sprintf( '%04d-01-01', $year );
		$to   = sprintf( '%04d-12-31', $year );
		$r    = Plugin::reports()->period( $from, $to );
		self::nav( 'solar', $year, 'Anno ' . $year );
		$fy = \AssociazioneSemplice\FiscalYears::get( $year );
		echo '<p>' . ( $fy ? ( 'open' === $fy['status'] ? '<span class="asem-ok">Anno aperto</span>' : '<strong class="asem-warn">Anno chiuso</strong>' ) : '<span class="description">Anno non creato</span>' ) . ' · <a href="' . esc_url( Ui::url( 'asem-years' ) ) . '">Anni solari</a></p>';
		echo '<p>' . Exports::link( 'period', array( 'from' => $from, 'to' => $to ), 'Esporta rendiconto (CSV)' ) . ' ' . Exports::link( 'ledger', array( 'from' => $from, 'to' => $to ), 'Esporta prima nota (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h3>Saldi dei conti</h3><table class="widefat striped"><thead><tr><th>Conto</th><th>Iniziale</th><th>Entrate</th><th>Uscite</th><th>Giroconti</th><th>Finale</th></tr></thead><tbody>';
		foreach ( $r['accounts'] as $a ) {
			echo '<tr><td>' . esc_html( $a['account']['name'] ) . '</td><td>' . Ui::money( $a['opening'] ) . '</td><td>' . Ui::money( $a['income'] ) . '</td><td>' . Ui::money( $a['expense'] ) . '</td><td>' . Ui::money( $a['transfers'] ) . '</td><td><strong>' . Ui::money( $a['closing'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale</strong></td><td>' . Ui::money( $r['opening_total'] ) . '</td><td>' . Ui::money( array_sum( array_column( $r['accounts'], 'income' ) ) ) . '</td><td>' . Ui::money( array_sum( array_column( $r['accounts'], 'expense' ) ) ) . '</td><td></td><td><strong>' . Ui::money( $r['closing_total'] ) . '</strong></td></tr><tr><td colspan="5">Fondi accantonati (rimborsi ai volontari)</td><td>− ' . Ui::money( $r['funds_total'] ) . '</td></tr><tr><td colspan="5"><strong>Disponibilità reale dell\'associazione</strong> <span class="description">(saldi meno fondi)</span></td><td><strong>' . Ui::money( $r['available'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput

		foreach ( array( 'Entrate' => array( $r['income'], $r['total_income'] ), 'Uscite' => array( $r['expenses'], $r['total_expense'] ) ) as $title => $pair ) {
			echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><thead><tr><th>Voce</th><th>Voce di rendiconto</th><th>Importo</th></tr></thead><tbody>';
			foreach ( $pair[0] as $x ) {
				echo '<tr><td>' . esc_html( $x['name'] ) . '</td><td>' . esc_html( (string) $x['fiscal_group'] ) . '</td><td>' . Ui::money( $x['cents'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<tr><td colspan="2"><strong>Totale</strong></td><td><strong>' . Ui::money( $pair[1] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><strong>Avanzo / disavanzo: ' . Ui::money( $r['result'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $r['fund_accrued'] || $r['fund_released'] ) {
			echo '<p class="description">Le uscite comprendono i fondi accantonati nell\'anno (' . esc_html( \AssociazioneSemplice\Money::format( $r['fund_accrued'] ) ) . ')' . ( $r['fund_released'] ? ', al netto di ' . esc_html( \AssociazioneSemplice\Money::format( $r['fund_released'] ) ) . ' liberati' : '' ) . ', anche se il rimborso non è ancora stato pagato; il pagamento del rimborso non si conta una seconda volta.</p>';
		}
		self::funds_by_year();
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

		echo '<h3>Attività</h3><table class="widefat striped"><thead><tr><th>Attività</th><th>Referente</th><th>Iscritti</th><th>Incassi</th><th>Costi</th><th>Resta all\'associazione</th></tr></thead><tbody>';
		foreach ( $r['activities'] as $a ) {
			echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $a['activity']['id'] ) ) ) . '">' . esc_html( $a['activity']['name'] ) . '</a></td><td>' . esc_html( (string) $a['activity']['instructor_name'] ) . '</td><td>' . (int) $a['participants'] . '</td><td>' . Ui::money( $a['income'] ) . '</td><td>' . Ui::money( $a['cost'] ) . '</td><td><strong>' . Ui::money( $a['margin'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
