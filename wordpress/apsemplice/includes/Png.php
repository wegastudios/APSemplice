<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Immagini PNG tinta unita (per le icone obbligatorie delle tessere nei wallet), senza GD. */
final class Png {

	public static function solid( int $w, int $h, int $r, int $g, int $b ): string {
		$row = "\0" . str_repeat( chr( $r ) . chr( $g ) . chr( $b ), $w );
		$raw = str_repeat( $row, $h );
		return "\x89PNG\r\n\x1a\n"
			. self::chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 ) )
			. self::chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. self::chunk( 'IEND', '' );
	}

	private static function chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) & 0xFFFFFFFF );
	}

	/** "#RRGGBB" => [r, g, b] (blu predefinito se non valido). */
	public static function rgb( string $hex ): array {
		if ( ! preg_match( '/^#?([0-9a-fA-F]{6})$/', trim( $hex ), $m ) ) {
			return array( 34, 113, 177 );
		}
		return array( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) );
	}

	/** True se il colore è chiaro (serve testo scuro). */
	public static function is_light( array $rgb ): bool {
		return ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) > 160;
	}
}
