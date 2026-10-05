<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Due viste sugli stessi movimenti:
 *  - rendiconto per cassa di un periodo (anno solare, per il commercialista);
 *  - valutazione dell'anno sociale (cosa rende ogni attività e cosa resta all'associazione).
 */
class ReportService {

	private function db(): \wpdb {
		return Db::db();
	}

	/** Rendiconto per cassa: saldi per conto, entrate e uscite per voce di rendiconto, avanzo. */
	public function period( string $from, string $to ): array {
		$ledger   = Plugin::ledger();
		$before   = $ledger->balances( ( new \DateTimeImmutable( $from ) )->modify( '-1 day' )->format( 'Y-m-d' ), true );
		$funds    = $ledger->fund_ids();
		$rows     = $ledger->rows( $from, $to, null, true );
		$accounts = array();
		foreach ( $before as $a ) {
			if ( null !== $a['closed_at'] && 0 === (int) $a['balance'] && ! array_filter( $rows, function ( $r ) use ( $a ) {
				return (int) $r['account_id'] === (int) $a['id'];
			} ) ) {
				continue; // conto chiuso e senza movimenti nel periodo
			}
			$own       = array_filter( $rows, function ( $r ) use ( $a ) {
				return (int) $r['account_id'] === (int) $a['id'];
			} );
			$income    = 0;
			$expense   = 0;
			$transfers = 0;
			foreach ( $own as $r ) {
				if ( 'income' === $r['type'] ) {
					$income += (int) $r['amount_cents'];
				} elseif ( 'expense' === $r['type'] ) {
					$expense += (int) $r['amount_cents'];
				} else {
					$transfers += Labels::sign( $r['type'] ) * (int) $r['amount_cents'];
				}
			}
			$accounts[] = array(
				'account' => $a, 'opening' => $a['balance'], 'income' => $income, 'expense' => $expense, 'transfers' => $transfers,
				'closing' => $a['balance'] + $income - $expense + $transfers,
				'fund' => in_array( (int) $a['id'], $funds, true ),
			);
		}

		$group = function ( string $type ) use ( $rows, $funds ) {
			$by = array();
			foreach ( $rows as $r ) {
				if ( $r['type'] !== $type || in_array( (int) $r['account_id'], $funds, true ) ) {
					continue;
				}
				$key = $r['category_id'];
				if ( ! isset( $by[ $key ] ) ) {
					$by[ $key ] = array( 'name' => $r['category_name'], 'cents' => 0 );
				}
				$by[ $key ]['cents'] += (int) $r['amount_cents'];
			}
			return array_values( $by );
		};
		$fiscal = $this->fiscal_groups();
		$decorate = function ( array $list ) use ( $fiscal ) {
			foreach ( $list as &$l ) {
				$l['fiscal_group'] = $fiscal[ $l['name'] ] ?? null;
			}
			return $list;
		};
		$income   = $decorate( $group( 'income' ) );
		$expenses = $decorate( $group( 'expense' ) );
		$ti       = array_sum( array_column( $income, 'cents' ) );
		$te       = array_sum( array_column( $expenses, 'cents' ) );

		$real      = array_filter( $accounts, function ( $a ) {
			return empty( $a['fund'] );
		} );
		$fund_rows = array_filter( $accounts, function ( $a ) {
			return ! empty( $a['fund'] );
		} );

		return array(
			'from' => $from, 'to' => $to, 'accounts' => $accounts, 'income' => $income, 'expenses' => $expenses,
			'total_income' => $ti, 'total_expense' => $te, 'result' => $ti - $te,
			'opening_total' => array_sum( array_column( $real, 'opening' ) ),
			'closing_total' => array_sum( array_column( $real, 'closing' ) ),
			'funds_opening' => array_sum( array_column( $fund_rows, 'opening' ) ),
			'funds_closing' => array_sum( array_column( $fund_rows, 'closing' ) ),
		);
	}

	private function fiscal_groups(): array {
		$out = array();
		foreach ( Plugin::ledger()->categories() as $c ) {
			$out[ $c['name'] ] = $c['fiscal_group'];
		}
		return $out;
	}

	/** Valutazione dell'anno sociale: per attività incassi - costi, più entrate/uscite generali e numero soci. */
	public function social_year( SocialYear $year ): array {
		$activities = Plugin::activities()->for_year( $year->label() );
		$ids        = array_map( 'intval', array_column( $activities, 'id' ) );
		$by_activity = array();
		if ( $ids ) {
			$sql  = "SELECT activity_id, type, SUM(amount_cents) AS s FROM " . Db::t( 'transactions' )
				. ' WHERE voided_at IS NULL AND activity_id IN (' . implode( ',', $ids ) . ') GROUP BY activity_id, type';
			foreach ( $this->db()->get_results( $sql, ARRAY_A ) ?: array() as $r ) {
				$by_activity[ (int) $r['activity_id'] ][ $r['type'] ] = (int) $r['s'];
			}
		}
		$summaries = array();
		foreach ( $activities as $a ) {
			$income      = $by_activity[ (int) $a['id'] ]['income'] ?? 0;
			$cost        = $by_activity[ (int) $a['id'] ]['expense'] ?? 0;
			$summaries[] = array(
				'activity' => $a, 'participants' => Plugin::activities()->active_participants( (int) $a['id'] ),
				'income' => $income, 'cost' => $cost, 'margin' => $income - $cost,
			);
		}

		// Movimenti generali: nel periodo dell'anno sociale, non legati ad attività e non giroconti
		$funds   = Plugin::ledger()->fund_ids();
		$general = array_filter(
			Plugin::ledger()->rows( $year->start()->format( 'Y-m-d' ), $year->end()->format( 'Y-m-d' ), null, true ),
			function ( $r ) use ( $funds ) {
				return empty( $r['activity_id'] ) && ! Labels::is_transfer( $r['type'] ) && ! in_array( (int) $r['account_id'], $funds, true );
			}
		);
		$group = function ( string $type ) use ( $general ) {
			$by = array();
			foreach ( $general as $r ) {
				if ( $r['type'] === $type ) {
					$by[ $r['category_id'] ]['name']   = $r['category_name'];
					$by[ $r['category_id'] ]['cents'] = ( $by[ $r['category_id'] ]['cents'] ?? 0 ) + (int) $r['amount_cents'];
				}
			}
			return array_values( $by );
		};
		$gi = $group( 'income' );
		$ge = $group( 'expense' );
		$ti = array_sum( array_column( $summaries, 'income' ) ) + array_sum( array_column( $gi, 'cents' ) );
		$te = array_sum( array_column( $summaries, 'cost' ) ) + array_sum( array_column( $ge, 'cents' ) );

		return array(
			'year' => $year, 'activities' => $summaries, 'members_by_type' => Plugin::people()->count_by_type_for_year( $year ),
			'general_income' => $gi, 'general_expenses' => $ge, 'total_income' => $ti, 'total_expense' => $te, 'result' => $ti - $te,
		);
	}
}
