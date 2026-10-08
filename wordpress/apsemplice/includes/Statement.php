<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Rendiconto per cassa di un anno solare, nello schema usato dagli enti del terzo settore: entrate e uscite raggruppate per area,
 * confronto con l'anno precedente, avanzo o disavanzo, saldi di cassa e conti, relazione sull'andamento.
 * I dati sono quelli della prima nota (la stessa fonte del report); il raggruppamento segue la "voce di rendiconto" di ogni categoria.
 */
final class Statement {

	const INCOME_ORDER  = array( 'Entrate da quote associative', 'Entrate da attività di interesse generale', 'Erogazioni liberali', 'Altre entrate' );
	const EXPENSE_ORDER = array( 'Uscite da attività di interesse generale', 'Uscite di supporto generale', 'Rettifiche' );
	const MAX_NOTES     = 5000;

	public static function notes( int $year ): string {
		return (string) get_option( 'apse_statement_notes_' . $year, '' );
	}

	/** @throws \InvalidArgumentException */
	public static function save_notes( int $year, string $text ): void {
		$text = trim( sanitize_textarea_field( $text ) );
		if ( mb_strlen( $text ) > self::MAX_NOTES ) {
			throw new \InvalidArgumentException( 'La relazione è troppo lunga (al massimo ' . self::MAX_NOTES . ' caratteri).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		update_option( 'apse_statement_notes_' . $year, $text, false );
	}

	/** Anni per cui ha senso il rendiconto: dal primo con movimenti all'anno in corso. @return int[] */
	public static function years(): array {
		$first = Db::db()->get_var( 'SELECT MIN(tx_date) FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL' );
		$now   = (int) substr( Db::today(), 0, 4 );
		$from  = $first ? min( (int) substr( (string) $first, 0, 4 ), $now ) : $now;
		return range( $now, $from );
	}

	private static function group_lines( array $cur, array $prev, array $order, string $fallback ): array {
		$groups = array();
		$add    = function ( array $list, string $slot ) use ( &$groups, $fallback ) {
			foreach ( $list as $l ) {
				$g = ! empty( $l['fiscal_group'] ) ? (string) $l['fiscal_group'] : $fallback;
				if ( ! isset( $groups[ $g ][ $l['name'] ] ) ) {
					$groups[ $g ][ $l['name'] ] = array( 'cur' => 0, 'prev' => 0 );
				}
				$groups[ $g ][ $l['name'] ][ $slot ] += (int) $l['cents'];
			}
		};
		$add( $cur, 'cur' );
		$add( $prev, 'prev' );
		$keys = array_keys( $groups );
		usort(
			$keys,
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a, $order, true );
				$ib = array_search( $b, $order, true );
				$ia = false === $ia ? 99 : $ia;
				$ib = false === $ib ? 99 : $ib;
				return $ia <=> $ib ?: strcmp( $a, $b );
			}
		);
		$out = array();
		foreach ( $keys as $g ) {
			$lines = array();
			$tc    = 0;
			$tp    = 0;
			foreach ( $groups[ $g ] as $name => $v ) {
				$lines[] = array( 'name' => (string) $name, 'cur' => $v['cur'], 'prev' => $v['prev'] );
				$tc     += $v['cur'];
				$tp     += $v['prev'];
			}
			$out[] = array( 'group' => $g, 'lines' => $lines, 'cur' => $tc, 'prev' => $tp );
		}
		return $out;
	}

	/** @return array dati del rendiconto dell'anno (e di quello precedente per il confronto) */
	public static function data( int $year ): array {
		$svc  = new ReportService();
		$cur  = $svc->period( $year . '-01-01', $year . '-12-31' );
		$prev = $svc->period( ( $year - 1 ) . '-01-01', ( $year - 1 ) . '-12-31' );
		$accounts = array();
		foreach ( $cur['accounts'] as $a ) {
			$accounts[] = array( 'name' => (string) $a['account']['name'], 'opening' => (int) $a['opening'], 'closing' => (int) $a['closing'] );
		}
		return array(
			'year'          => $year,
			'income'        => self::group_lines( $cur['income'], $prev['income'], self::INCOME_ORDER, 'Altre entrate' ),
			'expenses'      => self::group_lines( $cur['expenses'], $prev['expenses'], self::EXPENSE_ORDER, 'Altre uscite' ),
			'total_income'  => array( 'cur' => (int) $cur['total_income'], 'prev' => (int) $prev['total_income'] ),
			'total_expense' => array( 'cur' => (int) $cur['total_expense'], 'prev' => (int) $prev['total_expense'] ),
			'result'        => array( 'cur' => (int) $cur['result'], 'prev' => (int) $prev['result'] ),
			'accounts'      => $accounts,
			'opening_total' => (int) $cur['opening_total'],
			'closing_total' => (int) $cur['closing_total'],
			'funds_total'   => (int) $cur['funds_total'],
			'available'     => (int) $cur['available'],
			'notes'         => self::notes( $year ),
		);
	}
}
