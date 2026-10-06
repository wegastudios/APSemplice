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
		$out         = self::preview_sheets( SheetReader::read( $path, $name ), $opts );
		$out['file'] = $name;
		return $out;
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
				'Non sono stati trovati né soci né movimenti. Per i soci servono le colonne "Nome" e "Cognome"; per la prima nota "Data" e "Importo" (oppure "Entrata" e "Uscita"). Fogli letti: ' . ( $ignored ? implode( ', ', $ignored ) : 'nessuno' ) . '.'
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
			$plan             = LedgerImport::plan( $ledger_rows, self::ledger_context( $ledger_rows, (int) ( $opts['default_account_id'] ?? 0 ), $people_rows ) );
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
				'tax' => $e['tax_code'], 'phone' => $e['phone'], 'type' => $e['type'], 'host_id' => (int) $e['host_person_id'],
			);
		}
		return $out;
	}

	private static function ledger_context( array $rows, int $default_account_id, array $people_rows = array() ): array {
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
			'pending_people'     => array_map( function ( $r ) {
				return array( 'card' => $r['card'], 'first' => $r['first'], 'last' => $r['last'] );
			}, $people_rows ),
		);
	}

	// ---------- Applicazione ----------

	/**
	 * Applica un'anteprima. Tutto quello che si scrive è legato a un "import" (numero), così si può annullare in blocco con {@see undo()}.
	 *
	 * @param array $preview anteprima di preview_sheets()
	 * @param array $opts    mark_members (bool), keep_balances (bool), source (testo: nome del file, "WP All Import"...)
	 * @return array people {created, updated, failed[]}, ledger {created, transfers, duplicates, skipped, failed[], memberships, shifted}, batch_id
	 */
	public static function apply( array $preview, array $opts = array() ): array {
		$res   = array( 'people' => null, 'ledger' => null, 'batch_id' => null );
		$track = array( 'people_created' => array(), 'people_updated' => array(), 'wp_users_created' => array(), 'memberships' => array(), 'shifts' => array(), 'accounts_created' => array() );
		$batch = self::open_batch( (string) ( $opts['source'] ?? $preview['file'] ?? 'Import' ) );
		try {
			if ( ! empty( $preview['people'] ) ) {
				$res['people'] = self::apply_people( $preview['people']['plan'], ! empty( $opts['mark_members'] ), $track );
			}
			if ( ! empty( $preview['ledger'] ) ) {
				$res['ledger'] = self::apply_ledger( $preview['ledger']['plan'], ! empty( $opts['keep_balances'] ), $batch, $track );
			}
		} finally {
			self::close_batch( $batch, $res, $track ); // anche se qualcosa va storto a metà: quello che è stato scritto si può annullare
		}
		$changed         = ( $res['people']['created'] ?? 0 ) + ( $res['people']['updated'] ?? 0 ) + ( $res['ledger']['created'] ?? 0 ) + ( $res['ledger']['transfers'] ?? 0 );
		$res['batch_id'] = $changed > 0 ? $batch : null;
		if ( ! $changed ) {
			Db::db()->delete( Db::t( 'import_batches' ), array( 'id' => $batch ) );
		}
		return $res;
	}

	private static function open_batch( string $source ): int {
		Db::db()->insert( Db::t( 'import_batches' ), array( 'created_at' => Db::now(), 'user_id' => get_current_user_id() ?: null, 'source' => mb_substr( $source, 0, 190 ) ) );
		return (int) Db::db()->insert_id;
	}

	private static function close_batch( int $batch, array $res, array $track ): void {
		$summary = array(
			'people_created' => count( $track['people_created'] ), 'people_updated' => count( $track['people_updated'] ),
			'transactions'   => (int) ( $res['ledger']['created'] ?? 0 ), 'transfers' => (int) ( $res['ledger']['transfers'] ?? 0 ),
		);
		Db::db()->update( Db::t( 'import_batches' ), array( 'summary' => wp_json_encode( $summary ), 'data' => wp_json_encode( $track ) ), array( 'id' => $batch ) );
	}

	private static function apply_people( array $plan, bool $mark, array &$track ): array {
		$db      = Db::db();
		$year    = Settings::membership_year()->label();
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
						$user_existed = ! empty( $data['email'] ) && (bool) get_user_by( 'email', $data['email'] );
						$id           = $people->create( $data );
						$created++;
						$track['people_created'][] = $id;
						$person                    = $people->get( $id );
						if ( ! $user_existed && $person && ! empty( $person['wp_user_id'] ) ) {
							$track['wp_users_created'][ $id ] = (int) $person['wp_user_id']; // utente nato con questo import: si potrà togliere
						}
					} else {
						$id     = (int) $row['matched_id'];
						$before = $people->get( $id );
						if ( ! empty( $row['type_given'] ) && 0 === $pass ) {
							$data['type'] = $row['type'];
						}
						$people->update( $id, $data );
						$updated++;
						if ( $before ) {
							$track['people_updated'][ $id ] = array_intersect_key( $before, array_flip( array( 'first_name', 'last_name', 'email', 'phone', 'tax_code', 'card_number', 'type', 'host_person_id' ) ) );
						}
					}
					$person = $people->get( $id );
					if ( $mark && $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
						$had = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND social_year = %s AND deleted_at IS NULL', $id, $year ) );
						$people->set_membership( $id, $year, true, 'import' );
						if ( ! $had ) {
							$track['memberships'][] = array( $id, $year );
						}
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

	private static function apply_ledger( array $plan, bool $keep_balances, int $batch, array &$track ): array {
		$ledger = Plugin::ledger();
		$people = Plugin::people();
		$res    = array( 'created' => 0, 'transfers' => 0, 'duplicates' => 0, 'skipped' => 0, 'failed' => array(), 'memberships' => 0, 'shifted' => 0 );
		$from   = null;
		$to     = null;
		$delta  = array(); // variazione per conto già esistente
		$new    = array(); // riferimento "new:…" => id del conto creato
		$member_rows = array();

		// Soci che stavano nello stesso file: ora esistono, si collegano ai loro movimenti.
		$late = array();
		foreach ( $plan as $i => $p ) {
			if ( 'create' === $p['action'] && ! empty( $p['data']['person_late'] ) ) {
				$late[ $i ] = $p['data'];
			}
		}
		if ( $late ) {
			$by_card = array();
			$by_name = array();
			foreach ( $people->search() as $pe ) {
				if ( ! empty( $pe['card_number'] ) ) {
					$by_card[ Text::lower( (string) $pe['card_number'] ) ] = (int) $pe['id'];
				}
				$by_name[ Text::normalize( $pe['first_name'] . $pe['last_name'] ) ][ (int) $pe['id'] ]  = true;
				$by_name[ Text::normalize( $pe['last_name'] . $pe['first_name'] ) ][ (int) $pe['id'] ] = true;
			}
			foreach ( $late as $i => $d ) {
				$id = 0;
				if ( '' !== (string) $d['card'] ) {
					$id = $by_card[ Text::lower( PeopleCsv::clean_card( (string) $d['card'] ) ) ] ?? 0;
				}
				if ( ! $id && '' !== (string) $d['person_text'] ) {
					$m  = array_keys( $by_name[ Text::normalize( (string) $d['person_text'] ) ] ?? array() );
					$id = 1 === count( $m ) ? (int) $m[0] : 0;
				}
				$plan[ $i ]['data']['person_id'] = $id;
			}
		}

		$ledger->in_batch(
			function () use ( $plan, $ledger, $batch, &$res, &$from, &$to, &$delta, &$new, &$member_rows ) {
				$account = function ( array $acc ) use ( $ledger, &$new ) {
					if ( ! empty( $acc['id'] ) ) {
						return (int) $acc['id'];
					}
					if ( ! isset( $new[ $acc['ref'] ] ) ) {
						$new[ $acc['ref'] ] = $ledger->add_account( (string) $acc['new'], (string) ( $acc['type'] ?: 'bank' ), 0 );
					}
					return $new[ $acc['ref'] ];
				};
				foreach ( $plan as $p ) { // importando gli anni precedenti, gli anni solari che mancano si creano (aperti); uno chiuso resta chiuso e blocca l'import
					$when = (string) ( $p['data']['date'] ?? '' );
					if ( preg_match( '/^(\d{4})-\d{2}-\d{2}$/', $when, $ym ) && (int) $ym[1] >= 2000 && (int) $ym[1] <= 2100 ) {
						FiscalYears::ensure( (int) $ym[1] );
					}
				}
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
							$tx     = $ledger->import_row( array_merge( $d, array( 'account_id' => $acc_id, 'batch' => $batch ) ) );
							$res['created']++;
							if ( $d['account_id'] ) {
								$delta[ $acc_id ] = ( $delta[ $acc_id ] ?? 0 ) + ( 'income' === $d['type'] ? $d['cents'] : -$d['cents'] );
							}
							if ( 'membership' === ( $d['category_kind'] ?? '' ) && $d['person_id'] ) {
								$member_rows[] = array( (int) $d['person_id'], Settings::membership_year( $d['date'] )->label(), $tx );
							}
							$from = null === $from || $d['date'] < $from ? $d['date'] : $from;
							$to   = null === $to || $d['date'] > $to ? $d['date'] : $to;
							break;
						case 'transfer':
							$a = $account( array( 'id' => $d['from']['id'], 'new' => $d['from']['new'], 'type' => $d['from']['type'], 'ref' => $d['from']['ref'] ) );
							$b = $account( array( 'id' => $d['to']['id'], 'new' => $d['to']['new'], 'type' => $d['to']['type'], 'ref' => $d['to']['ref'] ) );
							$ledger->tag_transfer( $ledger->record_transfer( $d['date'], $a, $b, $d['cents'], $d['method'], $d['description'] ), $batch );
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
		$track['accounts_created'] = array_values( $new );
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
				$track['shifts'][ (int) $acc_id ] = -$d;
				$res['shifted']++;
			}
		}
		Audit::log( 'import.ledger', 'ledger', null, array( 'created' => $res['created'], 'transfers' => $res['transfers'], 'duplicates' => $res['duplicates'], 'from' => $from, 'to' => $to, 'keep_balances' => $keep_balances ) );
		return $res;
	}

	// ---------- Cronologia e annullamento ----------

	/** @return array[] gli ultimi import, dal più recente (summary e data già decodificati) */
	public static function batches( int $limit = 20 ): array {
		$db   = Db::db();
		$rows = $db->get_results( $db->prepare( 'SELECT b.*, u.display_name FROM ' . Db::t( 'import_batches' ) . ' b LEFT JOIN ' . $db->users . ' u ON u.ID = b.user_id ORDER BY b.id DESC LIMIT %d', max( 1, $limit ) ), ARRAY_A ) ?: array();
		foreach ( $rows as &$r ) {
			$r['summary'] = (array) json_decode( (string) $r['summary'], true );
			$r['data']    = (array) json_decode( (string) $r['data'], true );
		}
		return $rows;
	}

	public static function batch( int $id ): ?array {
		foreach ( self::batches( 500 ) as $b ) {
			if ( (int) $b['id'] === $id ) {
				return $b;
			}
		}
		return null;
	}

	/** True se la persona non è usata altrove (nessuna iscrizione a un'attività, prenotazione, movimento, pagamento o iscrizione residua). */
	private static function person_unused( int $id ): bool {
		$db = Db::db();
		foreach ( array(
			'enrollments' => 'person_id', 'bookings' => 'person_id', 'memberships' => 'person_id', 'payments' => 'payer_person_id',
		) as $table => $col ) {
			$extra = 'memberships' === $table ? ' AND deleted_at IS NULL' : '';
			if ( (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . " WHERE $col = %d$extra", $id ) ) > 0 ) {
				return false;
			}
		}
		return 0 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE person_id = %d AND voided_at IS NULL', $id ) );
	}

	/**
	 * Annulla un intero import: movimenti (e le iscrizioni nate da loro), saldi iniziali aggiustati, conti creati e vuoti,
	 * iscrizioni segnate, dati dei soci aggiornati (ripristinati), soci e ospiti creati (solo se non ancora usati; l'utente WordPress
	 * nato con l'import viene tolto se non ha altri ruoli). Quello che è già stato usato resta e viene segnalato.
	 *
	 * @return array voided, shifts_undone, accounts_removed, memberships_removed, people_restored, people_removed, people_kept[], users_removed
	 * @throws \InvalidArgumentException
	 */
	public static function undo( int $batch_id ): array {
		$b = self::batch( $batch_id );
		if ( ! $b ) {
			throw new \InvalidArgumentException( 'Import non trovato.' );
		}
		if ( ! empty( $b['undone_at'] ) ) {
			throw new \InvalidArgumentException( 'Questo import è già stato annullato.' );
		}
		$db     = Db::db();
		$ledger = Plugin::ledger();
		$people = Plugin::people();
		$data   = $b['data'];
		$res    = array( 'voided' => 0, 'shifts_undone' => 0, 'accounts_removed' => 0, 'memberships_removed' => 0, 'people_restored' => 0, 'people_removed' => 0, 'people_kept' => array(), 'users_removed' => 0 );
		$reason = 'Annullamento import n. ' . $batch_id;

		// 1. Prima nota: tutto o niente
		$ledger->in_batch(
			function () use ( $db, $ledger, $batch_id, $data, $reason, &$res ) {
				$ids = array_map( 'intval', $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE import_batch = %d AND voided_at IS NULL ORDER BY id', $batch_id ) ) );
				foreach ( $ids as $id ) {
					$still = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d AND voided_at IS NULL', $id ) );
					if ( $still ) { // un giroconto si annulla con entrambe le righe: la seconda può essere già andata
						$ledger->void( $id, $reason );
						$res['voided']++;
					}
				}
				foreach ( (array) ( $data['shifts'] ?? array() ) as $acc => $shift ) {
					$ledger->shift_opening( (int) $acc, -(int) $shift );
					$res['shifts_undone']++;
				}
				foreach ( (array) ( $data['accounts_created'] ?? array() ) as $acc ) {
					$used = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE account_id = %d AND voided_at IS NULL', (int) $acc ) );
					if ( ! $used ) {
						$db->update( Db::t( 'accounts' ), array( 'deleted_at' => Db::now() ), array( 'id' => (int) $acc ) );
						$res['accounts_removed']++;
					}
				}
			}
		);

		// 2. Iscrizioni segnate dall'import
		foreach ( (array) ( $data['memberships'] ?? array() ) as $m ) {
			try {
				$people->set_membership( (int) $m[0], (string) $m[1], false, 'import-undo' );
				$res['memberships_removed']++;
			} catch ( \InvalidArgumentException $e ) { // persona eliminata nel frattempo
				continue;
			}
		}

		// 3. Soci aggiornati: si rimettono i dati di prima
		foreach ( (array) ( $data['people_updated'] ?? array() ) as $id => $before ) {
			if ( in_array( (int) $id, array_map( 'intval', (array) ( $data['people_created'] ?? array() ) ), true ) ) {
				continue;
			}
			try {
				$people->update( (int) $id, (array) $before );
				$res['people_restored']++;
			} catch ( \InvalidArgumentException $e ) {
				$res['people_kept'][] = 'scheda ' . (int) $id . ' non ripristinata: ' . $e->getMessage();
			}
		}

		// 4. Soci e ospiti creati: prima gli ospiti (altrimenti l'ospitante non si può togliere), solo se non usati
		$created = array_map( 'intval', (array) ( $data['people_created'] ?? array() ) );
		usort(
			$created,
			function ( $a, $c ) use ( $people ) {
				$pa = $people->get( $a );
				$pc = $people->get( $c );
				return ( $pa && MemberType::GUEST === $pa['type'] ? 0 : 1 ) <=> ( $pc && MemberType::GUEST === $pc['type'] ? 0 : 1 );
			}
		);
		foreach ( $created as $id ) {
			$p = $people->get( $id );
			if ( ! $p ) {
				continue;
			}
			$name = trim( $p['first_name'] . ' ' . $p['last_name'] );
			if ( ! self::person_unused( $id ) ) {
				$res['people_kept'][] = $name . ' (già usato)';
				continue;
			}
			try {
				$people->delete( $id );
				$res['people_removed']++;
				$uid = (int) ( $data['wp_users_created'][ $id ] ?? 0 );
				if ( $uid && self::remove_import_user( $uid ) ) {
					$res['users_removed']++;
				}
			} catch ( \InvalidArgumentException $e ) {
				$res['people_kept'][] = $name . ' (' . $e->getMessage() . ')';
			}
		}

		$db->update(
			Db::t( 'import_batches' ),
			array( 'undone_at' => Db::now(), 'undone_by' => get_current_user_id() ?: null, 'undo_result' => wp_json_encode( $res ) ),
			array( 'id' => $batch_id )
		);
		Audit::log( 'import.undone', 'import', $batch_id, array( 'voided' => $res['voided'], 'people_removed' => $res['people_removed'], 'kept' => count( $res['people_kept'] ) ) );
		return $res;
	}

	/** Toglie l'utente WordPress creato da un import, ma solo se è un semplice socio (mai amministratori né utenti con altri ruoli o contenuti). */
	private static function remove_import_user( int $user_id ): bool {
		$u = get_userdata( $user_id );
		if ( ! $u || user_can( $u, 'manage_options' ) || user_can( $u, Plugin::CAP_OPS ) || user_can( $u, 'edit_posts' ) ) {
			return false;
		}
		$extra = array_diff( (array) $u->roles, array( Plugin::ROLE_MEMBER, 'subscriber' ) );
		if ( $extra || (int) count_user_posts( $user_id, 'any' ) > 0 ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return (bool) wp_delete_user( $user_id );
	}
}
