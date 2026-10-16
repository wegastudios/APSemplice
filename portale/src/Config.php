<?php
namespace Portale;

/** Configurazione (da config.php) con accesso a percorso puntato: Config::get('stripe.secret_key'). */
final class Config {

	/** @var array */
	private static $data = array();

	public static function load( array $data ): void {
		self::$data = $data;
	}

	/** @param mixed $default */
	public static function get( string $path, $default = null ) {
		$v = self::$data;
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $v ) || ! array_key_exists( $k, $v ) ) {
				return $default;
			}
			$v = $v[ $k ];
		}
		return $v;
	}

	/** Sostituisce un valore a percorso puntato (usato per applicare le impostazioni salvate dal pannello). @param mixed $value */
	public static function set( string $path, $value ): void {
		$keys = explode( '.', $path );
		$ref  = &self::$data;
		foreach ( $keys as $k ) {
			if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
				$ref[ $k ] = array();
			}
			$ref = &$ref[ $k ];
		}
		$ref = $value;
	}

	/** Piano di vendita e prezzo (periodo `month` | `year`); null se non esiste o non è in vendita. */
	public static function price( string $plan, string $period ): ?array {
		if ( false === self::get( 'plans.' . $plan . '.visible', true ) ) {
			return null;
		}
		$p = self::get( 'plans.' . $plan . '.prices.' . $period );
		return is_array( $p ) ? $p : null;
	}
}
