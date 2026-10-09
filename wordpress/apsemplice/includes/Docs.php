<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Documenti stampabili dei registri: libro soci, registro volontari, presenze, verbali e rendiconto, in PDF (e CSV per gli elenchi).
 * Il PDF è generato dal plugin (classe Pdf), senza librerie esterne.
 */
final class Docs {

	const LEFT   = 50.0;
	const RIGHT  = 545.0; // Pdf::W - 50
	const BOTTOM = 790.0;

	public static function register(): void {
		add_action( 'admin_post_apse_doc', array( __CLASS__, 'handle' ) );
	}

	public static function url( string $what, array $args = array(), string $format = 'pdf' ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'apse_doc', 'what' => $what, 'format' => $format ), $args ), admin_url( 'admin-post.php' ) ), 'apse_doc' );
	}

	private static function d( ?string $ymd ): string {
		return $ymd ? ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) : '';
	}

	private static function fit( string $s, float $w, float $size, bool $bold = false ): string {
		if ( Pdf::width( $s, $size, $bold ) <= $w ) {
			return $s;
		}
		while ( '' !== $s && Pdf::width( $s . '…', $size, $bold ) > $w ) {
			$s = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, -1, 'UTF-8' ) : substr( $s, 0, -1 );
		}
		return $s . '…';
	}

	private static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = Admin\Exports::neutralize( null === $c ? '' : (string) $c );
			$out[] = preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ';', $out ) . "\r\n";
	}

	private static function csv( array $cols, array $rows ): string {
		$s = self::csv_line( array_column( $cols, 'label' ) );
		foreach ( $rows as $r ) {
			$s .= self::csv_line( $r );
		}
		return "\xEF\xBB\xBF" . $s;
	}

	private static function president(): string {
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				return trim( $b['first_name'] . ' ' . $b['last_name'] );
			}
		}
		return '';
	}

	/** Intestazione dell'associazione, titolo e sottotitolo. @return float la y da cui continuare */
	private static function head( Pdf $pdf, string $title, string $subtitle = '' ): float {
		$name = (string) Settings::get( 'association_name' );
		$cf   = (string) Settings::get( 'tax_code' );
		$pdf->text( self::LEFT, 56, '' !== $name ? $name : 'Associazione', 15, true );
		if ( '' !== $cf ) {
			$pdf->text( self::LEFT, 72, 'Codice fiscale: ' . $cf, 9 );
		}
		$pdf->line( self::LEFT, 82, self::RIGHT, 82, 1.0 );
		$pdf->text( self::LEFT, 106, $title, 13, true );
		$y = 106.0;
		if ( '' !== $subtitle ) {
			$y += 15;
			$pdf->text( self::LEFT, $y, $subtitle, 9 );
		}
		return $y + 20;
	}

	/**
	 * Tabella a più pagine.
	 *
	 * @param array[] $cols [label, w (punti), align L|R|C]
	 * @param array[] $rows celle di testo
	 */
	private static function table_pdf( string $title, string $subtitle, array $cols, array $rows, string $foot = '' ): string {
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$y           = self::head( $pdf, $title, $subtitle );
		$header      = function ( Pdf $pdf, float $y ) use ( $cols ) {
			$pdf->rect( self::LEFT, $y - 11, self::RIGHT - self::LEFT, 17 );
			$x = self::LEFT;
			foreach ( $cols as $c ) {
				$pdf->text( 'R' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] - 3 : $x + 3, $y + 1, self::fit( (string) $c['label'], $c['w'] - 6, 8, true ), 8, true, $c['align'] ?? 'L' );
				$x += $c['w'];
			}
		};
		$header( $pdf, $y );
		$y += 20;
		foreach ( $rows as $i => $r ) {
			if ( $y > self::BOTTOM ) {
				$pdf->add_page();
				$y = 60.0;
				$header( $pdf, $y );
				$y += 20;
			}
			if ( 0 === $i % 2 ) {
				$pdf->rect( self::LEFT, $y - 9, self::RIGHT - self::LEFT, 13, 0.97 );
			}
			$x = self::LEFT;
			foreach ( $cols as $k => $c ) {
				$pdf->text( 'R' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] - 3 : ( 'C' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] / 2 : $x + 3 ), $y, self::fit( (string) ( $r[ $k ] ?? '' ), $c['w'] - 6, 8 ), 8, false, $c['align'] ?? 'L' );
				$x += $c['w'];
			}
			$y += 13;
		}
		if ( ! $rows ) {
			$pdf->text( self::LEFT + 3, $y, 'Nessun dato.', 9 );
			$y += 13;
		}
		if ( '' !== $foot ) {
			$y += 14;
			foreach ( Pdf::wrap( $foot, self::RIGHT - self::LEFT, 8 ) as $line ) {
				$pdf->text( self::LEFT, min( $y, self::BOTTOM + 30 ), $line, 8 );
				$y += 11;
			}
		}
		$out         = $pdf->output();
		Pdf::$filter = null;
		return $out;
	}

	// ---------- Libro soci ----------

	private static function book_cols(): array {
		return array(
			array( 'label' => 'N.', 'w' => 26, 'align' => 'R' ), array( 'label' => 'Cognome e nome', 'w' => 135 ), array( 'label' => 'Codice fiscale', 'w' => 98 ),
			array( 'label' => 'Livello', 'w' => 80 ), array( 'label' => 'Ingresso', 'w' => 56 ), array( 'label' => 'Cessazione', 'w' => 100 ),
		);
	}

	private static function book_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( (string) $r['n'], $r['name'], $r['tax_code'], $r['level'], self::d( $r['joined_on'] ), $r['left_on'] ? self::d( $r['left_on'] ) . ( '' !== $r['left_reason'] ? ' · ' . $r['left_reason'] : '' ) : '' );
		}
		return $out;
	}

	/** @return array{body:string,filename:string,mime:string} */
	public static function book( string $filter = '', string $format = 'pdf' ): array {
		$rows   = MemberBook::rows( $filter );
		$all    = MemberBook::rows();
		$active = count( MemberBook::rows( MemberBook::IN_FORCE ) );
		$cells  = self::book_rows( $rows );
		if ( 'csv' === $format ) {
			return array( 'body' => self::csv( self::book_cols(), $cells ), 'filename' => 'libro-soci.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = 'Aggiornato al ' . self::d( Db::today() ) . ' · ' . count( $all ) . ' soci iscritti, ' . $active . ' in carica';
		return array( 'body' => self::table_pdf( 'LIBRO DEI SOCI', $sub, self::book_cols(), $cells ), 'filename' => 'libro-soci.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Registro volontari e assicurazione ----------

	private static function vol_cols(): array {
		return array(
			array( 'label' => 'Cognome e nome', 'w' => 140 ), array( 'label' => 'Codice fiscale', 'w' => 96 ), array( 'label' => 'Compagnia', 'w' => 90 ),
			array( 'label' => 'Polizza', 'w' => 66 ), array( 'label' => 'Copertura fino al', 'w' => 52 ), array( 'label' => 'Stato', 'w' => 51 ),
		);
	}

	/** @return array{body:string,filename:string,mime:string} */
	public static function volunteers( string $format = 'pdf' ): array {
		$labels = Insurance::status_labels();
		$cells  = array();
		foreach ( Insurance::register() as $r ) {
			$p       = $r['person'];
			$pol     = $r['policy'];
			$cells[] = array( trim( $p['last_name'] . ' ' . $p['first_name'] ), (string) $p['tax_code'], $pol ? (string) $pol['company'] : '', $pol ? (string) $pol['policy_no'] : '', $pol ? self::d( $pol['valid_to'] ) : '', $labels[ $r['status'] ] );
		}
		if ( 'csv' === $format ) {
			return array( 'body' => self::csv( self::vol_cols(), $cells ), 'filename' => 'registro-volontari.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = 'Aggiornato al ' . self::d( Db::today() ) . ' · ' . count( $cells ) . ' volontari';
		return array( 'body' => self::table_pdf( 'REGISTRO DEI VOLONTARI E ASSICURAZIONE', $sub, self::vol_cols(), $cells ), 'filename' => 'registro-volontari.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Registro presenze ----------

	/** @return array{body:string,filename:string,mime:string} @throws \InvalidArgumentException */
	public static function attendance( int $activity_id, string $from, string $to, string $format = 'pdf' ): array {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		foreach ( array( $from, $to ) as $day ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				throw new \InvalidArgumentException( 'Indica il periodo (dal / al).' );
			}
		}
		$s     = Attendance::summary( $activity_id, $from, $to );
		$dates = $s['dates'];
		$matrix = count( $dates ) <= 10;
		$cols  = array( array( 'label' => 'Cognome e nome', 'w' => $matrix ? 125 : 255 ) );
		if ( $matrix ) {
			foreach ( $dates as $d ) {
				$cols[] = array( 'label' => substr( $d, 8, 2 ) . '/' . substr( $d, 5, 2 ), 'w' => 33, 'align' => 'C' );
			}
			$cols[] = array( 'label' => 'Presenze', 'w' => 40, 'align' => 'R' );
		} else {
			$cols[] = array( 'label' => 'Presenze', 'w' => 80, 'align' => 'R' );
			$cols[] = array( 'label' => 'Lezioni', 'w' => 80, 'align' => 'R' );
			$cols[] = array( 'label' => '%', 'w' => 80, 'align' => 'R' );
		}
		$cells = array();
		foreach ( $s['people'] as $p ) {
			$row = array( trim( $p['last_name'] . ' ' . $p['first_name'] ) );
			if ( $matrix ) {
				foreach ( $dates as $d ) {
					$row[] = isset( $p['by_date'][ $d ] ) ? ( $p['by_date'][ $d ] ? 'P' : 'A' ) : '';
				}
				$row[] = $p['presenze'] . '/' . $p['totale'];
			} else {
				$row[] = (string) $p['presenze'];
				$row[] = (string) $p['totale'];
				$row[] = $p['totale'] ? (string) round( 100 * $p['presenze'] / $p['totale'] ) . '%' : '';
			}
			$cells[] = $row;
		}
		$slug = sanitize_title( (string) $a['name'] );
		if ( 'csv' === $format ) {
			$csv_cols = $cols;
			return array( 'body' => self::csv( $csv_cols, $cells ), 'filename' => 'presenze-' . $slug . '.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = $a['name'] . ' · dal ' . self::d( $from ) . ' al ' . self::d( $to ) . ' · ' . count( $dates ) . ' lezioni registrate';
		return array( 'body' => self::table_pdf( 'REGISTRO PRESENZE', $sub, $cols, $cells, 'P = presente, A = assente.' ), 'filename' => 'presenze-' . $slug . '.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Verbale ----------

	/** @return array{body:string,filename:string,mime:string} @throws \InvalidArgumentException */
	public static function minute( int $id ): array {
		$m = Minutes::get( $id );
		if ( ! $m ) {
			throw new \InvalidArgumentException( 'Verbale non trovato.' );
		}
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$kinds       = Minutes::kinds();
		$y           = self::head( $pdf, 'VERBALE N. ' . $m['number'] . ' — ' . mb_strtoupper( $kinds[ $m['kind'] ] ), $m['title'] );
		$w           = self::RIGHT - self::LEFT;
		$put         = function ( string $text, float $size = 10, bool $bold = false ) use ( $pdf, &$y, $w ) {
			foreach ( preg_split( "/\r\n|\n|\r/", $text ) ?: array( '' ) as $para ) {
				foreach ( '' === trim( $para ) ? array( '' ) : Pdf::wrap( $para, $w, $size, $bold ) as $line ) {
					if ( $y > self::BOTTOM ) {
						$pdf->add_page();
						$y = 60.0;
					}
					$pdf->text( self::LEFT, $y, $line, $size, $bold );
					$y += $size + 4;
				}
			}
		};
		$put( 'Data: ' . self::d( $m['meeting_date'] ) . ( '' !== (string) $m['place'] ? ' · Luogo: ' . $m['place'] : '' ) );
		$y += 6;
		if ( '' !== (string) $m['attendees'] ) {
			$put( 'Presenti', 10, true );
			$put( (string) $m['attendees'] );
			$y += 6;
		}
		if ( '' !== (string) $m['agenda'] ) {
			$put( 'Ordine del giorno', 10, true );
			$put( (string) $m['agenda'] );
			$y += 6;
		}
		$put( 'Svolgimento e deliberazioni', 10, true );
		$put( (string) $m['body'] );
		$y += 10;
		if ( $m['approved_on'] ) {
			$put( 'Verbale letto e approvato il ' . self::d( $m['approved_on'] ) . '.' );
		}
		$y += 24;
		if ( $y > self::BOTTOM - 40 ) {
			$pdf->add_page();
			$y = 80.0;
		}
		$pdf->line( self::LEFT, $y + 24, self::LEFT + 170, $y + 24 );
		$pdf->text( self::LEFT, $y + 37, 'Il Segretario', 9 );
		$pdf->line( self::RIGHT - 170, $y + 24, self::RIGHT, $y + 24 );
		$pres = self::president();
		$pdf->text( self::RIGHT, $y + 37, 'Il Presidente' . ( '' !== $pres ? ' ' . $pres : '' ), 9, false, 'R' );
		$out         = $pdf->output();
		Pdf::$filter = null;
		return array( 'body' => $out, 'filename' => 'verbale-' . str_replace( '/', '-', $m['number'] ) . '-' . $m['kind'] . '.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Rendiconto ----------

	/** @return array{body:string,filename:string,mime:string} */
	public static function statement( int $year ): array {
		if ( $year < 2000 || $year > (int) substr( Db::today(), 0, 4 ) + 1 ) {
			throw new \InvalidArgumentException( 'Anno non valido.' );
		}
		$s = Statement::data( $year );
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$y           = self::head( $pdf, 'RENDICONTO PER CASSA — ANNO ' . $year, 'Entrate e uscite dal 1° gennaio al 31 dicembre ' . $year . ', con il confronto con l\'anno ' . ( $year - 1 ) );
		$cx          = self::RIGHT - 3;
		$px          = self::RIGHT - 3 - 85;
		$ensure      = function ( float $need ) use ( $pdf, &$y ) {
			if ( $y + $need > self::BOTTOM ) {
				$pdf->add_page();
				$y = 60.0;
			}
		};
		$colhead     = function () use ( $pdf, &$y, $cx, $px, $year ) {
			$pdf->rect( self::LEFT, $y - 11, self::RIGHT - self::LEFT, 17 );
			$pdf->text( $px, $y + 1, (string) ( $year - 1 ), 8, true, 'R' );
			$pdf->text( $cx, $y + 1, (string) $year, 8, true, 'R' );
			$y += 20;
		};
		$section = function ( string $label, array $groups, array $total ) use ( $pdf, &$y, $cx, $px, $ensure, $colhead ) {
			$ensure( 50 );
			$pdf->text( self::LEFT + 3, $y + 1, $label, 10, true );
			$colhead();
			foreach ( $groups as $g ) {
				$ensure( 30 );
				$pdf->text( self::LEFT + 3, $y, self::fit( $g['group'], 330, 9, true ), 9, true );
				$pdf->text( $px, $y, Money::format( $g['prev'] ), 9, true, 'R' );
				$pdf->text( $cx, $y, Money::format( $g['cur'] ), 9, true, 'R' );
				$y += 13;
				foreach ( $g['lines'] as $l ) {
					$ensure( 14 );
					$pdf->text( self::LEFT + 14, $y, self::fit( $l['name'], 320, 8 ), 8 );
					$pdf->text( $px, $y, Money::format( $l['prev'] ), 8, false, 'R' );
					$pdf->text( $cx, $y, Money::format( $l['cur'] ), 8, false, 'R' );
					$y += 12;
				}
				$y += 3;
			}
			$ensure( 20 );
			$pdf->line( self::LEFT, $y - 8, self::RIGHT, $y - 8 );
			$pdf->text( self::LEFT + 3, $y + 3, 'Totale', 9, true );
			$pdf->text( $px, $y + 3, Money::format( $total['prev'] ), 9, true, 'R' );
			$pdf->text( $cx, $y + 3, Money::format( $total['cur'] ), 9, true, 'R' );
			$y += 24;
		};
		$section( 'ENTRATE', $s['income'], $s['total_income'] );
		$section( 'USCITE', $s['expenses'], $s['total_expense'] );
		$ensure( 40 );
		$pdf->rect( self::LEFT, $y - 11, self::RIGHT - self::LEFT, 19, 0.9 );
		$pdf->text( self::LEFT + 3, $y + 2, $s['result']['cur'] >= 0 ? 'AVANZO DI GESTIONE' : 'DISAVANZO DI GESTIONE', 10, true );
		$pdf->text( $px, $y + 2, Money::format( $s['result']['prev'] ), 10, true, 'R' );
		$pdf->text( $cx, $y + 2, Money::format( $s['result']['cur'] ), 10, true, 'R' );
		$y += 34;

		$ensure( 60 );
		$pdf->text( self::LEFT + 3, $y, 'CASSA E CONTI', 10, true );
		$pdf->rect( self::LEFT, $y + 5, self::RIGHT - self::LEFT, 17 );
		$pdf->text( $px, $y + 17, 'Iniziale', 8, true, 'R' );
		$pdf->text( $cx, $y + 17, 'Finale', 8, true, 'R' );
		$y += 36;
		foreach ( $s['accounts'] as $a ) {
			$ensure( 14 );
			$pdf->text( self::LEFT + 14, $y, self::fit( $a['name'], 320, 8 ), 8 );
			$pdf->text( $px, $y, Money::format( $a['opening'] ), 8, false, 'R' );
			$pdf->text( $cx, $y, Money::format( $a['closing'] ), 8, false, 'R' );
			$y += 12;
		}
		$pdf->line( self::LEFT, $y - 6, self::RIGHT, $y - 6 );
		$pdf->text( self::LEFT + 3, $y + 5, 'Totale', 9, true );
		$pdf->text( $px, $y + 5, Money::format( $s['opening_total'] ), 9, true, 'R' );
		$pdf->text( $cx, $y + 5, Money::format( $s['closing_total'] ), 9, true, 'R' );
		$y += 20;
		if ( 0 !== $s['funds_total'] ) {
			$ensure( 30 );
			$pdf->text( self::LEFT + 14, $y, 'di cui accantonato nei fondi (rimborsi ai volontari)', 8 );
			$pdf->text( $cx, $y, Money::format( $s['funds_total'] ), 8, false, 'R' );
			$y += 12;
			$pdf->text( self::LEFT + 14, $y, 'Disponibilità effettiva', 8, true );
			$pdf->text( $cx, $y, Money::format( $s['available'] ), 8, true, 'R' );
			$y += 12;
		}
		$y += 10;
		if ( '' !== $s['notes'] ) {
			$ensure( 40 );
			$pdf->text( self::LEFT + 3, $y, 'RELAZIONE SULL\'ANDAMENTO DELLA GESTIONE', 10, true );
			$y += 16;
			foreach ( preg_split( "/\r\n|\n|\r/", $s['notes'] ) ?: array() as $para ) {
				foreach ( '' === trim( $para ) ? array( '' ) : Pdf::wrap( $para, self::RIGHT - self::LEFT, 9 ) as $line ) {
					$ensure( 14 );
					$pdf->text( self::LEFT + 3, $y, $line, 9 );
					$y += 12;
				}
			}
			$y += 8;
		}
		$ensure( 70 );
		$y += 24;
		$pdf->line( self::LEFT, $y + 24, self::LEFT + 170, $y + 24 );
		$pdf->text( self::LEFT, $y + 37, 'Il Tesoriere', 9 );
		$pdf->line( self::RIGHT - 170, $y + 24, self::RIGHT, $y + 24 );
		$pres = self::president();
		$pdf->text( self::RIGHT, $y + 37, 'Il Presidente' . ( '' !== $pres ? ' ' . $pres : '' ), 9, false, 'R' );
		$out         = $pdf->output();
		Pdf::$filter = null;
		return array( 'body' => $out, 'filename' => 'rendiconto-' . $year . '.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Download ----------

	private static function send( array $doc ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $doc['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( ( 0 === strpos( $doc['mime'], 'text/csv' ) ? 'Content-Disposition: attachment' : 'Content-Disposition: inline' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $doc['filename'] ) . '"' );
		header( 'Content-Length: ' . strlen( $doc['body'] ) );
		echo $doc['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- file
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'apse_doc' );
		if ( ! Edition::allows( 'export' ) ) {
			wp_die( 'L\'esportazione dei dati è sospesa perché la licenza di APSemplice non risulta in regola.', 'Licenza non in regola', array( 'response' => 402, 'back_link' => true ) );
		}
		$g      = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$what   = (string) ( $g['what'] ?? '' );
		$format = 'csv' === ( $g['format'] ?? '' ) ? 'csv' : 'pdf';
		$needs  = array( 'volunteers' => 'insurance', 'attendance' => 'insurance', 'statement' => 'fiscal' ); // documenti delle funzioni avanzate
		if ( isset( $needs[ $what ] ) && ! Edition::has( $needs[ $what ] ) ) {
			wp_die( 'Questo documento non è disponibile in questa edizione.', 403 );
		}
		try {
			switch ( $what ) {
				case 'book':
					$doc = self::book( (string) ( $g['filter'] ?? '' ), $format );
					break;
				case 'volunteers':
					$doc = self::volunteers( $format );
					break;
				case 'attendance':
					$doc = self::attendance( (int) ( $g['activity'] ?? 0 ), (string) ( $g['from'] ?? '' ), (string) ( $g['to'] ?? '' ), $format );
					break;
				case 'minute':
					$doc = self::minute( (int) ( $g['id'] ?? 0 ) );
					break;
				case 'statement':
					$doc = self::statement( (int) ( $g['year'] ?? 0 ) );
					break;
				case 'privacy':
					$doc = PrivacyNotice::pdf();
					break;
				default:
					wp_die( 'Documento non valido.', 400 );
			}
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send( $doc );
	}
}
