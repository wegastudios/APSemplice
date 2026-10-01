<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

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

	/**
	 * @return array ['error'=>string] oppure ['rows'=>[ ['line','card','type_text','first','last','email','phone','tax'], ... ]]
	 */
	public static function parse( string $text ): array {
		$table = self::read_table( $text );
		if ( ! $table ) {
			return array( 'error' => 'Il file è vuoto.' );
		}
		$header = array_map( array( Text::class, 'normalize' ), $table[0] );
		$col    = function ( array $names ) use ( $header ) {
			foreach ( $header as $i => $h ) {
				if ( in_array( $h, $names, true ) ) {
					return $i;
				}
			}
			return -1;
		};
		$i_card  = $col( array( 'tessera', 'numerotessera', 'ntessera', 'nrtessera', 'numtessera', 'nrtess', 'numero', 'n', 'nr' ) );
		$i_type  = $col( array( 'tipo', 'tiposocio', 'categoria', 'qualifica', 'figura' ) );
		$i_first = $col( array( 'nome', 'firstname', 'name' ) );
		$i_last  = $col( array( 'cognome', 'surname', 'lastname' ) );
		$i_mail  = $col( array( 'email', 'mail', 'emailaddress', 'indirizzoemail', 'postaelettronica' ) );
		$i_phone = $col( array( 'telefono', 'tel', 'cellulare', 'cell', 'mobile', 'phone', 'telefonocellulare' ) );
		$i_tax   = $col( array( 'codicefiscale', 'cf', 'codfisc', 'codicefisc', 'fiscalcode' ) );
		if ( $i_first < 0 || $i_last < 0 ) {
			return array( 'error' => 'Nella prima riga servono almeno le colonne "Nome" e "Cognome". Trovate: ' . implode( ', ', $table[0] ) );
		}
		$cell = function ( array $cells, int $i ) {
			if ( $i < 0 || ! isset( $cells[ $i ] ) ) {
				return null;
			}
			$v = trim( $cells[ $i ] );
			return '' === $v ? null : $v;
		};
		$rows = array();
		foreach ( $table as $idx => $cells ) {
			if ( 0 === $idx ) {
				continue;
			}
			$card   = $cell( $cells, $i_card );
			$tax    = $cell( $cells, $i_tax );
			$rows[] = array(
				'line'      => $idx + 1,
				'card'      => null === $card ? null : self::clean_card( $card ),
				'type_text' => $cell( $cells, $i_type ),
				'first'     => $cell( $cells, $i_first ) ?? '',
				'last'      => $cell( $cells, $i_last ) ?? '',
				'email'     => $cell( $cells, $i_mail ),
				'phone'     => $cell( $cells, $i_phone ),
				'tax'       => null === $tax ? null : strtoupper( str_replace( ' ', '', $tax ) ),
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
		$by_name = array();
		foreach ( $existing as $e ) {
			if ( ! empty( $e['card'] ) ) {
				$by_card[ Text::lower( $e['card'] ) ] = $e;
			}
			if ( ! empty( $e['email'] ) ) {
				$by_mail[ Text::lower( $e['email'] ) ] = $e;
			}
			if ( ! empty( $e['tax'] ) ) {
				$by_tax[ strtoupper( $e['tax'] ) ] = $e;
			}
			$by_name[ Text::normalize( $e['first'] ) . '|' . Text::normalize( $e['last'] ) ][] = $e;
		}
		$label = function ( array $e ) {
			return trim( $e['first'] . ' ' . $e['last'] );
		};

		$cards_in_file = array();
		$mails_in_file = array();
		$tax_in_file   = array();
		$touched       = array();
		$plans         = array();

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
				$plans[] = $err( 'Gli ospiti non si importano: si aggiungono dalla scheda del socio che li ospita' );
				continue;
			}
			$email = $r['email'];
			if ( null === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$plans[] = $err( null === $email ? 'Email mancante (obbligatoria per i soci)' : 'Email non valida: ' . $email );
				continue;
			}

			$card = null === $r['card'] ? null : Text::lower( $r['card'] );
			$mail = Text::lower( $email );
			$tax  = $r['tax'];
			if ( null !== $card && isset( $cards_in_file[ $card ] ) ) {
				$plans[] = $err( 'Tessera ' . $r['card'] . ' già usata alla riga ' . $cards_in_file[ $card ] . ' del file' );
				continue;
			}
			if ( isset( $mails_in_file[ $mail ] ) ) {
				$plans[] = $err( 'Email già presente alla riga ' . $mails_in_file[ $mail ] . ' del file' );
				continue;
			}
			if ( null !== $tax && isset( $tax_in_file[ $tax ] ) ) {
				$plans[] = $err( 'Codice fiscale già presente alla riga ' . $tax_in_file[ $tax ] . ' del file' );
				continue;
			}

			$card_holder = null !== $card ? ( $by_card[ $card ] ?? null ) : null;
			$mail_holder = $by_mail[ $mail ] ?? null;
			$tax_holder  = null !== $tax ? ( $by_tax[ $tax ] ?? null ) : null;
			$name_key    = Text::normalize( $r['first'] ) . '|' . Text::normalize( $r['last'] );
			$by_name_rows = $by_name[ $name_key ] ?? array();

			// La tessera identifica il socio: se è di un'altra persona non si importa.
			if ( $card_holder && Text::normalize( $card_holder['first'] ) . '|' . Text::normalize( $card_holder['last'] ) !== $name_key
				&& ( ! $mail_holder || $mail_holder['id'] === $card_holder['id'] ) ) {
				$plans[] = $err( 'Tessera ' . $r['card'] . ' già assegnata a ' . $label( $card_holder ) );
				continue;
			}
			$holders = array();
			foreach ( array( $card_holder, $mail_holder, $tax_holder ) as $h ) {
				if ( $h ) {
					$holders[ $h['id'] ] = $h;
				}
			}
			if ( count( $holders ) > 1 ) {
				$names = implode( ' / ', array_map( $label, array_values( $holders ) ) );
				$plans[] = $err( 'Tessera, email e codice fiscale indicano persone diverse: ' . $names );
				continue;
			}
			$match = $holders ? reset( $holders ) : null;
			$how   = $card_holder ? 'per tessera' : ( $mail_holder ? 'per email' : 'per codice fiscale' );
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
			$mails_in_file[ $mail ] = $r['line'];
			if ( null !== $tax ) {
				$tax_in_file[ $tax ] = $r['line'];
			}
			$plans[] = array( 'row' => $r, 'type' => $type, 'type_given' => null !== $r['type_text'], 'action' => $act, 'message' => $msg, 'matched_id' => $match ? $match['id'] : null );
		}
		return $plans;
	}
}
