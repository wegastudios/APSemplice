<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ricevute di pagamento e attestazioni annuali dei versamenti, in PDF.
 *
 * Una ricevuta è un incasso (tutte le righe con lo stesso `receipt_id`; per i movimenti vecchi senza codice, la singola riga).
 * Il numero progressivo (N/AAAA, per anno) si assegna alla prima emissione e non cambia più. Un incasso annullato non ha ricevuta.
 * Le vede chi amministra e chi ha pagato (o il socio che ospita chi ha pagato).
 */
final class Receipts {

	const MONTHS = array( 1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' );

	public static function register(): void {
		add_action( 'admin_post_apse_receipt', array( __CLASS__, 'handle_receipt' ) );
		add_action( 'admin_post_apse_statement', array( __CLASS__, 'handle_statement' ) );
		foreach ( array( 'apse_receipt', 'apse_statement' ) as $a ) {
			add_action(
				'admin_post_nopriv_' . $a,
				function () {
					wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
					exit;
				}
			);
		}
	}

	// ---------- Dati ----------

	public static function clean_key( string $key ): string {
		return (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $key );
	}

	/** Codice di una ricevuta a partire da una riga di movimento. */
	public static function key_of( array $tx ): string {
		return ! empty( $tx['receipt_id'] ) ? (string) $tx['receipt_id'] : 'tx' . (int) $tx['id'];
	}

	/** Righe di incasso (non annullate) di una ricevuta, con voce, attività, data dell'evento e beneficiario. */
	public static function rows( string $key ): array {
		$key = self::clean_key( $key );
		if ( '' === $key ) {
			return array();
		}
		$db  = Db::db();
		$sql = 'SELECT t.*, c.name AS category_name, c.kind AS category_kind, a.name AS activity_name, s.session_date, bp.first_name AS b_first, bp.last_name AS b_last '
			. 'FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'LEFT JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id LEFT JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = t.session_id '
			. 'LEFT JOIN ' . Db::t( 'people' ) . " bp ON bp.id = t.person_id WHERE t.type = 'income' AND t.voided_at IS NULL AND ";
		if ( preg_match( '/^tx(\d+)$/', $key, $m ) ) {
			$sql = $db->prepare( $sql . 't.id = %d AND t.receipt_id IS NULL ORDER BY t.id', (int) $m[1] );
		} else {
			$sql = $db->prepare( $sql . 't.receipt_id = %s ORDER BY t.id', $key );
		}
		return $db->get_results( $sql, ARRAY_A ) ?: array();
	}

	/** Chi ha pagato (persona), o null se l'incasso non è intestato a nessuno. */
	public static function payer( array $rows ): ?array {
		foreach ( $rows as $r ) {
			$pid = (int) ( $r['payer_person_id'] ?: $r['person_id'] );
			if ( $pid ) {
				return Plugin::people()->get( $pid );
			}
		}
		return null;
	}

	/** Ricevute di una persona (come chi ha pagato), dalla più recente. @return array[] key, date, cents, what */
	public static function list_for_payer( int $person_id, int $limit = 30 ): array {
		$db   = Db::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT t.id, t.tx_date, t.amount_cents, t.receipt_id, c.name AS category_name FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
				. "WHERE t.type = 'income' AND t.voided_at IS NULL AND COALESCE(t.payer_person_id, t.person_id) = %d ORDER BY t.tx_date DESC, t.id DESC LIMIT 500",
				$person_id
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$k = self::key_of( $r );
			if ( ! isset( $out[ $k ] ) ) {
				if ( count( $out ) >= $limit ) {
					continue;
				}
				$out[ $k ] = array( 'key' => $k, 'date' => $r['tx_date'], 'cents' => 0, 'what' => array() );
			}
			$out[ $k ]['cents'] += (int) $r['amount_cents'];
			$out[ $k ]['what'][ $r['category_name'] ] = true;
		}
		foreach ( $out as &$o ) {
			$o['what'] = implode( ', ', array_keys( $o['what'] ) );
		}
		unset( $o );
		return array_values( $out );
	}

	/** Anni (solari) in cui una persona ha versato qualcosa, dal più recente. @return int[] */
	public static function years_for_payer( int $person_id ): array {
		$db = Db::db();
		return array_map(
			'intval',
			$db->get_col(
				$db->prepare(
					'SELECT DISTINCT YEAR(tx_date) AS y FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND COALESCE(payer_person_id, person_id) = %d ORDER BY y DESC",
					$person_id
				)
			) ?: array()
		);
	}

	// ---------- Permessi e indirizzi ----------

	public static function can_view( string $key ): bool {
		$rows = self::rows( $key );
		if ( ! $rows ) {
			return false;
		}
		if ( current_user_can( Plugin::CAP_OPS ) ) {
			return true;
		}
		$payer = self::payer( $rows );
		return $payer && current_user_can( 'apse_book_for', (int) $payer['id'] );
	}

	public static function can_statement( int $person_id ): bool {
		return $person_id > 0 && ( current_user_can( Plugin::CAP_OPS ) || current_user_can( 'apse_book_for', $person_id ) );
	}

	public static function url( string $key ): string {
		$key = self::clean_key( $key );
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_receipt', 'key' => $key ), admin_url( 'admin-post.php' ) ), 'apse_receipt_' . $key );
	}

	public static function statement_url( int $person_id, int $year ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_statement', 'person' => $person_id, 'year' => $year ), admin_url( 'admin-post.php' ) ), 'apse_statement_' . $person_id . '_' . $year );
	}

	// ---------- Numerazione ----------

	/** Numero progressivo della ricevuta (per anno): si assegna la prima volta e resta. @return array{number:int,year:int} */
	public static function number_for( string $key, string $date ): array {
		$db   = Db::db();
		$tbl  = Db::t( 'receipts' );
		$have = $db->get_row( $db->prepare( "SELECT number, year FROM $tbl WHERE receipt_key = %s", $key ), ARRAY_A );
		if ( $have ) {
			return array( 'number' => (int) $have['number'], 'year' => (int) $have['year'] );
		}
		$year = (int) substr( $date, 0, 4 );
		$lock = (int) $db->get_var( "SELECT GET_LOCK('apse_receipt_number', 5)" );
		try {
			$have = $db->get_row( $db->prepare( "SELECT number, year FROM $tbl WHERE receipt_key = %s", $key ), ARRAY_A );
			if ( $have ) {
				return array( 'number' => (int) $have['number'], 'year' => (int) $have['year'] );
			}
			$next = 1 + (int) $db->get_var( $db->prepare( "SELECT MAX(number) FROM $tbl WHERE year = %d", $year ) );
			if ( ! $db->insert( $tbl, array( 'receipt_key' => $key, 'year' => $year, 'number' => $next, 'issued_at' => Db::now(), 'issued_by' => get_current_user_id() ?: null ) ) ) {
				throw new \RuntimeException( 'Impossibile assegnare il numero della ricevuta.' );
			}
			return array( 'number' => $next, 'year' => $year );
		} finally {
			if ( $lock ) {
				$db->get_var( "SELECT RELEASE_LOCK('apse_receipt_number')" );
			}
		}
	}

	// ---------- Testi ----------

	private static function d( string $ymd ): string {
		return ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' );
	}

	private static function month_label( string $ym ): string {
		return ( self::MONTHS[ (int) substr( $ym, 5, 2 ) ] ?? '' ) . ' ' . substr( $ym, 0, 4 );
	}

	/** Descrizione di una riga di incasso, per chi legge la ricevuta. */
	public static function describe( array $r, ?array $payer ): string {
		switch ( $r['category_kind'] ) {
			case 'membership':
				$what = 'Quota associativa' . ( $r['social_year'] ? ' ' . $r['social_year'] : '' );
				break;
			case 'activity_fee':
				if ( ! empty( $r['session_date'] ) ) {
					$what = 'Contributo evento: ' . $r['activity_name'] . ' (' . self::d( $r['session_date'] ) . ')';
				} elseif ( ! empty( $r['competence_month'] ) ) {
					$what = 'Quota corso: ' . $r['activity_name'] . ' — ' . self::month_label( $r['competence_month'] );
				} else {
					$what = 'Contributo: ' . ( $r['activity_name'] ?: $r['category_name'] );
				}
				break;
			default:
				$what = $r['category_name'] . ( in_array( $r['category_kind'], array( 'donation', 'other_income' ), true ) && '' !== trim( (string) $r['description'] ) ? ' — ' . $r['description'] : '' );
		}
		$ben = (int) $r['person_id'];
		if ( $ben && $payer && $ben !== (int) $payer['id'] && ! empty( $r['b_first'] ) ) {
			$what .= ' — per ' . trim( $r['b_first'] . ' ' . $r['b_last'] );
		}
		return $what;
	}

	private static function president_name(): string {
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				return trim( $b['first_name'] . ' ' . $b['last_name'] );
			}
		}
		return '';
	}

	/** Imponibile e IVA delle righe di una ricevuta, per aliquota (solo le righe con IVA). @return array<int,array{net:int,vat:int}> */
	public static function vat_breakdown( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( null === ( $r['vat_rate'] ?? null ) || '' === $r['vat_rate'] || (int) ( $r['vat_cents'] ?? 0 ) <= 0 ) {
				continue;
			}
			$rate = (int) $r['vat_rate'];
			$out[ $rate ] = $out[ $rate ] ?? array( 'net' => 0, 'vat' => 0 );
			$out[ $rate ]['vat'] += (int) $r['vat_cents'];
			$out[ $rate ]['net'] += (int) $r['amount_cents'] - (int) $r['vat_cents'];
		}
		ksort( $out );
		return $out;
	}

	private static function header( Pdf $pdf ): float {
		$name = (string) Settings::get( 'association_name' );
		$cf   = (string) Settings::get( 'tax_code' );
		$pdf->text( 50, 62, '' !== $name ? $name : 'Associazione', 17, true );
		$vat = Fiscal::vat_applies() ? (string) Settings::get( 'vat_number' ) : '';
		if ( '' !== $cf || '' !== $vat ) {
			$pdf->text( 50, 80, trim( ( '' !== $cf ? 'Codice fiscale: ' . $cf : '' ) . ( '' !== $cf && '' !== $vat ? ' · ' : '' ) . ( '' !== $vat ? 'Partita IVA: ' . $vat : '' ) ), 10 );
		}
		$pdf->line( 50, 92, Pdf::W - 50, 92, 1.2 );
		return 118;
	}

	private static function footer( Pdf $pdf, float $y ): void {
		$foot = (string) Settings::get( 'receipt_footer' );
		if ( '' !== $foot ) {
			foreach ( Pdf::wrap( $foot, Pdf::W - 100, 9 ) as $line ) {
				$pdf->text( 50, $y, $line, 9 );
				$y += 12;
			}
		}
	}

	// ---------- Ricevuta ----------

	/** @return array pdf (byte), filename, number (stringa N/AAAA), payer (persona o null)
	 *  @throws \InvalidArgumentException */
	public static function build( string $key ): array {
		$key  = self::clean_key( $key );
		$rows = self::rows( $key );
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'Ricevuta non trovata (o incasso annullato).' );
		}
		$payer  = self::payer( $rows );
		$date   = (string) $rows[0]['tx_date'];
		$num    = self::number_for( $key, $date );
		$label  = $num['number'] . '/' . $num['year'];
		$kinds  = array_unique( array_column( $rows, 'category_kind' ) );
		$title  = array( 'donation' ) === array_values( $kinds ) ? 'RICEVUTA DI EROGAZIONE LIBERALE' : 'RICEVUTA DI PAGAMENTO';
		Pdf::$filter = array( Texts::class, 'plain' ); // testi personalizzati nel PDF
		$pdf    = new Pdf();
		$y      = self::header( $pdf );
		$pdf->text( 50, $y, $title, 14, true );
		$pdf->text( Pdf::W - 50, $y, 'N. ' . $label, 14, true, 'R' );
		$y += 20;
		$pdf->text( 50, $y, 'Data: ' . self::d( $date ), 11 );
		$y += 30;
		$pdf->text( 50, $y, 'Ricevuto da', 9 );
		$y += 15;
		if ( $payer ) {
			$pdf->text( 50, $y, Plugin::people()->full_name( $payer ), 12, true );
			$y += 15;
			if ( ! empty( $payer['tax_code'] ) ) {
				$pdf->text( 50, $y, 'Codice fiscale: ' . $payer['tax_code'], 10 );
				$y += 14;
			}
		} else {
			$pdf->text( 50, $y, '—', 12 );
			$y += 15;
		}
		$y += 14;
		$pdf->rect( 50, $y - 12, Pdf::W - 100, 20 );
		$pdf->text( 56, $y + 2, 'Descrizione', 10, true );
		$pdf->text( Pdf::W - 56, $y + 2, 'Importo', 10, true, 'R' );
		$y    += 24;
		$total = 0;
		foreach ( $rows as $r ) {
			$cents = (int) $r['amount_cents'];
			$total += $cents;
			$lines = Pdf::wrap( self::describe( $r, $payer ), Pdf::W - 100 - 110, 11 );
			foreach ( $lines as $i => $line ) {
				$pdf->text( 56, $y, $line, 11 );
				if ( 0 === $i ) {
					$pdf->text( Pdf::W - 56, $y, Money::format( $cents ), 11, false, 'R' );
				}
				$y += 15;
			}
			$y += 3;
		}
		$pdf->line( 50, $y - 6, Pdf::W - 50, $y - 6 );
		$y += 8;
		$pdf->text( 56, $y, 'Totale', 12, true );
		$pdf->text( Pdf::W - 56, $y, Money::format( $total ), 12, true, 'R' );
		$y += 18;
		foreach ( self::vat_breakdown( $rows ) as $rate => $b ) { // imponibile e IVA contenuta, per aliquota
			$pdf->text( 56, $y, 'di cui imponibile ' . $rate . '%: ' . Money::format( $b['net'] ) . ' · IVA: ' . Money::format( $b['vat'] ), 10 );
			$y += 14;
		}
		$y += 6;
		$method = (string) $rows[0]['method'];
		$pdf->text( 56, $y, 'Pagato con: ' . ( Labels::methods()[ $method ] ?? $method ), 10 );
		if ( ! empty( $rows[0]['document_ref'] ) ) {
			$y += 14;
			$pdf->text( 56, $y, 'Riferimento: ' . $rows[0]['document_ref'], 10 );
		}
		$y += 60;
		$pdf->text( Pdf::W - 60, $y, 'Per l\'associazione', 10, false, 'R' );
		$pres = self::president_name();
		$y   += 34;
		$pdf->line( Pdf::W - 230, $y, Pdf::W - 60, $y );
		if ( '' !== $pres ) {
			$pdf->text( Pdf::W - 60, $y + 13, 'Il Presidente ' . $pres, 9, false, 'R' );
		}
		self::footer( $pdf, 770 );
		$who = $payer ? Text::normalize( $payer['last_name'] ) : 'ricevuta';
		Pdf::$filter = null;
		return array( 'pdf' => $pdf->output(), 'filename' => 'ricevuta-' . $num['number'] . '-' . $num['year'] . '-' . preg_replace( '/[^a-z0-9]/', '', $who ) . '.pdf', 'number' => $label, 'payer' => $payer );
	}

	// ---------- Attestazione annuale ----------

	/** @return array pdf, filename, total_cents
	 *  @throws \InvalidArgumentException */
	public static function statement( int $person_id, int $year ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$db   = Db::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT t.*, c.name AS category_name, c.kind AS category_kind, a.name AS activity_name, s.session_date, bp.first_name AS b_first, bp.last_name AS b_last '
				. 'FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id LEFT JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id '
				. 'LEFT JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = t.session_id LEFT JOIN ' . Db::t( 'people' ) . ' bp ON bp.id = t.person_id '
				. "WHERE t.type = 'income' AND t.voided_at IS NULL AND COALESCE(t.payer_person_id, t.person_id) = %d AND YEAR(t.tx_date) = %d ORDER BY t.tx_date, t.id",
				$person_id,
				$year
			),
			ARRAY_A
		) ?: array();
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'Nessun versamento nel ' . $year . '.' );
		}
		$groups = array( 'membership' => 'Quote associative', 'activity_fee' => 'Contributi per attività ed eventi', 'donation' => 'Erogazioni liberali' );
		$sums   = array();
		$total  = 0;
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf    = new Pdf();
		$y      = self::header( $pdf );
		$pdf->text( 50, $y, 'ATTESTAZIONE DEI VERSAMENTI — ANNO ' . $year, 14, true );
		$y += 24;
		$pdf->text( 50, $y, 'Si attesta che ' . Plugin::people()->full_name( $person ) . ( ! empty( $person['tax_code'] ) ? ' (C.F. ' . $person['tax_code'] . ')' : '' ) . ' ha versato nel ' . $year . ':', 11 );
		$y += 28;
		$head = function ( Pdf $pdf, float $y ) {
			$pdf->rect( 50, $y - 12, Pdf::W - 100, 20 );
			$pdf->text( 56, $y + 2, 'Data', 10, true );
			$pdf->text( 120, $y + 2, 'Descrizione', 10, true );
			$pdf->text( Pdf::W - 56, $y + 2, 'Importo', 10, true, 'R' );
			return $y + 24;
		};
		$y = $head( $pdf, $y );
		foreach ( $rows as $r ) {
			if ( $y > 740 ) {
				self::footer( $pdf, 800 );
				$pdf->add_page();
				$y = $head( $pdf, 70 );
			}
			$cents = (int) $r['amount_cents'];
			$total += $cents;
			$g = $groups[ $r['category_kind'] ] ?? 'Altri versamenti';
			$sums[ $g ] = ( $sums[ $g ] ?? 0 ) + $cents;
			$lines = Pdf::wrap( self::describe( $r, $person ), Pdf::W - 120 - 110, 10 );
			foreach ( $lines as $i => $line ) {
				if ( 0 === $i ) {
					$pdf->text( 56, $y, self::d( $r['tx_date'] ), 10 );
					$pdf->text( Pdf::W - 56, $y, Money::format( $cents ), 10, false, 'R' );
				}
				$pdf->text( 120, $y, $line, 10 );
				$y += 13;
			}
			$y += 2;
		}
		if ( $y > 640 ) {
			self::footer( $pdf, 800 );
			$pdf->add_page();
			$y = 80;
		}
		$pdf->line( 50, $y - 4, Pdf::W - 50, $y - 4 );
		$y += 14;
		foreach ( $sums as $g => $c ) {
			$pdf->text( 56, $y, $g, 11 );
			$pdf->text( Pdf::W - 56, $y, Money::format( $c ), 11, false, 'R' );
			$y += 16;
		}
		$pdf->text( 56, $y + 4, 'Totale versato nel ' . $year, 12, true );
		$pdf->text( Pdf::W - 56, $y + 4, Money::format( $total ), 12, true, 'R' );
		$y += 50;
		$pdf->text( 50, $y, 'Emessa il ' . self::d( current_time( 'Y-m-d' ) ), 10 );
		$pres = self::president_name();
		$pdf->text( Pdf::W - 60, $y, 'Per l\'associazione', 10, false, 'R' );
		$pdf->line( Pdf::W - 230, $y + 34, Pdf::W - 60, $y + 34 );
		if ( '' !== $pres ) {
			$pdf->text( Pdf::W - 60, $y + 47, 'Il Presidente ' . $pres, 9, false, 'R' );
		}
		self::footer( $pdf, 800 );
		Pdf::$filter = null;
		return array( 'pdf' => $pdf->output(), 'filename' => 'attestazione-' . $year . '-' . preg_replace( '/[^a-z0-9]/', '', Text::normalize( $person['last_name'] ) ) . '.pdf', 'total_cents' => $total );
	}

	// ---------- Invio per email ----------

	/** Manda la ricevuta in PDF all'email di chi ha pagato. @throws \InvalidArgumentException */
	public static function email( string $key ): void {
		$r     = self::build( $key );
		$payer = $r['payer'];
		$to    = $payer && ! empty( $payer['email'] ) ? (string) $payer['email'] : '';
		if ( '' === $to && $payer && ! empty( $payer['host_person_id'] ) ) {
			$host = Plugin::people()->get( (int) $payer['host_person_id'] );
			$to   = $host && ! empty( $host['email'] ) ? (string) $host['email'] : '';
		}
		if ( '' === $to || ! is_email( $to ) ) {
			throw new \InvalidArgumentException( 'Chi ha pagato non ha un indirizzo email (né il socio che lo ospita).' );
		}
		$tmp = wp_tempnam( 'apse-ricevuta' );
		$dst = dirname( $tmp ) . '/' . $r['filename'];
		file_put_contents( $dst, $r['pdf'] );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$assoc = (string) Settings::get( 'association_name' );
		$ok    = \ApSemplice\Texts::mail(
			$to,
			'Ricevuta n. ' . $r['number'] . ( '' !== $assoc ? ' — ' . $assoc : '' ),
			'Ciao ' . ( $payer ? $payer['first_name'] : '' ) . ",\n\nin allegato la ricevuta n. " . $r['number'] . " del tuo pagamento.\n\nGrazie!",
			array(),
			array( $dst )
		);
		@unlink( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Invio non riuscito: controlla la posta in uscita del sito.' );
		}
		Audit::log( 'receipt.emailed', 'receipt', 0, array( 'number' => $r['number'] ) );
	}

	// ---------- Download ----------

	private static function send_pdf( string $bytes, string $filename ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- file binario
		exit;
	}

	public static function handle_receipt(): void {
		$key = isset( $_GET['key'] ) ? self::clean_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'apse_receipt_' . $key );
		if ( ! self::can_view( $key ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$r = self::build( $key );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send_pdf( $r['pdf'], $r['filename'] );
	}

	public static function handle_statement(): void {
		$pid  = isset( $_GET['person'] ) ? (int) $_GET['person'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$year = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'apse_statement_' . $pid . '_' . $year );
		if ( ! self::can_statement( $pid ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$r = self::statement( $pid, $year );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send_pdf( $r['pdf'], $r['filename'] );
	}
}
