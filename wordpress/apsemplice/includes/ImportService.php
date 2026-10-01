<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Import da Excel/CSV (e dall'area di appoggio di WP All Import): soci, ospiti e prima nota.
 * Prima si costruisce un'anteprima (nulla viene scritto), poi si applica.
 */
final class ImportService {

	const MAX_ROWS = 5000;

	// ---------- Anteprima ----------

	/**
	 * @param array $opts default_type, default_account_id
	 * @return array anteprima: people {plan}, ledger {plan, summary}, ignored (nomi dei fogli non riconosciuti)
	 * @throws \InvalidArgumentException
	 */
	public static function preview_file( string $path, string $name, array $opts = array() ): array {
		return self::preview_sheets( SheetReader::read( $path, $name ), $opts );
	}

	/** @param array[] $sheets [ ['name','rows','lines'], ... ] */
	public static function preview_sheets( array $sheets, array $opts = array() ): array {
		$people_rows = array();
		$ledger_rows = array();
		$ignored     = array();
		foreach ( $sheets as $sh ) {
			$role = SheetReader::role( $sh );
			if ( SheetReader::ROLE_PEOPLE === $role ) {
				$p = PeopleCsv::parse_table( $sh['rows'], $sh['lines'] ?? null, (string) $sh['name'] );
				if ( isset( $p['error'] ) ) {
					throw new \InvalidArgumentException( $p['error'] );
				}
				$people_rows = array_merge( $people_rows, $p['rows'] );
			} elseif ( SheetReader::ROLE_LEDGER === $role ) {
				$l = LedgerImport::parse_table( $sh['rows'], $sh['lines'] ?? null, (string) $sh['name'] );
				if ( isset( $l['error'] ) ) {
					throw new \InvalidArgumentException( $l['error'] );
				}
				$ledger_rows = array_merge( $ledger_rows, $l['rows'] );
			} else {
				$ignored[] = (string) $sh['name'];
			}
		}
		if ( ! $people_rows && ! $ledger_rows ) {
			throw new \InvalidArgumentException(
				'Non ho trovato né soci né movimenti. Per i soci servono le colonne "Nome" e "Cognome"; per la prima nota "Data" e "Importo" (oppure "Entrata" e "Uscita"). Fogli letti: ' . ( $ignored ? implode( ', ', $ignored ) : 'nessuno' ) . '.'
			);
		}
		if ( count( $people_rows ) > self::MAX_ROWS || count( $ledger_rows ) > self::MAX_ROWS ) {
			throw new \InvalidArgumentException( 'Il file ha più di ' . self::MAX_ROWS . ' righe per tipo: dividilo in più file.' );
		}
		$out = array( 'people' => null, 'ledger' => null, 'ignored' => $ignored );
		if ( $people_rows ) {
			$default = MemberType::is_member( (string) ( $opts['default_type'] ?? '' ) ) ? $opts['default_type'] : MemberType::ORDINARY;
			$out['people'] = array( 'plan' => PeopleCsv::plan( $people_rows, self::existing_people(), $default ) );
		}
		if ( $ledger_rows ) {
			$plan             = LedgerImport::plan( $ledger_rows, self::ledger_context( $ledger_rows, (int) ( $opts['default_account_id'] ?? 0 ) ) );
			$names            = array_column( Plugin::ledger()->accounts(), 'name', 'id' );
			$out['ledger']    = array( 'plan' => $plan, 'summary' => LedgerImport::summary( $plan, array_map( 'strval', $names ) ) );
		}
		return $out;
	}

	private static function existing_people(): array {
		$out = array();
		foreach ( Plugin::people()->search() as $e ) {
			$out[] = array(
				'id' => (int) $e['id'], 'card' => $e['card_number'], 'first' => $e['first_name'], 'last' => $e['last_name'], 'email' => $e['email'],
				'tax' => $e['tax_code'], 'type' => $e['type'], 'host_id' => (int) $e['host_person_id'],
			);
		}
		return $out;
	}

	private static function ledger_context( array $rows, int $default_account_id ): array {
		$ledger = Plugin::ledger();
		$from   = null;
		$to     = null;
		foreach ( $rows as $r ) {
			if ( $r['date'] ) {
				$from = null === $from || $r['date'] < $from ? $r['date'] : $from;
				$to   = null === $to || $r['date'] > $to ? $r['date'] : $to;
			}
		}
		$people = array();
		foreach ( Plugin::people()->search() as $p ) {
			$people[] = array( 'id' => (int) $p['id'], 'card' => $p['card_number'], 'first' => $p['first_name'], 'last' => $p['last_name'] );
		}
		return array(
			'accounts'           => array_map( function ( $a ) {
				return array( 'id' => (int) $a['id'], 'name' => $a['name'], 'type' => $a['type'] );
			}, $ledger->accounts() ),
			'categories'         => array_map( function ( $c ) {
				return array( 'id' => (int) $c['id'], 'name' => $c['name'], 'kind' => $c['kind'] );
			}, $ledger->categories() ),
			'people'             => $people,
			'activities'         => Plugin::activities()->all_for_select(),
			'existing'           => $from ? $ledger->import_keys( $from, $to ) : array(),
			'default_account_id' => $default_account_id,
		);
	}

	// ---------- Applicazione ----------

	/**
	 * @param array $preview anteprima di preview_sheets()
	 * @param array $opts    mark_members (bool), keep_balances (bool)
	 * @return array people {created, updated, failed[]}, ledger {created, transfers, duplicates, skipped, failed[], memberships}
	 */
	public static function apply( array $preview, array $opts = array() ): array {
		$res = array( 'people' => null, 'ledger' => null );
		if ( ! empty( $preview['people'] ) ) {
			$res['people'] = self::apply_people( $preview['people']['plan'], ! empty( $opts['mark_members'] ) );
		}
		if ( ! empty( $preview['ledger'] ) ) {
			$res['ledger'] = self::apply_ledger( $preview['ledger']['plan'], ! empty( $opts['keep_balances'] ) );
		}
		return $res;
	}

	private static function apply_people( array $plan, bool $mark ): array {
		$year    = Settings::social_year()->label();
		$people  = Plugin::people();
		$created = 0;
		$updated = 0;
		$failed  = array();
		$order   = array();
		foreach ( $plan as $row ) {
			if ( 'error' === $row['action'] ) {
				continue;
			}
			$order[ MemberType::GUEST === $row['type'] ? 1 : 0 ][] = $row; // prima i soci, poi gli ospiti (l'ospitante può essere nuovo)
		}
		foreach ( array( 0, 1 ) as $pass ) {
			foreach ( $order[ $pass ] ?? array() as $row ) {
				$r    = $row['row'];
				$data = array( 'first_name' => $r['first'], 'last_name' => $r['last'] );
				if ( null !== $r['email'] ) {
					$data['email'] = $r['email'];
				}
				foreach ( array( 'card' => 'card_number', 'phone' => 'phone', 'tax' => 'tax_code' ) as $from => $to ) {
					if ( null !== $r[ $from ] && ( 0 === $pass || 'card' !== $from ) ) {
						$data[ $to ] = $r[ $from ];
					}
				}
				try {
					if ( 1 === $pass ) {
						$host = ! empty( $row['host_id'] ) ? (int) $row['host_id'] : self::find_host( (string) $row['host_text'] );
						if ( ! $host ) {
							throw new \InvalidArgumentException( 'socio ospitante "' . $row['host_text'] . '" non trovato' );
						}
						$data['host_person_id'] = $host;
					}
					if ( 'create' === $row['action'] ) {
						$data['type'] = $row['type'];
						$id           = $people->create( $data );
						$created++;
					} else {
						$id = (int) $row['matched_id'];
						if ( ! empty( $row['type_given'] ) && 0 === $pass ) {
							$data['type'] = $row['type'];
						}
						$people->update( $id, $data );
						$updated++;
					}
					$person = $people->get( $id );
					if ( $mark && $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
						$people->set_membership( $id, $year, true, 'import' );
					}
				} catch ( \InvalidArgumentException $e ) {
					$failed[] = ( $r['sheet'] ? $r['sheet'] . ' ' : '' ) . 'riga ' . $r['line'] . ': ' . $e->getMessage();
				}
			}
		}
		Audit::log( 'import.applied', 'people', null, array( 'created' => $created, 'updated' => $updated, 'failed' => count( $failed ) ) );
		return array( 'created' => $created, 'updated' => $updated, 'failed' => $failed );
	}

	/** L'ospitante di un ospite: tessera, email oppure nome e cognome (unico). */
	private static function find_host( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		$n = Text::normalize( $text );
		$c = Text::lower( PeopleCsv::clean_card( $text ) );
		$m = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::GUEST === $p['type'] ) {
				continue;
			}
			if ( ( ! empty( $p['card_number'] ) && Text::lower( (string) $p['card_number'] ) === $c )
				|| ( ! empty( $p['email'] ) && Text::lower( (string) $p['email'] ) === Text::lower( $text ) )
				|| Text::normalize( $p['first_name'] . $p['last_name'] ) === $n || Text::normalize( $p['last_name'] . $p['first_name'] ) === $n ) {
				$m[ (int) $p['id'] ] = true;
			}
		}
		return 1 === count( $m ) ? (int) array_keys( $m )[0] : 0;
	}

	private static function apply_ledger( array $plan, bool $keep_balances ): array {
		$ledger = Plugin::ledger();
		$people = Plugin::people();
		$res    = array( 'created' => 0, 'transfers' => 0, 'duplicates' => 0, 'skipped' => 0, 'failed' => array(), 'memberships' => 0, 'shifted' => 0 );
		$from   = null;
		$to     = null;
		$delta  = array(); // variazione per conto già esistente
		$new    = array(); // riferimento "new:…" => id del conto creato
		$member_rows = array();

		$ledger->in_batch(
			function () use ( $plan, $ledger, &$res, &$from, &$to, &$delta, &$new, &$member_rows ) {
				$account = function ( array $acc ) use ( $ledger, &$new ) {
					if ( ! empty( $acc['id'] ) ) {
						return (int) $acc['id'];
					}
					if ( ! isset( $new[ $acc['ref'] ] ) ) {
						$new[ $acc['ref'] ] = $ledger->add_account( (string) $acc['new'], (string) ( $acc['type'] ?: 'bank' ), 0 );
					}
					return $new[ $acc['ref'] ];
				};
				foreach ( $plan as $p ) {
					$d = $p['data'];
					switch ( $p['action'] ) {
						case 'duplicate':
							$res['duplicates']++;
							break;
						case 'skip':
							$res['skipped']++;
							break;
						case 'create':
							$acc_id = $account( array( 'id' => $d['account_id'], 'new' => $d['new_account'], 'type' => $d['new_account_type'], 'ref' => $d['account_ref'] ) );
							$tx     = $ledger->import_row( array_merge( $d, array( 'account_id' => $acc_id ) ) );
							$res['created']++;
							if ( $d['account_id'] ) {
								$delta[ $acc_id ] = ( $delta[ $acc_id ] ?? 0 ) + ( 'income' === $d['type'] ? $d['cents'] : -$d['cents'] );
							}
							if ( 'membership' === ( $d['category_kind'] ?? '' ) && $d['person_id'] ) {
								$member_rows[] = array( (int) $d['person_id'], Settings::social_year( $d['date'] )->label(), $tx );
							}
							$from = null === $from || $d['date'] < $from ? $d['date'] : $from;
							$to   = null === $to || $d['date'] > $to ? $d['date'] : $to;
							break;
						case 'transfer':
							$a = $account( array( 'id' => $d['from']['id'], 'new' => $d['from']['new'], 'type' => $d['from']['type'], 'ref' => $d['from']['ref'] ) );
							$b = $account( array( 'id' => $d['to']['id'], 'new' => $d['to']['new'], 'type' => $d['to']['type'], 'ref' => $d['to']['ref'] ) );
							$ledger->record_transfer( $d['date'], $a, $b, $d['cents'], $d['method'], $d['description'] );
							$res['transfers']++;
							if ( $d['from']['id'] ) {
								$delta[ $a ] = ( $delta[ $a ] ?? 0 ) - $d['cents'];
							}
							if ( $d['to']['id'] ) {
								$delta[ $b ] = ( $delta[ $b ] ?? 0 ) + $d['cents'];
							}
							$from = null === $from || $d['date'] < $from ? $d['date'] : $from;
							$to   = null === $to || $d['date'] > $to ? $d['date'] : $to;
							break;
					}
				}
			}
		);
		// Iscrizioni dall'anno sociale delle quote importate (anche passate): la tessera "ricorda" gli anni pagati.
		foreach ( $member_rows as $m ) {
			try {
				$person = $people->get( $m[0] );
				if ( $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
					$people->set_membership( $m[0], $m[1], true, 'import', $m[2] );
					$res['memberships']++;
				}
			} catch ( \InvalidArgumentException $e ) {
				$res['failed'][] = 'iscrizione ' . $m[1] . ': ' . $e->getMessage();
			}
		}
		// Movimenti storici: il saldo attuale dei conti già esistenti non deve cambiare.
		if ( $keep_balances ) {
			foreach ( $delta as $acc_id => $d ) {
				$ledger->shift_opening( (int) $acc_id, -$d );
				$res['shifted']++;
			}
		}
		Audit::log( 'import.ledger', 'ledger', null, array( 'created' => $res['created'], 'transfers' => $res['transfers'], 'duplicates' => $res['duplicates'], 'from' => $from, 'to' => $to, 'keep_balances' => $keep_balances ) );
		return $res;
	}
}
