<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Prima nota, conti e cassa.
 *
 * Regole:
 *  - importi in centesimi, sempre positivi; il segno viene dal tipo di movimento;
 *  - il saldo di un conto è calcolato (saldo iniziale + movimenti), mai memorizzato;
 *  - i movimenti non si modificano: si annullano (voided_at + motivo) e si reinseriscono;
 *  - un giroconto sono due righe con lo stesso transfer_id: non è né entrata né uscita;
 *  - un incasso con più voci sono più righe con lo stesso receipt_id.
 */
class LedgerService {

	private function db(): \wpdb {
		return Db::db();
	}

	// ---------- Conti e categorie ----------

	public function accounts(): array {
		return $this->db()->get_results( 'SELECT * FROM ' . Db::t( 'accounts' ) . ' WHERE deleted_at IS NULL ORDER BY sort_order, name', ARRAY_A ) ?: array();
	}

	public function account( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'accounts' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	public function add_account( string $name, string $type, int $opening_cents ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Il nome del conto è obbligatorio.' );
		}
		if ( ! isset( Labels::account_types()[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Tipo di conto non valido.' );
		}
		$this->db()->insert( Db::t( 'accounts' ), array( 'name' => $name, 'type' => $type, 'opening_cents' => $opening_cents, 'sort_order' => count( $this->accounts() ) ) );
		return (int) $this->db()->insert_id;
	}

	/** Conto "Stripe" / "PayPal" in cui entrano gli incassi online (creato al primo pagamento). */
	public function online_account( string $provider ): int {
		$name = 'paypal' === $provider ? 'PayPal' : 'Stripe';
		foreach ( $this->accounts() as $a ) {
			if ( $a['name'] === $name ) {
				return (int) $a['id'];
			}
		}
		return $this->add_account( $name, 'other', 0 );
	}

	public function categories(): array {
		return $this->db()->get_results( 'SELECT * FROM ' . Db::t( 'categories' ) . ' WHERE deleted_at IS NULL ORDER BY name', ARRAY_A ) ?: array();
	}

	private function category( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'categories' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	public function category_id_of_kind( string $kind ): int {
		return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT id FROM ' . Db::t( 'categories' ) . ' WHERE kind = %s AND deleted_at IS NULL LIMIT 1', $kind ) );
	}

	/** Conto più adatto alla modalità di pagamento: contanti->cassa, POS->conto POS, altro->banca. */
	public function default_account_for( string $method ): ?array {
		$wanted = 'cash' === $method ? 'cash' : ( 'pos' === $method ? 'pos' : 'bank' );
		$all    = $this->accounts();
		foreach ( $all as $a ) {
			if ( $a['type'] === $wanted ) {
				return $a;
			}
		}
		return $all[0] ?? null;
	}

	// ---------- Saldi ----------

	private function deltas( ?string $up_to ): array {
		$sql = "SELECT account_id, SUM(CASE WHEN type IN ('income','transfer_in') THEN amount_cents ELSE -amount_cents END) AS delta "
			. 'FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL';
		if ( null !== $up_to ) {
			$sql = $this->db()->prepare( $sql . ' AND tx_date <= %s', $up_to );
		}
		$out = array();
		foreach ( $this->db()->get_results( $sql . ' GROUP BY account_id', ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['account_id'] ] = (int) $r['delta'];
		}
		return $out;
	}

	/** Conti con il saldo calcolato (a oggi, oppure fino a una data inclusa). */
	public function balances( ?string $up_to = null ): array {
		$deltas = $this->deltas( $up_to );
		$out    = array();
		foreach ( $this->accounts() as $a ) {
			$a['balance'] = (int) $a['opening_cents'] + ( $deltas[ (int) $a['id'] ] ?? 0 );
			$out[]        = $a;
		}
		return $out;
	}

	public function expected_balance( int $account_id, string $up_to ): int {
		foreach ( $this->balances( $up_to ) as $a ) {
			if ( (int) $a['id'] === $account_id ) {
				return $a['balance'];
			}
		}
		return 0;
	}

	// ---------- Registrazione ----------

	private function assert_date( string $d ): void {
		$dt = \DateTime::createFromFormat( 'Y-m-d', $d );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $d ) {
			throw new \InvalidArgumentException( 'Data non valida.' );
		}
	}

	private function assert_account_method( int $account_id, string $method ): void {
		if ( ! $this->account( $account_id ) ) {
			throw new \InvalidArgumentException( 'Conto non valido.' );
		}
		if ( ! isset( Labels::methods()[ $method ] ) ) {
			throw new \InvalidArgumentException( 'Modalità di pagamento non valida.' );
		}
	}

	private function tx_defaults( array $extra ): array {
		return array_merge(
			array(
				'description' => '', 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now(),
			),
			$extra
		);
	}

	private function insert_tx( array $row ): int {
		if ( ! $this->db()->insert( Db::t( 'transactions' ), $this->tx_defaults( $row ) ) ) {
			throw new \RuntimeException( 'Errore del database nel salvare il movimento.' );
		}
		$id = (int) $this->db()->insert_id;
		Audit::log( 'tx.created', 'transaction', $id, array( 'type' => $row['type'], 'cents' => (int) $row['amount_cents'], 'account' => (int) $row['account_id'] ) );
		return $id;
	}

	private function in_transaction( callable $fn ) {
		$this->db()->query( 'START TRANSACTION' );
		try {
			$res = $fn();
			$this->db()->query( 'COMMIT' );
			return $res;
		} catch ( \Throwable $e ) {
			$this->db()->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Incasso da una persona: una o più voci, un solo conto e una sola modalità.
	 *
	 * @param array $d date, account_id, method, person_id?, document_ref?, lines[]: category_id, amount_cents,
	 *                 activity_id?, competence_month?, social_year?, description?
	 * @return int numero di righe registrate
	 */
	public function record_receipt( array $d ): int {
		$date = (string) ( $d['date'] ?? '' );
		$this->assert_date( $date );
		$this->assert_account_method( (int) ( $d['account_id'] ?? 0 ), (string) ( $d['method'] ?? '' ) );
		$lines = $d['lines'] ?? array();
		if ( ! $lines ) {
			throw new \InvalidArgumentException( 'Aggiungi almeno una voce all\'incasso.' );
		}
		$person = ! empty( $d['person_id'] ) ? Plugin::people()->get( (int) $d['person_id'] ) : null;
		if ( ! empty( $d['person_id'] ) && ! $person ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}

		// Validazione completa prima di scrivere
		$prepared = array();
		foreach ( $lines as $i => $l ) {
			$n    = $i + 1;
			$cat  = $this->category( (int) ( $l['category_id'] ?? 0 ) );
			$cents = (int) ( $l['amount_cents'] ?? 0 );
			if ( ! $cat || ! Labels::category_kinds()[ $cat['kind'] ][1] || 'adjustment' === $cat['kind'] ) {
				throw new \InvalidArgumentException( "Voce $n: categoria non valida per un incasso." );
			}
			if ( $cents <= 0 ) {
				throw new \InvalidArgumentException( "Voce $n: importo non valido." );
			}
			if ( ! empty( $l['competence_month'] ) && ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $l['competence_month'] ) ) {
				throw new \InvalidArgumentException( "Voce $n: mese di competenza non valido." );
			}
			$activity_id = ! empty( $l['activity_id'] ) ? (int) $l['activity_id'] : null;
			$session_id  = ! empty( $l['session_id'] ) ? (int) $l['session_id'] : null;
			$activity    = $activity_id ? Plugin::activities()->get( $activity_id ) : null;
			if ( $activity_id && ! $activity ) {
				throw new \InvalidArgumentException( "Voce $n: attività non trovata." );
			}
			if ( $activity_id && ! $person ) {
				throw new \InvalidArgumentException( "Voce $n: indica chi paga il contributo dell'attività." );
			}
			if ( $session_id && ! $activity_id ) {
				throw new \InvalidArgumentException( "Voce $n: la data dell'evento richiede l'attività." );
			}
			if ( $activity && ActivityKind::uses_sessions( $activity['kind'] ) ) {
				// Eventi: il contributo si paga per una data a cui la persona è prenotata
				$session = $session_id ? Plugin::activities()->session( $session_id ) : null;
				if ( ! $session || (int) $session['activity_id'] !== $activity_id ) {
					throw new \InvalidArgumentException( "Voce $n: indica a quale data dell'evento si riferisce il contributo." );
				}
				if ( ! Plugin::activities()->has_active_booking( $session_id, (int) $person['id'] ) ) {
					throw new \InvalidArgumentException( "Voce $n: la persona non è prenotata a questa data." );
				}
			} elseif ( $session_id ) {
				throw new \InvalidArgumentException( "Voce $n: i corsi non hanno date: indica il mese di competenza." );
			}
			$social_year = null;
			if ( 'membership' === $cat['kind'] ) {
				if ( ! $person ) {
					throw new \InvalidArgumentException( "Voce $n: la quota associativa richiede di indicare il socio." );
				}
				if ( MemberType::GUEST === $person['type'] ) {
					throw new \InvalidArgumentException( "Voce $n: un ospite non è socio e non paga la quota associativa." );
				}
				if ( MemberType::is_auto_renewed( $person['type'] ) ) {
					throw new \InvalidArgumentException( "Voce $n: il socio fondatore ha la tessera sempre rinnovata." );
				}
				$social_year = ! empty( $l['social_year'] ) ? $l['social_year'] : Settings::social_year( $date )->label();
			}
			$prepared[] = array( 'cat' => $cat, 'cents' => $cents, 'activity_id' => $activity_id, 'session_id' => $session_id, 'social_year' => $social_year, 'line' => $l );
		}

		$receipt_id = wp_generate_uuid4();
		return $this->in_transaction(
			function () use ( $prepared, $d, $date, $person, $receipt_id ) {
				foreach ( $prepared as $p ) {
					$tx_id = $this->insert_tx(
						array(
							'tx_date'          => $date,
							'type'             => 'income',
							'amount_cents'     => $p['cents'],
							'account_id'       => (int) $d['account_id'],
							'method'           => $d['method'],
							'category_id'      => (int) $p['cat']['id'],
							'activity_id'      => $p['activity_id'],
							'session_id'       => $p['session_id'],
							'person_id'        => $person ? (int) $person['id'] : null,
							'description'      => substr( (string) ( $p['line']['description'] ?? '' ), 0, 255 ),
							'competence_month' => ! empty( $p['line']['competence_month'] ) ? $p['line']['competence_month'] : null,
							'social_year'      => $p['social_year'],
							'document_ref'     => ! empty( $d['document_ref'] ) ? substr( trim( $d['document_ref'] ), 0, 80 ) : null,
							'receipt_id'       => $receipt_id,
						)
					);
					if ( 'membership' === $p['cat']['kind'] ) {
						Plugin::people()->set_membership( (int) $person['id'], $p['social_year'], true, 'payment', $tx_id );
					}
				}
				return count( $prepared );
			}
		);
	}

	/** @param array $d date, account_id, method, category_id, amount_cents, activity_id?, person_id?, description?, document_ref? */
	public function record_expense( array $d ): int {
		$date = (string) ( $d['date'] ?? '' );
		$this->assert_date( $date );
		$this->assert_account_method( (int) ( $d['account_id'] ?? 0 ), (string) ( $d['method'] ?? '' ) );
		$cat = $this->category( (int) ( $d['category_id'] ?? 0 ) );
		if ( ! $cat || ! Labels::category_kinds()[ $cat['kind'] ][2] || 'adjustment' === $cat['kind'] ) {
			throw new \InvalidArgumentException( 'Categoria non valida per una spesa.' );
		}
		if ( (int) ( $d['amount_cents'] ?? 0 ) <= 0 ) {
			throw new \InvalidArgumentException( 'Importo non valido.' );
		}
		if ( ! empty( $d['activity_id'] ) && ! Plugin::activities()->get( (int) $d['activity_id'] ) ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		return $this->insert_tx(
			array(
				'tx_date'      => $date,
				'type'         => 'expense',
				'amount_cents' => (int) $d['amount_cents'],
				'account_id'   => (int) $d['account_id'],
				'method'       => $d['method'],
				'category_id'  => (int) $cat['id'],
				'activity_id'  => ! empty( $d['activity_id'] ) ? (int) $d['activity_id'] : null,
				'person_id'    => ! empty( $d['person_id'] ) ? (int) $d['person_id'] : null,
				'description'  => substr( trim( (string) ( $d['description'] ?? '' ) ), 0, 255 ),
				'document_ref' => ! empty( $d['document_ref'] ) ? substr( trim( $d['document_ref'] ), 0, 80 ) : null,
			)
		);
	}

	/** Giroconto tra due conti (versamento contanti in banca, accredito POS...). */
	public function record_transfer( string $date, int $from_id, int $to_id, int $cents, string $method, string $description = '' ): void {
		$this->assert_date( $date );
		if ( $from_id === $to_id ) {
			throw new \InvalidArgumentException( 'Scegli due conti diversi.' );
		}
		$this->assert_account_method( $from_id, $method );
		$this->assert_account_method( $to_id, $method );
		if ( $cents <= 0 ) {
			throw new \InvalidArgumentException( 'Importo non valido.' );
		}
		$cat      = $this->category_id_of_kind( 'adjustment' ); // segnaposto neutro per le righe di giroconto
		$transfer = wp_generate_uuid4();
		$this->in_transaction(
			function () use ( $date, $from_id, $to_id, $cents, $method, $description, $cat, $transfer ) {
				foreach ( array( array( 'transfer_out', $from_id ), array( 'transfer_in', $to_id ) ) as $leg ) {
					$this->insert_tx(
						array(
							'tx_date' => $date, 'type' => $leg[0], 'amount_cents' => $cents, 'account_id' => $leg[1], 'method' => $method,
							'category_id' => $cat, 'description' => substr( $description, 0, 255 ), 'transfer_id' => $transfer,
						)
					);
				}
			}
		);
	}

	/** Annulla (senza cancellare) un movimento; per i giroconti annulla entrambe le righe. */
	public function void( int $tx_id, string $reason ): void {
		$tbl = Db::t( 'transactions' );
		$tx  = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM $tbl WHERE id = %d AND voided_at IS NULL", $tx_id ), ARRAY_A );
		if ( ! $tx ) {
			throw new \InvalidArgumentException( 'Movimento non trovato o già annullato.' );
		}
		$ids = array( $tx_id );
		if ( ! empty( $tx['transfer_id'] ) ) {
			$ids = array_map( 'intval', $this->db()->get_col( $this->db()->prepare( "SELECT id FROM $tbl WHERE transfer_id = %s AND voided_at IS NULL", $tx['transfer_id'] ) ) );
		}
		$reason = '' === trim( $reason ) ? 'Annullato' : substr( trim( $reason ), 0, 255 );
		$this->in_transaction(
			function () use ( $ids, $reason, $tbl ) {
				foreach ( $ids as $id ) {
					$this->db()->update( $tbl, array( 'voided_at' => Db::now(), 'void_reason' => $reason ), array( 'id' => $id ) );
					Plugin::people()->remove_membership_of_transaction( $id );
					Audit::log( 'tx.voided', 'transaction', $id, array( 'reason' => $reason ) );
				}
			}
		);
	}

	// ---------- Verifica cassa ----------

	/**
	 * Confronta il saldo dell'app con quello reale. Con $adjust crea la rettifica che riallinea l'app.
	 *
	 * @return int differenza (reale - app) in centesimi
	 */
	public function record_cash_count( int $account_id, string $date, int $counted_cents, bool $adjust, ?string $notes = null ): int {
		$this->assert_date( $date );
		$account = $this->account( $account_id );
		if ( ! $account ) {
			throw new \InvalidArgumentException( 'Conto non valido.' );
		}
		return $this->in_transaction(
			function () use ( $account, $account_id, $date, $counted_cents, $adjust, $notes ) {
				$expected = $this->expected_balance( $account_id, $date );
				$diff     = $counted_cents - $expected;
				$adj_id   = null;
				if ( $adjust && 0 !== $diff ) {
					$adj_id = $this->insert_tx(
						array(
							'tx_date' => $date, 'type' => $diff > 0 ? 'income' : 'expense', 'amount_cents' => abs( $diff ),
							'account_id' => $account_id, 'method' => 'cash' === $account['type'] ? 'cash' : 'other',
							'category_id' => $this->category_id_of_kind( 'adjustment' ), 'description' => 'Rettifica da verifica saldo',
						)
					);
				}
				$this->db()->insert(
					Db::t( 'cash_counts' ),
					array(
						'account_id' => $account_id, 'count_date' => $date, 'counted_cents' => $counted_cents, 'expected_cents' => $expected,
						'difference_cents' => $diff, 'adjustment_tx_id' => $adj_id, 'notes' => $notes, 'created_at' => Db::now(),
					)
				);
				Audit::log( 'cashcount.recorded', 'account', $account_id, array( 'difference' => $diff, 'adjusted' => null !== $adj_id ) );
				return $diff;
			}
		);
	}

	// ---------- Lettura ----------

	/** Prima nota con i nomi risolti. $asc = ordine cronologico (per gli export). */
	public function rows( string $from, string $to, ?int $account_id = null, bool $asc = false ): array {
		$sql  = 'SELECT t.*, a.name AS account_name, c.name AS category_name, CONCAT(p.first_name, " ", p.last_name) AS person_name, '
			. 'p.card_number AS person_card, act.name AS activity_name FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id '
			. 'JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = t.person_id '
			. 'LEFT JOIN ' . Db::t( 'activities' ) . ' act ON act.id = t.activity_id '
			. 'WHERE t.voided_at IS NULL AND t.tx_date BETWEEN %s AND %s';
		$args = array( $from, $to );
		if ( $account_id ) {
			$sql   .= ' AND t.account_id = %d';
			$args[] = $account_id;
		}
		$sql .= $asc ? ' ORDER BY t.tx_date, t.id' : ' ORDER BY t.tx_date DESC, t.id DESC';
		return $this->db()->get_results( $this->db()->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/** Ultime spese (non annullate) registrate da un utente: serve al tesoriere, che non vede il resto della prima nota. */
	public function expenses_by_user( int $user_id, int $limit = 15 ): array {
		$sql = 'SELECT t.*, c.name AS category_name, a.name AS account_name FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id '
			. "WHERE t.voided_at IS NULL AND t.type = 'expense' AND t.created_by = %d ORDER BY t.tx_date DESC, t.id DESC LIMIT %d";
		return $this->db()->get_results( $this->db()->prepare( $sql, $user_id, max( 1, $limit ) ), ARRAY_A ) ?: array();
	}

	/** Movimenti (non annullati) di una persona. */
	public function rows_of_person( int $person_id ): array {
		$sql = 'SELECT t.*, a.name AS account_name, c.name AS category_name, act.name AS activity_name FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'LEFT JOIN ' . Db::t( 'activities' ) . ' act ON act.id = t.activity_id '
			. 'WHERE t.voided_at IS NULL AND t.person_id = %d ORDER BY t.tx_date DESC, t.id DESC';
		return $this->db()->get_results( $this->db()->prepare( $sql, $person_id ), ARRAY_A ) ?: array();
	}
}
