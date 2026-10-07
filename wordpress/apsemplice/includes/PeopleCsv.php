<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Lettura dei file CSV di import soci e piano di importazione (nuovo / aggiorna / errore).
 * Codice puro: non scrive nel database.
 */
final class PeopleCsv {

	const TEMPLATE = "Numero tessera;Tipo;Nome;Cognome;Email;Telefono;Codice fiscale\r\n1;ordinario;Mario;Rossi;mario.rossi@example.com;3331234567;RSSMRA80A01H501U\r\n";

	/** UTF-8 (anche con BOM); se non è UTF-8 valido ripiega su Windows-1252 (Excel). */
	public static function decode( string $bytes ): string {
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $bytes, 'UTF-8' ) ) {
			$bytes = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
		}
		if ( 0 === strpos( $bytes, "\xEF\xBB\xBF" ) ) {
			$bytes = substr( $bytes, 3 );
		}
		return $bytes;
	}

	const COLS = array(
		'card'  => array( 'tessera', 'numerotessera', 'ntessera', 'nrtessera', 'numtessera', 'nrtess', 'numero', 'n', 'nr' ),
		'type'  => array( 'tipo', 'tiposocio', 'categoria', 'qualifica', 'figura' ),
		'first' => array( 'nome', 'firstname', 'name' ),
		'last'  => array( 'cognome', 'surname', 'lastname' ),
		'mail'  => array( 'email', 'mail', 'emailaddress', 'indirizzoemail', 'postaelettronica' ),
		'phone' => array( 'telefono', 'tel', 'cellulare', 'cell', 'mobile', 'phone', 'telefonocellulare' ),
		'tax'   => array( 'codicefiscale', 'cf', 'codfisc', 'codicefisc', 'fiscalcode' ),
		'addr'  => array( 'indirizzo', 'via', 'residenza', 'address' ),
		'zip'   => array( 'cap', 'codicepostale', 'zip', 'postalcode' ),
		'city'  => array( 'comune', 'citta', 'localita', 'city' ),
		'prov'  => array( 'provincia', 'prov', 'province' ),
		'host'  => array( 'ospitedi', 'ospitante', 'socioospitante', 'invitatoda', 'ospiteda', 'tesseraospitante', 'host' ),
	);

	public static function accepts_header( array $h ): bool {
		return SheetReader::col( $h, self::COLS['first'] ) >= 0 && SheetReader::col( $h, self::COLS['last'] ) >= 0;
	}

	/**
	 * @return array ['error'=>string] oppure ['rows'=>[ ['line','card','type_text','first','last','email','phone','tax','host'], ... ]]
	 */
	public static function parse( string $text ): array {
		$table = self::read_table( $text );
		if ( ! $table ) {
			return array( 'error' => 'Il file è vuoto.' );
		}
		return self::parse_table( $table );
	}

	/**
	 * @param array      $table righe di celle (l'intestazione può non essere la prima riga)
	 * @param int[]|null $lines numero di riga originale di ogni riga della tabella
	 */
	public static function parse_table( array $table, ?array $lines = null, string $sheet = '' ): array {
		if ( ! $table ) {
			return array( 'error' => 'Il file è vuoto.' );
		}
		$hi = SheetReader::find_header( $table, array( __CLASS__, 'accepts_header' ) );
		if ( null === $hi ) {
			return array( 'error' => 'Nella prima riga servono almeno le colonne "Nome" e "Cognome". Trovate: ' . implode( ', ', $table[0] ) );
		}
		$header = SheetReader::normalize_header( $table[ $hi ] );
		$col    = array();
		foreach ( self::COLS as $k => $names ) {
			$col[ $k ] = SheetReader::col( $header, $names );
		}
		$cell = function ( array $cells, int $i ) {
			if ( $i < 0 || ! isset( $cells[ $i ] ) ) {
				return null;
			}
			$v = trim( (string) $cells[ $i ] );
			return '' === $v ? null : $v;
		};
		$rows = array();
		foreach ( $table as $idx => $cells ) {
			if ( $idx <= $hi ) {
				continue;
			}
			$card   = $cell( $cells, $col['card'] );
			$tax    = $cell( $cells, $col['tax'] );
			$rows[] = array(
				'line'      => $lines ? (int) ( $lines[ $idx ] ?? $idx + 1 ) : $idx + 1,
				'sheet'     => $sheet,
				'card'      => null === $card ? null : self::clean_card( $card ),
				'type_text' => $cell( $cells, $col['type'] ),
				'first'     => $cell( $cells, $col['first'] ) ?? '',
				'last'      => $cell( $cells, $col['last'] ) ?? '',
				'email'     => $cell( $cells, $col['mail'] ),
				'phone'     => $cell( $cells, $col['phone'] ),
				'tax'       => null === $tax ? null : strtoupper( str_replace( ' ', '', $tax ) ),
				'addr'      => $cell( $cells, $col['addr'] ),
				'zip'       => $cell( $cells, $col['zip'] ),
				'city'      => $cell( $cells, $col['city'] ),
				'prov'      => $cell( $cells, $col['prov'] ),
				'host'      => $cell( $cells, $col['host'] ),
			);
		}
		return array( 'rows' => $rows );
	}


	/** "123.0" (artefatto di Excel) => "123". */
	public static function clean_card( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^(\d+)[.,]0+$/', $raw, $m ) ) {
			return $m[1];
		}
		return $raw;
	}

	/** Righe di celle, senza righe completamente vuote. Gestisce i campi tra virgolette. */
	public static function read_table( string $text ): array {
		$first_line = '';
		foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$first_line = $l;
				break;
			}
		}
		if ( '' === $first_line ) {
			return array();
		}
		$delim = ';';
		$best  = substr_count( $first_line, ';' );
		foreach ( array( ',', "\t" ) as $d ) {
			if ( substr_count( $first_line, $d ) > $best ) {
				$best  = substr_count( $first_line, $d );
				$delim = $d;
			}
		}

		$rows   = array();
		$row    = array();
		$cell   = '';
		$quoted = false;
		$len    = strlen( $text );
		$end_row = function () use ( &$rows, &$row, &$cell ) {
			$row[] = $cell;
			$cell  = '';
			foreach ( $row as $c ) {
				if ( '' !== trim( $c ) ) {
					$rows[] = $row;
					break;
				}
			}
			$row = array();
		};
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $quoted && '"' === $ch && $i + 1 < $len && '"' === $text[ $i + 1 ] ) {
				$cell .= '"';
				$i++;
			} elseif ( '"' === $ch ) {
				$quoted = ! $quoted;
			} elseif ( ! $quoted && $ch === $delim ) {
				$row[] = $cell;
				$cell  = '';
			} elseif ( ! $quoted && "\n" === $ch ) {
				$end_row();
			} elseif ( ! $quoted && "\r" === $ch ) {
				continue;
			} else {
				$cell .= $ch;
			}
		}
		if ( '' !== $cell || $row ) {
			$end_row();
		}
		return $rows;
	}

	/**
	 * Decide per ogni riga se creare, aggiornare o rifiutare.
	 * Riconoscimento di una persona già presente: tessera, poi email, poi codice fiscale, poi nome+cognome (se univoco).
	 *
	 * @param array  $rows         righe di {@see parse()}
	 * @param array  $existing     [ ['id','card','first','last','email','tax'], ... ]
	 * @param string $default_type tipo da usare quando la colonna Tipo manca o è vuota
	 * @return array [ ['row'=>riga, 'type'=>tipo|null, 'action'=>'create'|'update'|'error', 'message'=>?, 'matched_id'=>?], ... ]
	 */
	public static function plan( array $rows, array $existing, string $default_type = MemberType::ORDINARY ): array {
		$by_card = array();
		$by_mail = array();
		$by_tax  = array();
		$by_phone = array();
		$by_name = array();
		$guests_of   = array();
		$by_name_all = array();
		foreach ( $existing as $e ) {
			if ( MemberType::GUEST === ( $e['type'] ?? '' ) ) {
				// Gli ospiti non sono soci: non si riconoscono per tessera o email, ma per ospitante e nome.
				$guests_of[ (int) ( $e['host_id'] ?? 0 ) ][ Text::normalize( $e['first'] . $e['last'] ) ] = $e;
				continue;
			}
			if ( ! empty( $e['card'] ) ) {
				$by_card[ Text::lower( $e['card'] ) ] = $e;
			}
			if ( ! empty( $e['email'] ) ) {
				$by_mail[ Text::lower( $e['email'] ) ] = $e;
			}
			if ( ! empty( $e['tax'] ) ) {
				$by_tax[ strtoupper( $e['tax'] ) ] = $e;
			}
			if ( ! empty( $e['phone'] ) && Phone::is_valid( (string) $e['phone'] ) ) {
				$by_phone[ Phone::key( (string) $e['phone'] ) ] = $e;
			}
			$by_name[ Text::normalize( $e['first'] ) . '|' . Text::normalize( $e['last'] ) ][] = $e;
			$by_name_all[ Text::normalize( $e['first'] . $e['last'] ) ][]                     = $e;
			$by_name_all[ Text::normalize( $e['last'] . $e['first'] ) ][]                     = $e;
		}
		$label = function ( array $e ) {
			return trim( $e['first'] . ' ' . $e['last'] );
		};

		$cards_in_file = array();
		$mails_in_file = array();
		$tax_in_file   = array();
		$touched       = array();
		$plans         = array();
		$deferred      = array();

		foreach ( $rows as $r ) {
			$err = function ( string $msg ) use ( $r ) {
				return array( 'row' => $r, 'type' => null, 'action' => 'error', 'message' => $msg, 'matched_id' => null );
			};

			if ( '' === trim( $r['first'] ) || '' === trim( $r['last'] ) ) {
				$plans[] = $err( 'Nome o cognome mancante' );
				continue;
			}
			$type = $default_type;
			if ( null !== $r['type_text'] ) {
				$type = MemberType::from_text( $r['type_text'] );
				if ( null === $type ) {
					$plans[] = $err( 'Tipo non riconosciuto: "' . $r['type_text'] . '" (usa fondatore, ordinario, volontario)' );
					continue;
				}
			}
			if ( MemberType::GUEST === $type ) {
				$deferred[ count( $plans ) ] = $r; // si risolve dopo i soci: l'ospitante può essere nello stesso file
				$plans[]                     = null;
				continue;
			}
			$email = $r['email'];
			if ( null !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$plans[] = $err( 'Email non valida: ' . $email );
				continue;
			}
			// L'email non è obbligatoria: un socio senza email si attiva dopo (link su WhatsApp). Serve però un modo per riconoscerlo.
			if ( null === $email && null === $r['card'] && ! Phone::is_valid( (string) $r['phone'] ) ) {
				$plans[] = $err( 'Servono almeno l\'email, il cellulare o il numero di tessera' );
				continue;
			}

			$card = null === $r['card'] ? null : Text::lower( $r['card'] );
			$mail = null === $email ? null : Text::lower( $email );
			$tax  = $r['tax'];
			if ( null !== $card && isset( $cards_in_file[ $card ] ) ) {
				$plans[] = $err( 'Tessera ' . $r['card'] . ' già usata alla riga ' . $cards_in_file[ $card ] . ' del file' );
				continue;
			}
			if ( null !== $mail && isset( $mails_in_file[ $mail ] ) ) {
				$plans[] = $err( 'Email già presente alla riga ' . $mails_in_file[ $mail ] . ' del file' );
				continue;
			}
			if ( null !== $tax && isset( $tax_in_file[ $tax ] ) ) {
				$plans[] = $err( 'Codice fiscale già presente alla riga ' . $tax_in_file[ $tax ] . ' del file' );
				continue;
			}

			$card_holder = null !== $card ? ( $by_card[ $card ] ?? null ) : null;
			$mail_holder = null !== $mail ? ( $by_mail[ $mail ] ?? null ) : null;
			$tax_holder  = null !== $tax ? ( $by_tax[ $tax ] ?? null ) : null;
			// Senza email, il cellulare riconosce un socio già presente.
			$phone_holder = null === $mail && Phone::is_valid( (string) $r['phone'] ) ? ( $by_phone[ Phone::key( (string) $r['phone'] ) ] ?? null ) : null;
			$name_key    = Text::normalize( $r['first'] ) . '|' . Text::normalize( $r['last'] );
			$by_name_rows = $by_name[ $name_key ] ?? array();

			// La tessera identifica il socio: se è di un'altra persona non si importa.
			if ( $card_holder && Text::normalize( $card_holder['first'] ) . '|' . Text::normalize( $card_holder['last'] ) !== $name_key
				&& ( ! $mail_holder || $mail_holder['id'] === $card_holder['id'] ) ) {
				$plans[] = $err( 'Tessera ' . $r['card'] . ' già assegnata a ' . $label( $card_holder ) );
				continue;
			}
			$holders = array();
			foreach ( array( $card_holder, $mail_holder, $tax_holder, $phone_holder ) as $h ) {
				if ( $h ) {
					$holders[ $h['id'] ] = $h;
				}
			}
			if ( count( $holders ) > 1 ) {
				$names = implode( ' / ', array_map( $label, array_values( $holders ) ) );
				$plans[] = $err( 'Tessera, email, cellulare e codice fiscale indicano persone diverse: ' . $names );
				continue;
			}
			$match = $holders ? reset( $holders ) : null;
			$how   = $card_holder ? 'per tessera' : ( $mail_holder ? 'per email' : ( $phone_holder ? 'per cellulare' : 'per codice fiscale' ) );
			if ( ! $match && 1 === count( $by_name_rows ) ) {
				$match = $by_name_rows[0];
				$how   = 'per nome e cognome';
			}
			if ( ! $match && count( $by_name_rows ) > 1 ) {
				$plans[] = $err( 'Più persone con questo nome: servono tessera o codice fiscale per distinguerle' );
				continue;
			}

			if ( $match ) {
				if ( isset( $touched[ $match['id'] ] ) ) {
					$plans[] = $err( 'Lo stesso socio è già aggiornato dalla riga ' . $touched[ $match['id'] ] );
					continue;
				}
				$touched[ $match['id'] ] = $r['line'];
				$msg = 'Socio esistente (' . $how . ')';
				$act = 'update';
			} else {
				$msg = null === $card ? 'Senza tessera: assegnabile dopo' : null;
				$act = 'create';
			}
			if ( null !== $card ) {
				$cards_in_file[ $card ] = $r['line'];
			}
			if ( null !== $mail ) {
				$mails_in_file[ $mail ] = $r['line'];
			}
			if ( null !== $tax ) {
				$tax_in_file[ $tax ] = $r['line'];
			}
			$plans[] = array( 'row' => $r, 'type' => $type, 'type_given' => null !== $r['type_text'], 'action' => $act, 'message' => $msg, 'matched_id' => $match ? $match['id'] : null );
		}
		if ( $deferred ) {
			// Soci già presenti e soci del file, per trovare l'ospitante di ogni ospite.
			$file_by_card = array();
			$file_by_mail = array();
			$file_by_name = array();
			foreach ( $plans as $p ) {
				if ( ! $p || 'error' === $p['action'] || MemberType::GUEST === $p['type'] ) {
					continue;
				}
				$fr = $p['row'];
				if ( null !== $fr['card'] ) {
					$file_by_card[ Text::lower( $fr['card'] ) ][] = $fr['line'];
				}
				if ( null !== $fr['email'] ) {
					$file_by_mail[ Text::lower( $fr['email'] ) ][] = $fr['line'];
				}
				$file_by_name[ Text::normalize( $fr['first'] . $fr['last'] ) ][]  = $fr['line'];
				$file_by_name[ Text::normalize( $fr['last'] . $fr['first'] ) ][] = $fr['line'];
			}
			$guests_in_file = array();
			foreach ( $deferred as $idx => $r ) {
				$err = function ( string $msg ) use ( $r ) {
					return array( 'row' => $r, 'type' => null, 'action' => 'error', 'message' => $msg, 'matched_id' => null );
				};
				$host = null === $r['host'] ? '' : trim( $r['host'] );
				if ( '' === $host ) {
					$plans[ $idx ] = $err( 'Ospite senza l\'indicazione del socio ospitante (colonna "Ospite di": tessera, email o nome e cognome)' );
					continue;
				}
				if ( null !== $r['email'] && ! filter_var( $r['email'], FILTER_VALIDATE_EMAIL ) ) {
					$plans[ $idx ] = $err( 'Email non valida: ' . $r['email'] );
					continue;
				}
				if ( ! Phone::is_valid( (string) $r['phone'] ) ) {
					$plans[ $idx ] = $err( 'Cellulare mancante o non valido (obbligatorio per gli ospiti: serve a riconoscerli ed evitare doppioni)' );
					continue;
				}
				// Chi è l'ospitante: tessera, email oppure nome e cognome (in uno dei due ordini).
				$existing_ids = array();
				$file_lines   = array();
				$hl           = Text::lower( PeopleCsv::clean_card( $host ) );
				if ( false !== strpos( $host, '@' ) ) {
					$hm = Text::lower( $host );
					if ( isset( $by_mail[ $hm ] ) ) {
						$existing_ids[ $by_mail[ $hm ]['id'] ] = $by_mail[ $hm ];
					}
					$file_lines = $file_by_mail[ $hm ] ?? array();
				} else {
					if ( isset( $by_card[ $hl ] ) ) {
						$existing_ids[ $by_card[ $hl ]['id'] ] = $by_card[ $hl ];
					}
					$file_lines = $file_by_card[ $hl ] ?? array();
					if ( ! $existing_ids && ! $file_lines ) {
						$hn = Text::normalize( $host );
						foreach ( $by_name_all[ $hn ] ?? array() as $e ) {
							$existing_ids[ $e['id'] ] = $e;
						}
						$file_lines = $file_by_name[ $hn ] ?? array();
					}
				}
				$found = $existing_ids ? count( $existing_ids ) : count( array_unique( $file_lines ) );
				if ( 0 === $found ) {
					$plans[ $idx ] = $err( 'Socio ospitante non trovato: "' . $host . '"' );
					continue;
				}
				if ( $found > 1 ) {
					$plans[ $idx ] = $err( 'Socio ospitante ambiguo: "' . $host . '" (indica la tessera)' );
					continue;
				}
				$host_id   = $existing_ids ? (int) array_keys( $existing_ids )[0] : 0;
				$host_line = $host_id ? 0 : (int) $file_lines[0];
				$gkey      = ( $host_id ? 'id' . $host_id : 'riga' . $host_line ) . '|' . Text::normalize( $r['first'] . $r['last'] );
				if ( isset( $guests_in_file[ $gkey ] ) ) {
					$plans[ $idx ] = $err( 'Lo stesso ospite è già alla riga ' . $guests_in_file[ $gkey ] . ' del file' );
					continue;
				}
				$guests_in_file[ $gkey ] = $r['line'];
				$match                   = $host_id ? ( $guests_of[ $host_id ][ Text::normalize( $r['first'] . $r['last'] ) ] ?? null ) : null;
				$plans[ $idx ]           = array(
					'row' => $r, 'type' => MemberType::GUEST, 'type_given' => true, 'action' => $match ? 'update' : 'create',
					'message' => $match ? 'Ospite esistente' : null, 'matched_id' => $match ? (int) $match['id'] : null,
					'host_text' => $host, 'host_id' => $host_id ?: null,
				);
			}
		}
		return $plans;
	}
}
