<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class Db {

	public static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Nome completo di una tabella del plugin: Db::t('people') => wp_asem_people */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'asem_' . $name;
	}

	/** Data di oggi (Y-m-d) nel fuso orario del sito. */
	public static function today(): string {
		return current_time( 'Y-m-d' );
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}

	/**
	 * Esegue $fn tenendo un blocco con nome (GET_LOCK): due richieste insieme non possono fare la stessa operazione.
	 * Se il blocco non si ottiene entro $wait secondi l'operazione non parte (si riprova); se il database non supporta i blocchi, si procede senza.
	 *
	 * @return mixed il risultato di $fn
	 * @throws \InvalidArgumentException
	 */
	public static function with_lock( string $name, callable $fn, int $wait = 5 ) {
		$db  = self::db();
		$got = $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		if ( '0' === (string) $got ) {
			throw new \InvalidArgumentException( 'Un\'altra operazione è in corso: riprova tra qualche secondo.' );
		}
		try {
			return $fn();
		} finally {
			if ( null !== $got ) {
				$db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}
}
