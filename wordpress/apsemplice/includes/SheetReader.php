<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Apre i file di import (Excel .xlsx o CSV) e li porta tutti alla stessa forma: fogli di righe di testo.
 * Capisce da solo a cosa serve ogni foglio (soci/ospiti oppure prima nota) guardando le intestazioni.
 */
final class SheetReader {

	const ROLE_PEOPLE = 'people';
	const ROLE_LEDGER = 'ledger';
	const SCAN_ROWS   = 12; // l'intestazione può non essere alla prima riga (titoli sopra la tabella)

	/**
	 * @return array[] [ ['name','rows','lines'], ... ]
	 * @throws \InvalidArgumentException
	 */
	public static function read( string $path, string $original_name ): array {
		$head = (string) file_get_contents( $path, false, null, 0, 8 );
		$ext  = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( 0 === strpos( $head, "\xD0\xCF\x11\xE0" ) || 'xls' === $ext ) {
			throw new \InvalidArgumentException( 'Il vecchio formato Excel (.xls) non è supportato: in Excel scegli File → Salva con nome → "Cartella di lavoro di Excel (.xlsx)" oppure CSV.' );
		}
		if ( in_array( $ext, array( 'ods', 'numbers' ), true ) ) {
			throw new \InvalidArgumentException( 'Salva il foglio come Excel (.xlsx) o CSV e ricaricalo.' );
		}
		if ( 0 === strpos( $head, 'PK' ) || 'xlsx' === $ext ) { // un .xlsx è uno zip
			return Xlsx::read( $path );
		}
		$table = PeopleCsv::read_table( PeopleCsv::decode( (string) file_get_contents( $path ) ) );
		if ( ! $table ) {
			throw new \InvalidArgumentException( 'Il file è vuoto.' );
		}
		return array( array( 'name' => 'CSV', 'rows' => $table, 'lines' => range( 1, count( $table ) ) ) );
	}

	/** Intestazioni normalizzate (minuscole, senza accenti né spazi). */
	public static function normalize_header( array $cells ): array {
		return array_map( array( Text::class, 'normalize' ), array_map( 'strval', $cells ) );
	}

	/** Indice della prima colonna il cui nome è tra quelli dati (-1 se manca). */
	public static function col( array $header, array $names ): int {
		foreach ( $header as $i => $h ) {
			if ( in_array( $h, $names, true ) ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/**
	 * Cerca la riga di intestazione tra le prime righe.
	 *
	 * @param callable $accept function( array $normalized_header ): bool
	 * @return int|null indice della riga
	 */
	public static function find_header( array $rows, callable $accept ): ?int {
		foreach ( array_slice( $rows, 0, self::SCAN_ROWS, true ) as $i => $cells ) {
			if ( $accept( self::normalize_header( $cells ) ) ) {
				return (int) $i;
			}
		}
		return null;
	}

	/** Di cosa si tratta: soci/ospiti, prima nota o altro (null). */
	public static function role( array $sheet ): ?string {
		$people = null !== self::find_header( $sheet['rows'], array( PeopleCsv::class, 'accepts_header' ) );
		$ledger = null !== self::find_header( $sheet['rows'], array( LedgerImport::class, 'accepts_header' ) );
		if ( $people && $ledger ) {
			$n = Text::normalize( (string) ( $sheet['name'] ?? '' ) );
			return false !== strpos( $n, 'prima' ) || false !== strpos( $n, 'moviment' ) || false !== strpos( $n, 'cassa' ) ? self::ROLE_LEDGER : self::ROLE_PEOPLE;
		}
		if ( $people ) {
			return self::ROLE_PEOPLE;
		}
		return $ledger ? self::ROLE_LEDGER : null;
	}
}
