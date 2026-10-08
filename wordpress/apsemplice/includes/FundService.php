<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Fondi: quote accantonate dai pagamenti di corsi ed eventi per rimborsare il volontario ("Rimborso Mario — Yoga").
 * Il pagamento resta nel conto in cui è entrato (la cassa torna con il contante contato): il fondo è un accantonamento
 * e la disponibilità reale dell'associazione è la somma dei saldi meno i fondi.
 * Saldo di un fondo = quote accantonate dai pagamenti non annullati − quote liberate − rimborsi pagati.
 */
class FundService {

	private function db(): \wpdb {
		return Db::db();
	}

	private function balance_sql( string $where = '1=1' ): string {
		$e = Db::t( 'fund_entries' );
		$t = Db::t( 'transactions' );
		return "SELECT e.fund_id, SUM(CASE WHEN e.kind = 'share' THEN e.cents ELSE -e.cents END) AS bal FROM $e e "
			. "LEFT JOIN $t t ON t.id = e.tx_id WHERE (e.tx_id IS NULL OR t.voided_at IS NULL) AND $where GROUP BY e.fund_id";
	}

	/** Fondi con il saldo (di default solo quelli aperti). @return array[] fondo + 'balance' */
	public function all( bool $with_closed = false ): array {
		$funds = $this->db()->get_results( 'SELECT * FROM ' . Db::t( 'funds' ) . ( $with_closed ? '' : ' WHERE closed_at IS NULL' ) . ' ORDER BY name', ARRAY_A ) ?: array();
		$bal   = array();
		foreach ( $this->db()->get_results( $this->balance_sql(), ARRAY_A ) ?: array() as $r ) {
			$bal[ (int) $r['fund_id'] ] = (int) $r['bal'];
		}
		foreach ( $funds as &$f ) {
			$f['balance'] = $bal[ (int) $f['id'] ] ?? 0;
		}
		return $funds;
	}

	public function get( int $id ): ?array {
		foreach ( $this->all( true ) as $f ) {
			if ( (int) $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/** Totale accantonato nei fondi (a oggi o fino a una data inclusa). */
	public function total( ?string $up_to = null ): int {
		$where = null === $up_to ? '1=1' : $this->db()->prepare( 'e.entry_date <= %s', $up_to );
		return (int) array_sum(
			array_map(
				function ( $r ) {
					return (int) $r['bal'];
				},
				$this->db()->get_results( $this->balance_sql( $where ), ARRAY_A ) ?: array()
			)
		);
	}

	/** Disponibilità reale: saldi dei conti meno i fondi (a oggi o a una data). */
	public function available( ?string $up_to = null ): array {
		$accounts = (int) array_sum( array_column( Plugin::ledger()->balances( $up_to, true ), 'balance' ) );
		$funds    = $this->total( $up_to );
		return array( 'accounts' => $accounts, 'funds' => $funds, 'available' => $accounts - $funds );
	}

	private function open_fund_for( int $activity_id, array $activity ): int {
		$id = (int) $this->db()->get_var( $this->db()->prepare( 'SELECT id FROM ' . Db::t( 'funds' ) . ' WHERE activity_id = %d AND closed_at IS NULL ORDER BY id LIMIT 1', $activity_id ) );
		if ( $id ) {
			return $id;
		}
		$vol  = ! empty( $activity['instructor_person_id'] ) ? Plugin::people()->get( (int) $activity['instructor_person_id'] ) : null;
		$name = 'Rimborso ' . ( $vol ? trim( $vol['first_name'] . ' ' . $vol['last_name'] ) : 'volontario' ) . ' — ' . $activity['name'];
		$this->db()->insert( Db::t( 'funds' ), array( 'name' => mb_substr( $name, 0, 200 ), 'activity_id' => $activity_id, 'person_id' => $vol ? (int) $vol['id'] : null, 'created_at' => Db::now() ) );
		$id = (int) $this->db()->insert_id;
		Audit::log( 'fund.created', 'fund', $id );
		return $id;
	}

	/** Accantona la quota di un pagamento ricevuto (incasso $tx_id di $line_cents su $activity). @return int centesimi accantonati */
	public function attribute( int $tx_id, array $activity, int $line_cents, string $date ): int {
		$cents = FundShare::cents( (string) ( $activity['fund_mode'] ?? '' ), (int) ( $activity['fund_value'] ?? 0 ), $line_cents );
		if ( $cents <= 0 || empty( $activity['instructor_person_id'] ) ) {
			return 0;
		}
		$fund = $this->open_fund_for( (int) $activity['id'], $activity );
		$this->db()->insert( Db::t( 'fund_entries' ), array( 'fund_id' => $fund, 'kind' => 'share', 'cents' => $cents, 'tx_id' => $tx_id, 'entry_date' => $date, 'note' => 'Quota del pagamento', 'created_at' => Db::now() ) );
		return $cents;
	}

	/** Crea un fondo a mano (ad esempio "Gita sociale"): facoltativamente con una somma già accantonata. */
	public function create( string $name, int $initial_cents = 0, ?int $person_id = null, string $date = '' ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Dai un nome al fondo.' );
		}
		if ( $initial_cents < 0 ) {
			throw new \InvalidArgumentException( 'L\'importo non può essere negativo.' );
		}
		if ( $person_id && ! Plugin::people()->get( $person_id ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$this->db()->insert( Db::t( 'funds' ), array( 'name' => mb_substr( $name, 0, 200 ), 'person_id' => $person_id ?: null, 'created_at' => Db::now() ) );
		$id = (int) $this->db()->insert_id;
		Audit::log( 'fund.created', 'fund', $id );
		if ( $initial_cents > 0 ) {
			$this->deposit( $id, $initial_cents, '' !== $date ? $date : Db::today(), 'Somma iniziale' );
		}
		return $id;
	}

	/** Accantona a mano una somma in un fondo (si sottrae dalla disponibilità reale). */
	public function deposit( int $fund_id, int $cents, string $date, string $note = '' ): void {
		$f = $this->get( $fund_id );
		if ( ! $f || null !== $f['closed_at'] ) {
			throw new \InvalidArgumentException( 'Fondo non trovato o già estinto.' );
		}
		if ( $cents <= 0 ) {
			throw new \InvalidArgumentException( 'Indica un importo maggiore di zero.' );
		}
		$this->db()->insert( Db::t( 'fund_entries' ), array( 'fund_id' => $fund_id, 'kind' => 'share', 'cents' => $cents, 'entry_date' => $date, 'note' => mb_substr( '' !== $note ? $note : 'Somma accantonata', 0, 255 ), 'created_at' => Db::now() ) );
		Audit::log( 'fund.deposited', 'fund', $fund_id, array( 'cents' => $cents ) );
	}

	/** Movimenti dei fondi (quelli di pagamenti annullati non contano), con l'anno solare in cui sono stati registrati. @return array[] fund_id, kind, cents, year */
	private function year_entries(): array {
		$e = Db::t( 'fund_entries' );
		$t = Db::t( 'transactions' );
		return $this->db()->get_results(
			"SELECT e.fund_id, e.kind, e.cents, YEAR(e.entry_date) AS year FROM $e e LEFT JOIN $t t ON t.id = e.tx_id WHERE (e.tx_id IS NULL OR t.voided_at IS NULL) ORDER BY e.entry_date, e.id",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Per ogni fondo e per ogni anno solare: accantonato, già rimborsato o liberato, ancora da rimborsare.
	 *
	 * @return array ['per_fund' => [fund_id => [anno => [accrued, settled, unsettled]]], 'totals' => [anno => [...]]]
	 */
	public function yearly(): array {
		$by = array();
		foreach ( $this->year_entries() as $r ) {
			$by[ (int) $r['fund_id'] ][] = $r;
		}
		$per = array();
		foreach ( $by as $fid => $rows ) {
			$per[ $fid ] = FundYears::by_year( $rows );
		}
		return array( 'per_fund' => $per, 'totals' => FundYears::totals( $per ) );
	}

	/** Quanto resta da rimborsare degli accantonamenti fatti fino a quell'anno solare (compreso): se è più di zero, quell'anno non si chiude. */
	public function unsettled_until_year( int $year ): int {
		$sum = 0;
		foreach ( $this->yearly()['totals'] as $y => $r ) {
			if ( (int) $y <= $year ) {
				$sum += (int) $r['unsettled'];
			}
		}
		return $sum;
	}

	/** Totale accantonato (quote dei pagamenti e somme messe a mano) con data nel periodo. */
	public function accrued_in( string $from, string $to ): int {
		return (int) $this->db()->get_var( $this->db()->prepare( $this->sum_sql( "e.kind = 'share'" ), $from, $to ) );
	}

	/** Totale liberato (tornato nella disponibilità dell'associazione) con data nel periodo. */
	public function released_in( string $from, string $to ): int {
		return (int) $this->db()->get_var( $this->db()->prepare( $this->sum_sql( "e.kind = 'release'" ), $from, $to ) );
	}

	private function sum_sql( string $kind_cond ): string {
		$e = Db::t( 'fund_entries' );
		$t = Db::t( 'transactions' );
		return "SELECT COALESCE(SUM(e.cents), 0) FROM $e e LEFT JOIN $t t ON t.id = e.tx_id WHERE (e.tx_id IS NULL OR t.voided_at IS NULL) AND $kind_cond AND e.entry_date BETWEEN %s AND %s";
	}

	/** Uscite di rimborso già conteggiate come accantonamento: id del movimento => centesimi (per non contarle due volte nel rendiconto dell'anno in cui si pagano). */
	public function payout_transactions(): array {
		$e   = Db::t( 'fund_entries' );
		$t   = Db::t( 'transactions' );
		$out = array();
		foreach ( $this->db()->get_results( "SELECT e.tx_id, e.cents FROM $e e JOIN $t t ON t.id = e.tx_id AND t.voided_at IS NULL WHERE e.kind = 'payout'", ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['tx_id'] ] = (int) $r['cents'];
		}
		return $out;
	}

	/** Libera parte del fondo: torna nella disponibilità dell'associazione (nessun movimento di cassa). */
	public function release( int $fund_id, int $cents, string $date, string $note = '' ): void {
		$f = $this->get( $fund_id );
		if ( ! $f || null !== $f['closed_at'] ) {
			throw new \InvalidArgumentException( 'Fondo non trovato o già estinto.' );
		}
		if ( $cents <= 0 || $cents > $f['balance'] ) {
			throw new \InvalidArgumentException( 'Puoi liberare da zero fino a ' . Money::format( $f['balance'] ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$this->db()->insert( Db::t( 'fund_entries' ), array( 'fund_id' => $fund_id, 'kind' => 'release', 'cents' => $cents, 'entry_date' => $date, 'note' => mb_substr( '' !== $note ? $note : 'Quota liberata', 0, 255 ), 'created_at' => Db::now() ) );
		Audit::log( 'fund.released', 'fund', $fund_id, array( 'cents' => $cents ) );
	}

	/**
	 * Estingue il fondo: registra in prima nota l'uscita del rimborso (dal conto indicato) e chiude il fondo.
	 *
	 * @return int id dell'uscita in prima nota (0 se il fondo era a zero)
	 */
	public function settle( int $fund_id, int $account_id, string $method, string $date ): int {
		// Sotto blocco (due richieste insieme non pagano due volte lo stesso rimborso) e in un'unica transazione (uscita, voce del fondo e chiusura: tutto o niente).
		return (int) Db::with_lock(
			'apse_fund_settle_' . $fund_id,
			function () use ( $fund_id, $account_id, $method, $date ) {
				$ledger = Plugin::ledger();
				return $ledger->in_batch(
					function () use ( $ledger, $fund_id, $account_id, $method, $date ) {
						$f = $this->get( $fund_id );
						if ( ! $f || null !== $f['closed_at'] ) {
							throw new \InvalidArgumentException( 'Fondo non trovato o già estinto.' );
						}
						$tx_id = 0;
						if ( $f['balance'] > 0 ) {
							$tx_id = $ledger->record_expense(
								array(
									'date' => $date, 'account_id' => $account_id, 'method' => $method, 'category_id' => $ledger->category_id_of_kind( $f['person_id'] ? 'member_reimbursement' : 'general_cost' ),
									'amount_cents' => $f['balance'], 'activity_id' => $f['activity_id'] ? (int) $f['activity_id'] : null, 'person_id' => $f['person_id'] ? (int) $f['person_id'] : null,
									'description' => mb_substr( $f['name'] . ' (fondo estinto)', 0, 255 ),
								)
							);
							$this->db()->insert( Db::t( 'fund_entries' ), array( 'fund_id' => $fund_id, 'kind' => 'payout', 'cents' => $f['balance'], 'tx_id' => $tx_id, 'entry_date' => $date, 'note' => 'Rimborso pagato', 'created_at' => Db::now() ) );
						}
						$this->db()->update( Db::t( 'funds' ), array( 'closed_at' => Db::now() ), array( 'id' => $fund_id ) );
						Audit::log( 'fund.settled', 'fund', $fund_id, array( 'cents' => $f['balance'], 'tx' => $tx_id ) );
						return $tx_id;
					}
				);
			}
		);
	}
}
