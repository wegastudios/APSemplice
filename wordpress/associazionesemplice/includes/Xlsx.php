<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Lettura di file Excel (.xlsx) senza librerie esterne: serve solo l'estensione zip di PHP.
 * {@see Xlsx::parse()} lavora sul contenuto già estratto (pura, testata); {@see Xlsx::read()} apre il file.
 *
 * Le celle diventano testo: i numeri restano numeri ("12.5"), le date (celle con formato data) diventano "AAAA-MM-GG".
 * Si leggono solo i valori: le formule non vengono calcolate (si usa il risultato che Excel ha salvato).
 */
final class Xlsx {

	const MAX_ROWS       = 20000;
	const MAX_ENTRY_SIZE = 52428800; // 50 MB di XML decompresso per ogni parte (difesa dai file "bomba")
	const NS_MAIN        = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL         = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** Formati data predefiniti di Excel. */
	const BUILTIN_DATE_FORMATS = array( 14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58 );

	/**
	 * @return array[] fogli visibili: [ ['name'=>string, 'rows'=>string[][], 'lines'=>int[]], ... ] ('lines' = numero di riga in Excel)
	 * @throws \InvalidArgumentException
	 */
	public static function read( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può leggere i file Excel (manca l\'estensione zip di PHP): salva il foglio come CSV.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \InvalidArgumentException( 'Il file non è un Excel (.xlsx) valido.' );
		}
		$files = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st   = $zip->statIndex( $i );
			$name = (string) $st['name'];
			if ( ! preg_match( '#^xl/(workbook\.xml|_rels/workbook\.xml\.rels|sharedStrings\.xml|styles\.xml|worksheets/[^/]+\.xml)$#', $name ) ) {
				continue;
			}
			if ( (int) $st['size'] > self::MAX_ENTRY_SIZE ) {
				$zip->close();
				throw new \InvalidArgumentException( 'Il file Excel è troppo grande: dividilo in più file o salvalo come CSV.' );
			}
			$files[ $name ] = (string) $zip->getFromIndex( $i );
		}
		$zip->close();
		return self::parse( $files );
	}

	/**
	 * @param array<string,string> $files contenuto delle parti del file (chiave = percorso nello zip, es. "xl/workbook.xml")
	 * @throws \InvalidArgumentException
	 */
	public static function parse( array $files ): array {
		if ( empty( $files['xl/workbook.xml'] ) ) {
			throw new \InvalidArgumentException( 'Il file non è un Excel (.xlsx) valido.' );
		}
		$wb      = self::xml( $files['xl/workbook.xml'] );
		$rels    = isset( $files['xl/_rels/workbook.xml.rels'] ) ? self::xml( $files['xl/_rels/workbook.xml.rels'] ) : null;
		$targets = array();
		if ( $rels ) {
			foreach ( $rels->Relationship as $r ) {
				$t                         = (string) $r['Target'];
				$targets[ (string) $r['Id'] ] = ltrim( 0 === strpos( $t, '/' ) ? substr( $t, 1 ) : 'xl/' . $t, '/' );
			}
		}
		$date1904 = false;
		if ( isset( $wb->workbookPr ) && in_array( strtolower( (string) $wb->workbookPr['date1904'] ), array( '1', 'true' ), true ) ) {
			$date1904 = true;
		}
		$shared = isset( $files['xl/sharedStrings.xml'] ) ? self::shared_strings( $files['xl/sharedStrings.xml'] ) : array();
		$dates  = isset( $files['xl/styles.xml'] ) ? self::date_styles( $files['xl/styles.xml'] ) : array();

		$sheets = array();
		if ( isset( $wb->sheets ) ) {
			foreach ( $wb->sheets->sheet as $s ) {
				$state = (string) $s['state'];
				if ( in_array( $state, array( 'hidden', 'veryHidden' ), true ) ) {
					continue;
				}
				$rid  = (string) $s->attributes( self::NS_REL )['id'];
				$path = $targets[ $rid ] ?? '';
				if ( '' === $path || empty( $files[ $path ] ) ) {
					continue;
				}
				$sheets[] = array_merge( array( 'name' => (string) $s['name'] ), self::sheet( $files[ $path ], $shared, $dates, $date1904 ) );
			}
		}
		if ( ! $sheets ) {
			throw new \InvalidArgumentException( 'Nel file Excel non ci sono fogli leggibili.' );
		}
		return $sheets;
	}

	private static function xml( string $xml ): \SimpleXMLElement {
		// Un XML in UTF-16 (byte nulli) potrebbe nascondere un DOCTYPE al controllo qui sotto: gli .xlsx di Excel sono sempre UTF-8.
		if ( false !== strpos( $xml, "\0" ) || false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			throw new \InvalidArgumentException( 'Il file Excel contiene dati non ammessi.' );
		}
		// Si lavora con nomi senza namespace (più semplice e robusto): via la dichiarazione di default e l'eventuale prefisso del namespace principale.
		$xml = (string) preg_replace( '/\sxmlns="[^"]*"/', '', $xml );
		if ( preg_match( '/xmlns:([A-Za-z_][\w.\-]*)="' . preg_quote( self::NS_MAIN, '/' ) . '"/', $xml, $m ) ) {
			$p   = preg_quote( $m[1], '/' );
			$xml = (string) preg_replace( array( '/<(\/?)' . $p . ':/', '/\sxmlns:' . $p . '="[^"]*"/' ), array( '<$1', '' ), $xml );
		}
		$prev = libxml_use_internal_errors( true );
		$x    = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $x ) {
			throw new \InvalidArgumentException( 'Il file Excel è danneggiato.' );
		}
		return $x;
	}

	private static function shared_strings( string $xml ): array {
		$x   = self::xml( $xml );
		$out = array();
		foreach ( $x->si as $si ) {
			$t = '';
			if ( isset( $si->t ) ) {
				$t = (string) $si->t;
			}
			foreach ( $si->r as $run ) { // testo con formattazione parziale
				$t .= (string) $run->t;
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Indici di stile (cellXfs) che rappresentano una data. @return array<int,bool> */
	private static function date_styles( string $xml ): array {
		$x       = self::xml( $xml );
		$custom  = array();
		if ( isset( $x->numFmts ) ) {
			foreach ( $x->numFmts->numFmt as $f ) {
				$custom[ (int) $f['numFmtId'] ] = (string) $f['formatCode'];
			}
		}
		$out = array();
		$i   = 0;
		if ( isset( $x->cellXfs ) ) {
			foreach ( $x->cellXfs->xf as $xf ) {
				$id        = (int) $xf['numFmtId'];
				$out[ $i ] = in_array( $id, self::BUILTIN_DATE_FORMATS, true ) || ( isset( $custom[ $id ] ) && self::is_date_format( $custom[ $id ] ) );
				$i++;
			}
		}
		return $out;
	}

	public static function is_date_format( string $code ): bool {
		$code = preg_replace( '/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./', '', $code ) ?? '';
		return (bool) preg_match( '/[dmyhs]/i', $code ) && ! preg_match( '/^(General|@|0|#)/i', $code );
	}

	/** Numero seriale di Excel => "AAAA-MM-GG" (null se fuori intervallo). */
	public static function serial_to_date( float $serial, bool $date1904 = false ): ?string {
		if ( $serial < 1 || $serial > 2958465 ) {
			return null;
		}
		$days = (int) floor( $serial );
		if ( $date1904 ) {
			$base = new \DateTimeImmutable( '1904-01-01', new \DateTimeZone( 'UTC' ) );
			return $base->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		}
		if ( $days < 60 ) { // prima del finto 29/02/1900 di Excel
			$days += 1;
		}
		return ( new \DateTimeImmutable( '1899-12-30', new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/** Indice di colonna (0 = A) da un riferimento come "BC12". */
	public static function column_index( string $ref ): int {
		$n = 0;
		for ( $i = 0, $l = strlen( $ref ); $i < $l; $i++ ) {
			$c = ord( $ref[ $i ] );
			if ( $c < 65 || $c > 90 ) {
				break;
			}
			$n = $n * 26 + ( $c - 64 );
		}
		return max( 0, $n - 1 );
	}

	private static function number_text( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^-?\d+(\.\d+)?$/', $raw ) ) {
			return $raw;
		}
		if ( is_numeric( $raw ) ) { // notazione scientifica
			return rtrim( rtrim( sprintf( '%.10F', (float) $raw ), '0' ), '.' );
		}
		return $raw;
	}

	private static function sheet( string $xml, array $shared, array $dates, bool $date1904 ): array {
		$x     = self::xml( $xml );
		$rows  = array();
		$lines = array();
		if ( ! isset( $x->sheetData ) ) {
			return array( 'rows' => array(), 'lines' => array() );
		}
		$seq = 0;
		foreach ( $x->sheetData->row as $row ) {
			$seq++;
			$line = (int) $row['r'] ?: $seq;
			$cells = array();
			$pos   = 0;
			foreach ( $row->c as $c ) {
				$idx = isset( $c['r'] ) ? self::column_index( (string) $c['r'] ) : $pos;
				$pos = $idx + 1;
				$t   = (string) $c['t'];
				$v   = isset( $c->v ) ? (string) $c->v : '';
				if ( 's' === $t ) {
					$val = $shared[ (int) $v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$val = isset( $c->is->t ) ? (string) $c->is->t : '';
					foreach ( $c->is->r as $run ) {
						$val .= (string) $run->t;
					}
				} elseif ( 'str' === $t ) {
					$val = $v;
				} elseif ( 'b' === $t ) {
					$val = '1' === $v ? '1' : '0';
				} elseif ( 'e' === $t || '' === $v ) {
					$val = '';
				} elseif ( ! empty( $dates[ (int) $c['s'] ] ) && is_numeric( $v ) ) {
					$val = self::serial_to_date( (float) $v, $date1904 ) ?? $v;
				} else {
					$val = self::number_text( $v );
				}
				$cells[ $idx ] = $val;
			}
			if ( ! $cells ) {
				continue;
			}
			$max = max( array_keys( $cells ) );
			$out = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$out[] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			$empty = true;
			foreach ( $out as $cell ) {
				if ( '' !== trim( $cell ) ) {
					$empty = false;
					break;
				}
			}
			if ( $empty ) {
				continue;
			}
			if ( count( $rows ) >= self::MAX_ROWS ) {
				throw new \InvalidArgumentException( 'Il foglio ha più di ' . self::MAX_ROWS . ' righe: dividilo in più file.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$rows[]  = $out;
			$lines[] = $line;
		}
		return array( 'rows' => $rows, 'lines' => $lines );
	}
}
