<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Db {

	public static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Nome completo di una tabella del plugin: Db::t('people') => wp_aps_people */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'apse_' . $name;
	}

	/** Data di oggi (Y-m-d) nel fuso orario del sito. */
	public static function today(): string {
		return current_time( 'Y-m-d' );
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}
}
