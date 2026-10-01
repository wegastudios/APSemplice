<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

/** Download CSV (separatore ";" e virgola decimale: si apre bene in Excel italiano). */
final class Exports {

	public static function register(): void {
		add_action( 'admin_post_aps_export', array( __CLASS__, 'handle' ) );
	}

	public static function link( string $what, array $args, string $label ): string {
		$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'aps_export', 'what' => $what ), $args ), admin_url( 'admin-post.php' ) ), 'aps_export' );
		return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function line( array $cells ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = null === $c ? '' : (string) $c;
			$out[] = preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ';', $out ) . "\r\n";
	}

	private static function d( ?string $ymd ): string {
		return $ymd ? ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) : '';
	}

	public static function handle(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'aps_export' );
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		switch ( $what ) {
			case 'ledger':
				list( $name, $csv ) = self::ledger( Ui::get_str( 'from' ), Ui::get_str( 'to' ) );
				break;
			case 'period':
				list( $name, $csv ) = self::period( Ui::get_str( 'from' ), Ui::get_str( 'to' ) );
				break;
			case 'social':
				list( $name, $csv ) = self::social( Ui::get_int( 'year', (int) Settings::social_year()->start_year ) );
				break;
			case 'people':
				list( $name, $csv ) = self::people();
				break;
			default:
				wp_die( 'Export non valido.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo "\xEF\xBB\xBF" . $csv; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function ledger( string $from, string $to ): array {
		$csv = self::line( array( 'Data', 'Tipo', 'Conto', 'Modalità', 'Voce', 'Attività', 'N. tessera', 'Persona', 'Descrizione', 'Competenza', 'Riferimento', 'Entrata', 'Uscita' ) );
		foreach ( Plugin::ledger()->rows( $from, $to, null, true ) as $r ) {
			$plus = Labels::sign( $r['type'] ) > 0 ? Money::plain( (int) $r['amount_cents'] ) : '';
			$minus = Labels::sign( $r['type'] ) < 0 ? Money::plain( (int) $r['amount_cents'] ) : '';
			$csv  .= self::line(
				array(
					self::d( $r['tx_date'] ), Labels::tx_types()[ $r['type'] ], $r['account_name'], Labels::methods()[ $r['method'] ] ?? $r['method'],
					Labels::is_transfer( $r['type'] ) ? 'Giroconto' : $r['category_name'], $r['activity_name'], $r['person_card'], $r['person_name'],
					$r['description'], $r['competence_month'], $r['document_ref'], $plus, $minus,
				)
			);
		}
		return array( "prima-nota-$from-$to.csv", $csv );
	}

	public static function period( string $from, string $to ): array {
		$r   = Plugin::reports()->period( $from, $to );
		$csv = self::line( array( 'Rendiconto per cassa', Settings::get( 'association_name' ) ) );
		$csv .= self::line( array( 'Periodo', self::d( $from ) . ' - ' . self::d( $to ) ) ) . "\r\n";
		$csv .= self::line( array( 'SALDI DEI CONTI', 'Iniziale', 'Entrate', 'Uscite', 'Giroconti', 'Finale' ) );
		foreach ( $r['accounts'] as $a ) {
			$csv .= self::line( array( $a['account']['name'], Money::plain( $a['opening'] ), Money::plain( $a['income'] ), Money::plain( $a['expense'] ), Money::plain( $a['transfers'] ), Money::plain( $a['closing'] ) ) );
		}
		$csv .= self::line( array( 'Totale', Money::plain( $r['opening_total'] ), Money::plain( $r['total_income'] ), Money::plain( $r['total_expense'] ), '0,00', Money::plain( $r['closing_total'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'ENTRATE', 'Voce di rendiconto', 'Importo' ) );
		foreach ( $r['income'] as $x ) {
			$csv .= self::line( array( $x['name'], $x['fiscal_group'], Money::plain( $x['cents'] ) ) );
		}
		$csv .= self::line( array( 'Totale entrate', '', Money::plain( $r['total_income'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'USCITE', 'Voce di rendiconto', 'Importo' ) );
		foreach ( $r['expenses'] as $x ) {
			$csv .= self::line( array( $x['name'], $x['fiscal_group'], Money::plain( $x['cents'] ) ) );
		}
		$csv .= self::line( array( 'Totale uscite', '', Money::plain( $r['total_expense'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'Avanzo / disavanzo', '', Money::plain( $r['result'] ) ) );
		return array( "rendiconto-$from-$to.csv", $csv );
	}

	public static function social( int $start_year ): array {
		$year = new SocialYear( $start_year, Settings::start_month() );
		$r    = Plugin::reports()->social_year( $year );
		$csv  = self::line( array( 'Valutazione anno sociale', $year->label(), Settings::get( 'association_name' ) ) );
		foreach ( MemberType::member_types() as $t ) {
			$csv .= self::line( array( MemberType::label( $t ), $r['members_by_type'][ $t ] ?? 0 ) );
		}
		$csv .= "\r\n" . self::line( array( 'ATTIVITÀ', 'Istruttore', 'Iscritti attivi', 'Incassi', 'Costi', "Resta all'associazione" ) );
		foreach ( $r['activities'] as $a ) {
			$csv .= self::line( array( $a['activity']['name'], $a['activity']['instructor_name'], $a['participants'], Money::plain( $a['income'] ), Money::plain( $a['cost'] ), Money::plain( $a['margin'] ) ) );
		}
		$csv .= "\r\n" . self::line( array( 'ENTRATE GENERALI (non di attività)', '', 'Importo' ) );
		foreach ( $r['general_income'] as $x ) {
			$csv .= self::line( array( $x['name'], '', Money::plain( $x['cents'] ) ) );
		}
		$csv .= self::line( array( 'USCITE GENERALI (non di attività)', '', 'Importo' ) );
		foreach ( $r['general_expenses'] as $x ) {
			$csv .= self::line( array( $x['name'], '', Money::plain( $x['cents'] ) ) );
		}
		$csv .= "\r\n" . self::line( array( 'Totale entrate', Money::plain( $r['total_income'] ) ) );
		$csv .= self::line( array( 'Totale uscite', Money::plain( $r['total_expense'] ) ) );
		$csv .= self::line( array( 'Risultato', Money::plain( $r['result'] ) ) );
		return array( 'anno-sociale-' . str_replace( '/', '-', $year->label() ) . '.csv', $csv );
	}

	public static function people(): array {
		$csv = self::line( array( 'Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Telefono', 'Codice fiscale', 'Ospite di', 'Tessera valida fino al' ) );
		foreach ( Plugin::people()->search() as $p ) {
			$csv .= self::line(
				array( $p['card_number'], MemberType::label( $p['type'] ), $p['first_name'], $p['last_name'], $p['email'], $p['phone'], $p['tax_code'], $p['host_name'], self::d( MemberType::GUEST === $p['type'] ? null : $p['active_until'] ) )
			);
		}
		return array( 'soci.csv', $csv );
	}
}
