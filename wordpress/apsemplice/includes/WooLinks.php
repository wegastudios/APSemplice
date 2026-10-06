<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Collegamenti tra le voci da pagare e i prodotti del negozio WooCommerce:
 *  - la quota associativa di ogni livello di socio;
 *  - i contributi di un corso o di un evento.
 * Per le voci senza un prodotto proprio si può indicare un prodotto generico.
 */
final class WooLinks {

	const LEVEL    = 'level';
	const ACTIVITY = 'activity';

	private static function db(): \wpdb {
		return Db::db();
	}

	/** @return array<int,array> righe di collegamento */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'woo_links' ) . ' ORDER BY kind, ref_id', ARRAY_A ) ?: array();
	}

	/** @return array<string,int> "level:3" => id prodotto */
	public static function map(): array {
		$out = array();
		foreach ( self::all() as $r ) {
			$out[ $r['kind'] . ':' . (int) $r['ref_id'] ] = (int) $r['product_id'];
		}
		return $out;
	}

	public static function product_id( string $kind, int $ref_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT product_id FROM ' . Db::t( 'woo_links' ) . ' WHERE kind = %s AND ref_id = %d', $kind, $ref_id ) );
	}

	/**
	 * Collega (o scollega, con prodotto 0) una voce a un prodotto. @throws \InvalidArgumentException
	 */
	public static function set( string $kind, int $ref_id, int $product_id ): void {
		if ( ! in_array( $kind, array( self::LEVEL, self::ACTIVITY ), true ) ) {
			throw new \InvalidArgumentException( 'Tipo di collegamento non valido.' );
		}
		$tbl = Db::t( 'woo_links' );
		if ( $product_id <= 0 ) {
			self::db()->delete( $tbl, array( 'kind' => $kind, 'ref_id' => $ref_id ) );
			return;
		}
		if ( self::LEVEL === $kind && ! Levels::get( $ref_id ) ) {
			throw new \InvalidArgumentException( 'Livello non trovato.' );
		}
		if ( self::LEVEL !== $kind && ! Plugin::activities()->get( $ref_id ) ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		WooBridge::assert_product( $product_id );
		$exists = self::product_id( $kind, $ref_id );
		if ( $exists ) {
			self::db()->update( $tbl, array( 'product_id' => $product_id ), array( 'kind' => $kind, 'ref_id' => $ref_id ) );
		} else {
			self::db()->insert( $tbl, array( 'kind' => $kind, 'ref_id' => $ref_id, 'product_id' => $product_id, 'created_at' => Db::now() ) );
		}
	}

	/** Livello che vale per un socio: quello scelto o, se non ne ha uno, il primo livello attivo della sua base. */
	public static function level_of( array $person ): int {
		if ( ! empty( $person['level_id'] ) ) {
			$lv = Levels::get( (int) $person['level_id'] );
			if ( $lv && $lv['base_type'] === $person['type'] ) {
				return (int) $lv['id'];
			}
		}
		foreach ( Levels::all( true ) as $lv ) {
			if ( $lv['base_type'] === $person['type'] ) {
				return (int) $lv['id'];
			}
		}
		return 0;
	}

	/** Prodotto da usare per una voce da pagare (0 se non c'è nemmeno quello generico). */
	public static function product_for_item( array $item ): int {
		$pid = 0;
		if ( PaymentItems::MEMBERSHIP === $item['type'] ) {
			$person = Plugin::people()->get( (int) $item['person_id'] );
			$lv     = $person ? self::level_of( $person ) : 0;
			$pid    = $lv ? self::product_id( self::LEVEL, $lv ) : 0;
		} elseif ( ! empty( $item['activity_id'] ) ) {
			$pid = self::product_id( self::ACTIVITY, (int) $item['activity_id'] );
		}
		return $pid ?: (int) Settings::get( 'woo_default_product' );
	}
}
