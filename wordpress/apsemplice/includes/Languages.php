<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Lingue: i testi del plugin sono scritti in italiano; un pacchetto di traduzione è una tabella «testo italiano → traduzione»
 * applicata a tutto ciò che il plugin mostra (pagine, email, PDF) prima delle personalizzazioni, che hanno sempre la precedenza.
 * I pacchetti inclusi stanno in `languages/*.json`; se ne possono caricare altri (CSV, Excel o JSON) dalla pagina dei testi.
 */
final class Languages {

	const DEFAULT_CODE = 'it';
	const OPT          = 'apse_lang_packs';
	const MAX_PACKS    = 8;
	const MAX_STRINGS  = 6000;

	/** @var array|null pacchetti inclusi: codice => [name, strings] */
	private static $bundled = null;

	public static function valid_code( string $code ): bool {
		return (bool) preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $code );
	}

	/** Pacchetti inclusi nel plugin. @return array<string,array{name:string,strings:array}> */
	public static function bundled(): array {
		if ( null !== self::$bundled ) {
			return self::$bundled;
		}
		self::$bundled = array();
		foreach ( glob( rtrim( APSE_DIR, '/\\' ) . '/languages/*.json' ) ?: array() as $file ) {
			$j = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $j ) && isset( $j['code'], $j['strings'] ) && is_array( $j['strings'] ) && self::valid_code( (string) $j['code'] ) && self::DEFAULT_CODE !== $j['code'] ) {
				self::$bundled[ (string) $j['code'] ] = array( 'name' => (string) ( $j['name'] ?? $j['code'] ), 'strings' => self::clean( $j['strings'] ) );
			}
		}
		return self::$bundled;
	}

	/** Pacchetti caricati dall'amministratore. @return array<string,array{name:string,strings:array}> */
	public static function uploaded(): array {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Lingue scelte: codice => nome (l'italiano c'è sempre). @return array<string,string> */
	public static function available(): array {
		$out = array( self::DEFAULT_CODE => 'Italiano' );
		foreach ( self::bundled() as $c => $p ) {
			$out[ $c ] = $p['name'];
		}
		foreach ( self::uploaded() as $c => $p ) {
			$out[ $c ] = (string) $p['name'];
		}
		return $out;
	}

	public static function current(): string {
		$c = (string) Settings::get( 'language' );
		return isset( self::available()[ $c ] ) ? $c : self::DEFAULT_CODE;
	}

	/** Traduzioni di una lingua (vuoto per l'italiano). Un pacchetto caricato ha la precedenza su quello incluso. @return array<string,string> */
	public static function strings( string $code ): array {
		if ( self::DEFAULT_CODE === $code ) {
			return array();
		}
		$up = self::uploaded();
		if ( isset( $up[ $code ]['strings'] ) ) {
			return array_merge( self::bundled()[ $code ]['strings'] ?? array(), $up[ $code ]['strings'] );
		}
		return self::bundled()[ $code ]['strings'] ?? array();
	}

	/** Traduzioni della lingua in uso. */
	public static function map(): array {
		return self::strings( self::current() );
	}

	private static function clean( array $pairs ): array {
		$out = array();
		foreach ( $pairs as $o => $t ) {
			$o = trim( (string) $o );
			$t = trim( (string) $t );
			if ( '' === $t || $t === $o || strlen( $o ) < Texts::MIN_LEN || strlen( $o ) > Texts::MAX_LEN || strlen( $t ) > Texts::MAX_LEN ) {
				continue;
			}
			$out[ $o ] = $t;
		}
		return $out;
	}

	/**
	 * Salva (o sostituisce) un pacchetto caricato. @throws \InvalidArgumentException
	 *
	 * @param array<string,string> $pairs testo italiano => traduzione
	 */
	public static function save_pack( string $code, string $name, array $pairs ): int {
		$code = trim( $code );
		if ( ! self::valid_code( $code ) || self::DEFAULT_CODE === $code ) {
			throw new \InvalidArgumentException( 'Il codice della lingua non è valido (ad esempio «en», «fr», «de», «es»).' );
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Indica il nome della lingua (ad esempio «Français»).' );
		}
		$strings = self::clean( $pairs );
		if ( ! $strings ) {
			throw new \InvalidArgumentException( 'Il file non contiene traduzioni.' );
		}
		if ( count( $strings ) > self::MAX_STRINGS ) {
			throw new \InvalidArgumentException( 'Troppe righe (al massimo ' . self::MAX_STRINGS . ').' );
		}
		$all = self::uploaded();
		if ( ! isset( $all[ $code ] ) && count( $all ) >= self::MAX_PACKS ) {
			throw new \InvalidArgumentException( 'Hai già caricato ' . self::MAX_PACKS . ' lingue: eliminane una.' );
		}
		$all[ $code ] = array( 'name' => mb_substr( $name, 0, 60 ), 'strings' => $strings );
		update_option( self::OPT, $all, false );
		Texts::flush();
		Audit::log( 'language.pack', 'settings', 0, array( 'code' => $code, 'strings' => count( $strings ) ) );
		return count( $strings );
	}

	/** Righe di un foglio (colonne «Originale» e «Traduzione») => coppie. @throws \InvalidArgumentException */
	public static function pairs_from_rows( array $rows ): array {
		$h = SheetReader::find_header(
			$rows,
			function ( $header ) {
				return SheetReader::col( $header, array( 'originale', 'testooriginale', 'italiano' ) ) >= 0 && SheetReader::col( $header, array( 'traduzione', 'translation', 'personalizzato', 'testopersonalizzato' ) ) >= 0;
			}
		);
		if ( null === $h ) {
			throw new \InvalidArgumentException( 'Il file non ha le colonne «Originale» e «Traduzione»: parti dal file dei testi esportato dalla pagina «Testi personalizzati».' );
		}
		$head = SheetReader::normalize_header( $rows[ $h ] );
		$co   = SheetReader::col( $head, array( 'originale', 'testooriginale', 'italiano' ) );
		$ct   = SheetReader::col( $head, array( 'traduzione', 'translation', 'personalizzato', 'testopersonalizzato' ) );
		$out  = array();
		foreach ( $rows as $i => $cells ) {
			if ( $i <= $h ) {
				continue;
			}
			$o = trim( (string) ( $cells[ $co ] ?? '' ) );
			$t = trim( (string) ( $cells[ $ct ] ?? '' ) );
			if ( '' !== $o && '' !== $t ) {
				$out[ $o ] = $t;
			}
		}
		return $out;
	}

	public static function delete_pack( string $code ): void {
		$all = self::uploaded();
		if ( ! isset( $all[ $code ] ) ) {
			throw new \InvalidArgumentException( 'Lingua non trovata tra quelle caricate.' );
		}
		unset( $all[ $code ] );
		update_option( self::OPT, $all, false );
		Texts::flush();
		Audit::log( 'language.deleted', 'settings', 0, array( 'code' => $code ) );
	}
}
