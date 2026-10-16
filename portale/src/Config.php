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

	/** Piano di vendita e prezzo (periodo `month` | `year`); null se non esiste o non è in vendita. */
	public static function price( string $plan, string $period ): ?array {
		$p = self::get( 'plans.' . $plan . '.prices.' . $period );
		return is_array( $p ) ? $p : null;
	}
}
