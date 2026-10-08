<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Import della prima nota da Excel/CSV: lettura delle righe e piano di importazione (crea / doppione / salta / errore).
 * Codice puro: non scrive nel database. Riconosce anche il file che esporta il plugin stesso (Data, Tipo, Conto, … Entrata, Uscita).
 */
final class LedgerImport {

	const COLS = array(
		'date'     => array( 'data', 'datamovimento', 'datadioperazione', 'dataoperazione', 'datapagamento', 'giorno', 'date' ),
		'type'     => array( 'tipo', 'tipomovimento', 'movimento', 'tipologia', 'eu', 'entrataouscita', 'segno' ),
		'account'  => array( 'conto', 'cassa', 'banca', 'contocassa', 'contobanca' ),
		'method'   => array( 'modalita', 'modalitapagamento', 'modalitadipagamento', 'metodo', 'metodopagamento', 'pagamento', 'mezzo' ),
		'category' => array( 'voce', 'categoria', 'voceprimanota', 'vocedibilancio', 'causale' ),
		'amount'   => array( 'importo', 'ammontare', 'totale', 'valore', 'euro', 'cifra', 'amount' ),
		'income'   => array( 'entrata', 'entrate', 'incasso', 'incassi', 'income' ),
		'expense'  => array( 'uscita', 'uscite', 'spesa', 'spese', 'esborso', 'costo', 'costi', 'expense' ),
		'desc'     => array( 'descrizione', 'descr', 'dettaglio', 'note', 'annotazioni', 'oggetto' ),
		'ref'      => array( 'riferimento', 'rif', 'ndocumento', 'numerodocumento', 'ndoc', 'nfattura', 'numfattura', 'fattura', 'documento', 'nfatturascontrino' ),
		'card'     => array( 'ntessera', 'tessera', 'numerotessera', 'numtessera', 'nrtessera' ),
		'person'   => array( 'persona', 'socio', 'nominativo', 'beneficiario', 'intestatario' ),
		'activity' => array( 'attivita', 'corso', 'evento' ),
		'month'    => array( 'competenza', 'mesecompetenza', 'mese' ),
	);

	const MONTH_NAMES = array(
		'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
		'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
	);

	/** Parole chiave (nel nome della voce) => tipo di voce del plugin. */
	const KEYWORDS = array(
		'membership'               => array( 'quotaassoc', 'quotasoc', 'tessera', 'iscrizione' ),
		'activity_fee'             => array( 'corso', 'attivita', 'lezione', 'quotacorso', 'contributo' ),
		'donation'                 => array( 'donazion', 'erogazion', 'liberal', 'offert' ),
		'member_reimbursement'     => array( 'rimborso', 'compens', 'istrutt', 'docent', 'insegnant' ),
	);

	public static function accepts_header( array $h ): bool {
		$has = function ( string $k ) use ( $h ) {
			return SheetReader::col( $h, self::COLS[ $k ] ) >= 0;
		};
		return $has( 'date' ) && ( $has( 'amount' ) || $has( 'income' ) || $has( 'expense' ) );
	}

	// ---------- Lettura ----------

	/**
	 * @param array      $table righe di celle (la riga di intestazione può non essere la prima)
	 * @param int[]|null $lines numero di riga originale di ogni riga della tabella
	 * @return array ['error'=>string] oppure ['rows'=>[...]]
	 */
	public static function parse_table( array $table, ?array $lines = null, string $sheet = '' ): array {
		$hi = SheetReader::find_header( $table, array( __CLASS__, 'accepts_header' ) );
		if ( null === $hi ) {
			return array( 'error' => 'Nella prima riga servono le colonne "Data" e "Importo" (oppure "Entrata" e "Uscita").' );
		}
		$h   = SheetReader::normalize_header( $table[ $hi ] );
		$col = array();
		foreach ( array_keys( self::COLS ) as $k ) {
			$col[ $k ] = SheetReader::col( $h, self::COLS[ $k ] );
		}
		$cell = function ( array $cells, string $k ) use ( $col ) {
			$i = $col[ $k ];
			if ( $i < 0 || ! isset( $cells[ $i ] ) ) {
				return '';
			}
			return trim( (string) $cells[ $i ] );
		};
		$rows = array();
		foreach ( $table as $idx => $cells ) {
			if ( $idx <= $hi ) {
				continue;
			}
			$line = $lines ? (int) ( $lines[ $idx ] ?? $idx + 1 ) : $idx + 1;
			$r    = array(
				'line' => $line, 'sheet' => $sheet, 'errors' => array(),
				'date' => null, 'type' => null, 'cents' => 0,
				'account' => $cell( $cells, 'account' ), 'method' => $cell( $cells, 'method' ), 'category' => $cell( $cells, 'category' ),
				'desc' => $cell( $cells, 'desc' ), 'ref' => $cell( $cells, 'ref' ), 'card' => $cell( $cells, 'card' ),
				'person' => $cell( $cells, 'person' ), 'activity' => $cell( $cells, 'activity' ), 'month' => $cell( $cells, 'month' ),
			);
			$r['date'] = self::parse_date( $cell( $cells, 'date' ) );
			if ( null === $r['date'] ) {
				$r['errors'][] = '' === $cell( $cells, 'date' ) ? 'Data mancante' : 'Data non valida: ' . $cell( $cells, 'date' );
			}
			// Importo e tipo
			$type_text = Text::normalize( $cell( $cells, 'type' ) );
			$type      = '' === $type_text ? null : self::type_from_text( $type_text );
			$amount_in = '' !== $cell( $cells, 'income' ) ? self::parse_amount( $cell( $cells, 'income' ) ) : null;
			$amount_out = '' !== $cell( $cells, 'expense' ) ? self::parse_amount( $cell( $cells, 'expense' ) ) : null;
			$amount    = '' !== $cell( $cells, 'amount' ) ? self::parse_amount( $cell( $cells, 'amount' ) ) : null;
			foreach ( array( 'income' => $amount_in, 'expense' => $amount_out, 'amount' => $amount ) as $k => $v ) {
				if ( '' !== $cell( $cells, $k ) && null === $v ) {
					$r['errors'][] = 'Importo non valido: ' . $cell( $cells, $k );
				}
			}
			if ( $amount_in && $amount_out ) {
				$r['errors'][] = 'Ci sono sia un\'entrata sia un\'uscita nella stessa riga';
			} elseif ( $amount_in || $amount_out ) {
				$signed = $amount_in ? abs( (int) $amount_in ) : -abs( (int) $amount_out );
				$r['cents'] = abs( $signed );
				$r['type']  = $type && in_array( $type, array( 'transfer_in', 'transfer_out' ), true ) ? $type : ( $signed > 0 ? 'income' : 'expense' );
			} elseif ( null !== $amount ) {
				$r['cents'] = abs( $amount );
				if ( $type ) {
					$r['type'] = $type;
				} else {
					$r['type'] = $amount < 0 ? 'expense' : 'income';
				}
			} elseif ( ! $r['errors'] || null !== $r['date'] ) {
				$r['errors'][] = 'Importo mancante';
			}
			if ( 0 === $r['cents'] && ! array_filter( $r['errors'], function ( $e ) {
				return 0 === strpos( $e, 'Importo' );
			} ) ) {
				$r['errors'][] = 'Importo uguale a zero';
			}
			// Una riga che ha solo "Totale" o è priva di tutto il resto non è un movimento: la si ignora in silenzio.
			if ( null === $r['date'] && '' === $cell( $cells, 'date' ) && '' === $cell( $cells, 'amount' ) && '' === $cell( $cells, 'income' ) && '' === $cell( $cells, 'expense' ) ) {
				continue;
			}
			$rows[] = $r;
		}
		return array( 'rows' => $rows );
	}

	/** Testo (già normalizzato) di una colonna "Tipo" => income | expense | transfer_in | transfer_out | null. */
	public static function type_from_text( string $n ): ?string {
		if ( false !== strpos( $n, 'giro' ) || false !== strpos( $n, 'trasfer' ) ) {
			if ( false !== strpos( $n, 'usc' ) ) {
				return 'transfer_out';
			}
			return false !== strpos( $n, 'entr' ) ? 'transfer_in' : 'transfer_out';
		}
		foreach ( array( 'entrat', 'incass', 'ricav', 'income', 'avere' ) as $k ) {
			if ( 0 === strpos( $n, $k ) ) {
				return 'income';
			}
		}
		foreach ( array( 'uscit', 'spes', 'pagament', 'cost', 'esbors', 'expense', 'dare' ) as $k ) {
			if ( 0 === strpos( $n, $k ) ) {
				return 'expense';
			}
		}
		if ( in_array( $n, array( 'e', 'in', 'i' ), true ) ) {
			return 'income';
		}
		return in_array( $n, array( 'u', 'out', 's' ), true ) ? 'expense' : null;
	}

	/** "2025-03-07", "7/3/2025", "07-03-25", "07.03.2025" => "2025-03-07"; null se non valida. */
	public static function parse_date( string $s ): ?string {
		$s = trim( $s );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $s, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
		} elseif ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})(?:[ T].*)?$#', $s, $m ) ) {
			$d  = (int) $m[1];
			$mo = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += $y < 70 ? 2000 : 1900;
			}
		} else {
			return null;
		}
		return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/** Importo in centesimi, con segno. Accetta 1.234,56 · 1,234.56 · 12,5 · (12,50) · 12,50- · "12,50 €". Null se non è un numero. */
	public static function parse_amount( string $s ): ?int {
		$s = trim( str_replace( array( '€', 'EUR', 'eur', "\xC2\xA0", ' ' ), '', $s ) );
		if ( '' === $s ) {
			return null;
		}
		$neg = false;
		if ( preg_match( '/^\((.*)\)$/', $s, $m ) ) {
			$neg = true;
			$s   = $m[1];
		}
		if ( '-' === substr( $s, -1 ) ) {
			$neg = true;
			$s   = substr( $s, 0, -1 );
		}
		$s = ltrim( $s, '+' );
		if ( false !== strpos( $s, '.' ) && false !== strpos( $s, ',' ) && strrpos( $s, '.' ) > strrpos( $s, ',' ) ) {
			$s = str_replace( ',', '', $s ); // stile inglese: 1,234.56
		}
		$cents = Money::parse( $s );
		if ( null === $cents ) {
			return null;
		}
		return $neg ? -abs( $cents ) : $cents;
	}

	/** "2025-03", "03/2025", "marzo 2025", "2025-03-15" => "2025-03"; null se non riconosciuto. */
	public static function parse_month( string $s ): ?string {
		$s = trim( $s );
		if ( '' === $s ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})(-\d{1,2})?$/', $s, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ) {
			return sprintf( '%04d-%02d', $m[1], $m[2] );
		}
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{4})$#', $s, $m ) && (int) $m[1] >= 1 && (int) $m[1] <= 12 ) {
			return sprintf( '%04d-%02d', $m[2], $m[1] );
		}
		$d = self::parse_date( $s );
		if ( $d ) {
			return substr( $d, 0, 7 );
		}
		if ( preg_match( '/^([a-zà-ù]+)\s+(\d{4})$/iu', $s, $m ) ) {
			$mo = self::MONTH_NAMES[ Text::normalize( $m[1] ) ] ?? 0;
			return $mo ? sprintf( '%04d-%02d', $m[2], $mo ) : null;
		}
		return null;
	}

	// ---------- Piano ----------

	/** Chiave per riconoscere i doppioni (stessa data, conto, tipo, importo, descrizione e riferimento). */
	public static function key( string $date, string $account, string $type, int $cents, string $desc, string $ref ): string {
		return $date . '|' . $account . '|' . $type . '|' . $cents . '|' . Text::normalize( $desc ) . '|' . Text::normalize( $ref );
	}

	/**
	 * @param array $rows righe di {@see parse_table()}
	 * @param array $ctx  accounts [id,name,type], categories [id,name,kind], people [id,card,first,last], activities [id,name],
	 *                    existing [chiave => quante già presenti], default_account_id
	 * @return array[] per riga: row, action (create|transfer|paired|duplicate|skip|error), message, warnings, data
	 */
	public static function plan( array $rows, array $ctx ): array {
		$accounts = array();
		foreach ( (array) ( $ctx['accounts'] ?? array() ) as $a ) {
			$accounts[ Text::normalize( $a['name'] ) ] = $a;
		}
		$by_id = array();
		foreach ( $accounts as $a ) {
			$by_id[ (int) $a['id'] ] = $a;
		}
		$new_accounts = array();
		$cats_by_name = array();
		$cats_by_kind = array();
		foreach ( (array) ( $ctx['categories'] ?? array() ) as $c ) {
			if ( 'adjustment' === $c['kind'] ) {
				continue;
			}
			$cats_by_name[ Text::normalize( $c['name'] ) ][] = $c;
			$cats_by_kind[ $c['kind'] ][]                    = $c;
		}
		$people_card = array();
		$people_name = array();
		foreach ( (array) ( $ctx['people'] ?? array() ) as $p ) {
			if ( ! empty( $p['card'] ) ) {
				$people_card[ Text::lower( (string) $p['card'] ) ] = $p;
			}
			$people_name[ Text::normalize( $p['first'] . $p['last'] ) ][] = $p;
			$people_name[ Text::normalize( $p['last'] . $p['first'] ) ][] = $p;
		}
		$pending_card = array();
		$pending_name = array();
		foreach ( (array) ( $ctx['pending_people'] ?? array() ) as $p ) {
			if ( ! empty( $p['card'] ) ) {
				$pending_card[ Text::lower( (string) $p['card'] ) ] = true;
			}
			$pending_name[ Text::normalize( $p['first'] . $p['last'] ) ][] = $p;
			$pending_name[ Text::normalize( $p['last'] . $p['first'] ) ][] = $p;
		}
		$acts = array();
		foreach ( (array) ( $ctx['activities'] ?? array() ) as $a ) {
			$acts[ Text::normalize( $a['name'] ) ][] = $a;
		}
		$existing = (array) ( $ctx['existing'] ?? array() );
		$seen     = array();
		$default  = (int) ( $ctx['default_account_id'] ?? 0 );
		$plans    = array();
		$kinds    = Labels::category_kinds();

		foreach ( $rows as $r ) {
			$warn = array();
			$fail = function ( string $m ) use ( $r ) {
				return array( 'row' => $r, 'action' => 'error', 'message' => $m, 'warnings' => array(), 'data' => null );
			};
			if ( $r['errors'] ) {
				$plans[] = $fail( implode( '; ', $r['errors'] ) );
				continue;
			}
			// Conto
			$acc_id   = 0;
			$acc_type = 'cash';
			$new_name = null;
			$acc_key  = Text::normalize( $r['account'] );
			if ( '' === $acc_key ) {
				if ( ! $default || ! isset( $by_id[ $default ] ) ) {
					$plans[] = $fail( 'Conto mancante: indica un conto nella riga o scegli un conto predefinito' );
					continue;
				}
				$acc_id   = $default;
				$acc_type = $by_id[ $default ]['type'];
			} elseif ( isset( $accounts[ $acc_key ] ) ) {
				$acc_id   = (int) $accounts[ $acc_key ]['id'];
				$acc_type = $accounts[ $acc_key ]['type'];
			} else {
				if ( ! isset( $new_accounts[ $acc_key ] ) ) {
					$new_accounts[ $acc_key ] = array( 'name' => $r['account'], 'type' => self::guess_account_type( $acc_key ) );
				}
				$new_name = $new_accounts[ $acc_key ]['name'];
				$acc_type = $new_accounts[ $acc_key ]['type'];
			}
			$acc_ref = $acc_id ? (string) $acc_id : 'new:' . $acc_key;

			// Modalità
			$method = self::method_from_text( Text::normalize( $r['method'] ) );
			if ( null === $method ) {
				if ( '' !== $r['method'] ) {
					$warn[] = 'Modalità "' . $r['method'] . '" non riconosciuta: usata "Altro"';
					$method = 'other';
				} else {
					$method = array( 'cash' => 'cash', 'bank' => 'bank_transfer', 'pos' => 'pos' )[ $acc_type ] ?? 'other';
				}
			}

			// Giroconti: si accoppiano dopo
			if ( in_array( $r['type'], array( 'transfer_in', 'transfer_out' ), true ) ) {
				$plans[] = array(
					'row' => $r, 'action' => 'transfer_leg', 'message' => '', 'warnings' => $warn,
					'data' => array( 'date' => $r['date'], 'type' => $r['type'], 'cents' => $r['cents'], 'account_id' => $acc_id, 'new_account' => $new_name, 'new_account_type' => $new_name ? $acc_type : null, 'account_ref' => $acc_ref, 'method' => $method, 'description' => $r['desc'] ),
				);
				continue;
			}

			// Voce
			$income = 'income' === $r['type'];
			$cat    = self::resolve_category( $r['category'], $income, $cats_by_name, $cats_by_kind, $kinds );
			$desc   = $r['desc'];
			if ( ! $cat['id'] ) {
				$fallback = $income ? 'other_income' : 'general_cost';
				$cat_row  = $cats_by_kind[ $fallback ][0] ?? null;
				if ( ! $cat_row ) {
					$plans[] = $fail( 'Voce "' . $r['category'] . '" non trovata e manca una voce generica' );
					continue;
				}
				$cat = array( 'id' => (int) $cat_row['id'], 'kind' => $fallback, 'name' => $cat_row['name'] );
				if ( '' !== $r['category'] ) {
					$warn[] = 'Voce "' . $r['category'] . '" non trovata: usata "' . $cat_row['name'] . '"';
					$desc   = '[' . $r['category'] . ']' . ( '' !== $desc ? ' ' . $desc : '' );
				} else {
					$warn[] = 'Senza voce: usata "' . $cat_row['name'] . '"';
				}
			}

			// Persona, attività, competenza
			$person_id = 0;
			$late      = false; // il socio è nello stesso file e ancora non esiste: si collega all'applicazione
			if ( '' !== $r['card'] ) {
				$p = $people_card[ Text::lower( PeopleCsv::clean_card( $r['card'] ) ) ] ?? null;
				if ( $p ) {
					$person_id = (int) $p['id'];
				} elseif ( isset( $pending_card[ Text::lower( PeopleCsv::clean_card( $r['card'] ) ) ] ) ) {
					$late = true;
				} else {
					$warn[] = 'Tessera ' . $r['card'] . ' non trovata: importato senza persona';
				}
			}
			if ( ! $person_id && ! $late && '' !== $r['person'] ) {
				$m = $people_name[ Text::normalize( $r['person'] ) ] ?? array();
				$m = array_values( array_unique( array_map( function ( $x ) {
					return (int) $x['id'];
				}, $m ) ) );
				if ( 1 === count( $m ) ) {
					$person_id = $m[0];
				} elseif ( ! $m && 1 === count( $pending_name[ Text::normalize( $r['person'] ) ] ?? array() ) ) {
					$late = true;
				} else {
					$warn[] = ( $m ? 'Più persone chiamate' : 'Persona non trovata:' ) . ' "' . $r['person'] . '": importato senza persona';
				}
			}
			$activity_id = 0;
			if ( '' !== $r['activity'] ) {
				$m = $acts[ Text::normalize( $r['activity'] ) ] ?? array();
				if ( 1 === count( $m ) ) {
					$activity_id = (int) $m[0]['id'];
				} else {
					$warn[] = ( $m ? 'Attività con lo stesso nome in più anni' : 'Attività non trovata' ) . ' "' . $r['activity'] . '": importato senza attività';
				}
			}
			$month = null;
			if ( '' !== $r['month'] ) {
				$month = self::parse_month( $r['month'] );
				if ( null === $month ) {
					$warn[] = 'Competenza "' . $r['month'] . '" non riconosciuta: ignorata';
				}
			}

			$data = array(
				'date' => $r['date'], 'type' => $r['type'], 'cents' => $r['cents'], 'account_id' => $acc_id, 'new_account' => $new_name,
				'new_account_type' => $new_name ? $acc_type : null, 'account_ref' => $acc_ref, 'method' => $method, 'category_id' => $cat['id'], 'category_kind' => $cat['kind'],
				'person_id' => $person_id, 'activity_id' => $activity_id, 'description' => $desc, 'ref' => $r['ref'], 'month' => $month,
				'person_late' => $late, 'card' => $r['card'], 'person_text' => $r['person'],
			);
			$key = self::key( $r['date'], $acc_ref, $r['type'], $r['cents'], $desc, $r['ref'] );
			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			if ( $seen[ $key ] <= (int) ( $existing[ $key ] ?? 0 ) ) {
				$plans[] = array( 'row' => $r, 'action' => 'duplicate', 'message' => 'Già in prima nota: saltato', 'warnings' => $warn, 'data' => $data );
				continue;
			}
			$plans[] = array( 'row' => $r, 'action' => 'create', 'message' => '', 'warnings' => $warn, 'data' => $data );
		}
		return self::pair_transfers( $plans, $existing );
	}

	/** Unisce le due righe di un giroconto (in uscita da un conto, in entrata su un altro, stessa data e importo). */
	private static function pair_transfers( array $plans, array $existing = array() ): array {
		$open        = array();
		$seen        = array();
		foreach ( $plans as $i => $p ) {
			if ( 'transfer_leg' !== $p['action'] ) {
				continue;
			}
			$d   = $p['data'];
			$opp = 'transfer_out' === $d['type'] ? 'transfer_in' : 'transfer_out';
			$k   = $d['date'] . '|' . $d['cents'];
			$match = null;
			foreach ( $open[ $opp ][ $k ] ?? array() as $j ) {
				if ( $plans[ $j ]['data']['account_ref'] !== $d['account_ref'] ) {
					$match = $j;
					break;
				}
			}
			if ( null !== $match ) {
				$open[ $opp ][ $k ] = array_values( array_diff( $open[ $opp ][ $k ], array( $match ) ) );
				$out_i = 'transfer_out' === $d['type'] ? $i : $match;
				$in_i  = 'transfer_out' === $d['type'] ? $match : $i;
				$out   = $plans[ $out_i ]['data'];
				$in    = $plans[ $in_i ]['data'];
				$plans[ $out_i ]['action'] = 'transfer';
				$plans[ $out_i ]['data']   = array(
					'date' => $out['date'], 'cents' => $out['cents'], 'method' => $out['method'], 'description' => $out['description'] !== '' ? $out['description'] : $in['description'],
					'from' => array( 'id' => $out['account_id'], 'new' => $out['new_account'], 'type' => $out['new_account_type'], 'ref' => $out['account_ref'] ),
					'to'   => array( 'id' => $in['account_id'], 'new' => $in['new_account'], 'type' => $in['new_account_type'], 'ref' => $in['account_ref'] ),
				);
				$plans[ $out_i ]['message'] = 'Giroconto (accoppiato alla riga ' . $plans[ $in_i ]['row']['line'] . ')';
				$key = self::key( $out['date'], $out['account_ref'] . '>' . $in['account_ref'], 'transfer', $out['cents'], (string) $plans[ $out_i ]['data']['description'], '' );
				$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
				if ( $seen[ $key ] <= (int) ( $existing[ $key ] ?? 0 ) ) {
					$plans[ $out_i ]['action']  = 'duplicate';
					$plans[ $out_i ]['message'] = 'Giroconto già in prima nota: saltato';
				}
				$plans[ $in_i ]['action']   = 'paired';
				$plans[ $in_i ]['message']  = 'Parte del giroconto della riga ' . $plans[ $out_i ]['row']['line'];
				$plans[ $in_i ]['data']     = null;
			} else {
				$open[ $d['type'] ][ $k ][] = $i;
			}
		}
		foreach ( $plans as $i => $p ) {
			if ( 'transfer_leg' === $p['action'] ) {
				$plans[ $i ]['action']  = 'skip';
				$plans[ $i ]['message'] = 'Giroconto senza la riga corrispondente (stessa data e importo su un altro conto): non importato, registralo da "Giroconto"';
				$plans[ $i ]['data']    = null;
			}
		}
		return $plans;
	}

	private static function resolve_category( string $text, bool $income, array $by_name, array $by_kind, array $kinds ): array {
		$none = array( 'id' => 0, 'kind' => '', 'name' => '' );
		$n    = Text::normalize( $text );
		if ( '' === $n ) {
			return $none;
		}
		$ok = function ( string $kind ) use ( $income, $kinds ) {
			return isset( $kinds[ $kind ] ) && ( $income ? $kinds[ $kind ][1] : $kinds[ $kind ][2] );
		};
		foreach ( $by_name[ $n ] ?? array() as $c ) {
			if ( $ok( $c['kind'] ) ) {
				return array( 'id' => (int) $c['id'], 'kind' => $c['kind'], 'name' => $c['name'] );
			}
		}
		foreach ( self::KEYWORDS as $kind => $words ) {
			if ( ! $ok( $kind ) || empty( $by_kind[ $kind ] ) ) {
				continue;
			}
			foreach ( $words as $w ) {
				if ( false !== strpos( $n, $w ) ) {
					$c = $by_kind[ $kind ][0];
					return array( 'id' => (int) $c['id'], 'kind' => $kind, 'name' => $c['name'] );
				}
			}
		}
		return $none;
	}

	public static function method_from_text( string $n ): ?string {
		if ( '' === $n ) {
			return null;
		}
		$map = array(
			'stripe' => array( 'stripe', 'cartaonline' ), 'paypal' => array( 'paypal' ),
			'cash' => array( 'contant', 'cash', 'cassa' ), 'bank_transfer' => array( 'bonific', 'sepa', 'bancari', 'banca', 'rid', 'addebito' ),
			'pos' => array( 'pos', 'carta', 'bancomat', 'visa', 'mastercard' ), 'check' => array( 'assegn' ),
		);
		foreach ( $map as $m => $words ) {
			foreach ( $words as $w ) {
				if ( 0 === strpos( $n, $w ) || ( strlen( $w ) > 4 && false !== strpos( $n, $w ) ) ) {
					return $m;
				}
			}
		}
		return 'altro' === $n || 'other' === $n ? 'other' : null;
	}

	public static function guess_account_type( string $n ): string {
		if ( false !== strpos( $n, 'cassa' ) || false !== strpos( $n, 'contant' ) ) {
			return 'cash';
		}
		if ( false !== strpos( $n, 'pos' ) ) {
			return 'pos';
		}
		if ( false !== strpos( $n, 'paypal' ) || false !== strpos( $n, 'stripe' ) ) {
			return 'other';
		}
		return 'bank';
	}

	/**
	 * Riepilogo per l'anteprima.
	 *
	 * @return array counts, income, expense, per_account [nome => variazione], new_accounts, from, to
	 */
	public static function summary( array $plans, array $account_names = array() ): array {
		$s = array(
			'counts' => array( 'create' => 0, 'transfer' => 0, 'duplicate' => 0, 'skip' => 0, 'error' => 0, 'warn' => 0 ),
			'income' => 0, 'expense' => 0, 'per_account' => array(), 'new_accounts' => array(), 'from' => null, 'to' => null,
		);
		$add = function ( string $ref, ?string $new, ?int $id, int $delta ) use ( &$s, $account_names ) {
			$name = $new ?? ( $account_names[ (int) $id ] ?? ( '#' . (int) $id ) );
			$s['per_account'][ $name ] = ( $s['per_account'][ $name ] ?? 0 ) + $delta;
			if ( $new ) {
				$s['new_accounts'][ $new ] = true;
			}
		};
		foreach ( $plans as $p ) {
			$a = $p['action'];
			if ( isset( $s['counts'][ $a ] ) ) {
				$s['counts'][ $a ]++;
			}
			if ( ! empty( $p['warnings'] ) ) {
				$s['counts']['warn']++;
			}
			if ( 'create' === $a ) {
				$d = $p['data'];
				$s[ 'income' === $d['type'] ? 'income' : 'expense' ] += $d['cents'];
				$add( $d['account_ref'], $d['new_account'], $d['account_id'], 'income' === $d['type'] ? $d['cents'] : -$d['cents'] );
			} elseif ( 'transfer' === $a ) {
				$d = $p['data'];
				$add( $d['from']['ref'], $d['from']['new'], $d['from']['id'], -$d['cents'] );
				$add( $d['to']['ref'], $d['to']['new'], $d['to']['id'], $d['cents'] );
			}
			if ( in_array( $a, array( 'create', 'transfer' ), true ) ) {
				$dt = $p['data']['date'];
				$s['from'] = null === $s['from'] || $dt < $s['from'] ? $dt : $s['from'];
				$s['to']   = null === $s['to'] || $dt > $s['to'] ? $dt : $s['to'];
			}
		}
		$s['new_accounts'] = array_keys( $s['new_accounts'] );
		return $s;
	}
}
