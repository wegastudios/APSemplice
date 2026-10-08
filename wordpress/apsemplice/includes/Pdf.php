<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Generatore minimo di PDF (A4, testo e linee, font Helvetica standard): serve per ricevute e attestazioni, senza librerie esterne.
 * Le coordinate sono in punti (1/72 di pollice) misurati dall'angolo in ALTO a sinistra della pagina.
 */
final class Pdf {

	const W = 595.0;
	const H = 842.0;

	/** Larghezze dei caratteri ASCII 32..126 di Helvetica (millesimi di corpo). */
	const WIDTHS = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	/** @var string[] contenuto di ogni pagina */
	private $pages = array();
	/** @var string */
	private $cur = '';

	public function __construct() {
		$this->add_page();
	}

	public function add_page(): void {
		if ( '' !== $this->cur || $this->pages ) {
			$this->pages[] = $this->cur;
		}
		$this->cur = '';
	}

	/** Testo in UTF-8 => byte Windows-1252 (accenti ed €), con le parentesi e la barra protette. */
	public static function encode( string $s ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$e = (string) @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		} elseif ( function_exists( 'iconv' ) ) {
			$e = (string) @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
		} else {
			$e = (string) preg_replace( '/[^\x20-\x7E]/', '?', $s );
		}
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $e );
	}

	/** Larghezza approssimata di un testo, in punti (per allineare a destra o al centro). */
	public static function width( string $s, float $size, bool $bold = false ): float {
		$w = 0;
		$n = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch = function_exists( 'mb_substr' ) ? mb_substr( $s, $i, 1, 'UTF-8' ) : $s[ $i ];
			$o  = 1 === strlen( $ch ) ? ord( $ch ) : 0;
			$w += ( $o >= 32 && $o <= 126 ) ? self::WIDTHS[ $o - 32 ] : 556;
		}
		return $w * $size / 1000 * ( $bold ? 1.06 : 1.0 );
	}

	/**
	 * @param string $align L (sinistra), R (destra: $x è il bordo destro) o C (centro: $x è il centro)
	 */
	/**  callable|null funzione che cambia ogni testo prima di scriverlo (testi personalizzati) */
	public static $filter = null;

	public function text( float $x, float $y, string $s, float $size = 11, bool $bold = false, string $align = 'L' ): void {
		if ( null !== self::$filter ) {
			$s = (string) call_user_func( self::$filter, $s );
		}
		if ( '' === $s ) {
			return;
		}
		if ( 'R' === $align ) {
			$x -= self::width( $s, $size, $bold );
		} elseif ( 'C' === $align ) {
			$x -= self::width( $s, $size, $bold ) / 2;
		}
		$this->cur .= sprintf( "BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 2 : 1, $size, $x, self::H - $y, self::encode( $s ) );
	}

	/** Spezza un testo lungo in righe che stanno in $width punti. @return string[] */
	public static function wrap( string $s, float $width, float $size = 11, bool $bold = false ): array {
		$lines = array();
		$line  = '';
		foreach ( preg_split( '/\s+/u', trim( $s ) ) ?: array() as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && self::width( $try, $size, $bold ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines ?: array( '' );
	}

	public function line( float $x1, float $y1, float $x2, float $y2, float $width = 0.6 ): void {
		$this->cur .= sprintf( "%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, self::H - $y1, $x2, self::H - $y2 );
	}

	/** Rettangolo pieno in un tono di grigio (0 nero … 1 bianco). */
	public function rect( float $x, float $y, float $w, float $h, float $gray = 0.93 ): void {
		$this->cur .= sprintf( "%.2F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, self::H - $y - $h, $w, $h );
	}

	/** Il documento completo (byte del file PDF). */
	public function output(): string {
		$pages = $this->pages;
		$pages[] = $this->cur;
		$n     = count( $pages );
		$objs  = array();
		// 1 catalogo, 2 elenco pagine, 3 e 4 font, poi per ogni pagina: oggetto pagina e contenuto
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids    = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 5 + $i * 2 ) . ' 0 R';
		}
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		foreach ( $pages as $i => $content ) {
			$pg          = 5 + $i * 2;
			$objs[ $pg ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( $pg + 1 ) . ' 0 R >>';
			$objs[ $pg + 1 ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . 'endstream';
		}
		$out     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		return $out . 'trailer << /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
	}
}
