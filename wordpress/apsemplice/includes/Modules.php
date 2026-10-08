<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Le parti del gestionale che l'ente usa davvero. Una parte spenta sparisce dal menu e dalle schede (nulla viene cancellato: i dati restano
 * e si riaccende in qualsiasi momento dalla configurazione guidata). Finché non si fa la configurazione guidata tutte le parti sono accese.
 */
final class Modules {

	/** Chiave => etichetta, domanda, pagine amministrative, dipende da. */
	public static function defs(): array {
		return array(
			'activities' => array( 'label' => 'Corsi ed eventi', 'ask' => 'Organizzi corsi, eventi o attività con iscrizioni e presenze?', 'pages' => array( 'apse-activities', 'apse-calendar', 'apse-attendance' ), 'needs' => '' ),
			'ledger'     => array( 'label' => 'Soldi e prima nota', 'ask' => 'Ti serve registrare incassi e spese (la prima nota)?', 'pages' => array( 'apse-money', 'apse-ledger', 'apse-income', 'apse-group', 'apse-expense' ), 'needs' => '' ),
			'accounts'   => array( 'label' => 'Conti e fondi', 'ask' => 'Ti serve gestire altri conti oltre alla cassa contanti (banca, PayPal, fondi)?', 'pages' => array( 'apse-accounts', 'apse-transfer' ), 'needs' => 'ledger' ),
			'accounting' => array( 'label' => 'Contabilità', 'ask' => 'Tieni la contabilità qui (anni solari, adempimenti, 5x1000)? Se la tiene un altro, resta solo la prima nota.', 'pages' => array( 'apse-accounting', 'apse-years', 'apse-fivepm' ), 'needs' => 'ledger' ),
			'reports'    => array( 'label' => 'Bilanci e rendiconto', 'ask' => 'Ti servono i bilanci e il rendiconto?', 'pages' => array( 'apse-reports', 'apse-statement' ), 'needs' => 'accounting' ),
			'book'       => array( 'label' => 'Libro soci e verbali', 'ask' => 'Ti serve il libro soci (con i verbali)?', 'pages' => array( 'apse-book', 'apse-minutes' ), 'needs' => '' ),
			'messages'   => array( 'label' => 'Comunicazioni', 'ask' => 'Vuoi scrivere ai soci dal gestionale, via email?', 'pages' => array( 'apse-messages' ), 'needs' => '' ),
			'import'     => array( 'label' => 'Importazioni', 'ask' => 'Devi importare soci da Excel, CSV o da WP All Import?', 'pages' => array( 'apse-import', 'apse-wpai' ), 'needs' => '' ),
		);
	}

	/** Parte accesa? I bilanci seguono l'impostazione già esistente; le altre si spengono solo se scelto. */
	public static function on( string $key ): bool {
		$defs = self::defs();
		if ( ! isset( $defs[ $key ] ) ) {
			return true;
		}
		if ( '' !== $defs[ $key ]['needs'] && ! self::on( $defs[ $key ]['needs'] ) ) {
			return false;
		}
		if ( 'reports' === $key ) {
			return ! empty( Settings::get( 'reports_enabled' ) );
		}
		$saved = Settings::get( 'modules' );
		return ! is_array( $saved ) || ! array_key_exists( $key, $saved ) || ! empty( $saved[ $key ] );
	}

	/** @return string[] pagine amministrative delle parti spente */
	public static function off_pages(): array {
		$out = array();
		foreach ( self::defs() as $key => $d ) {
			if ( ! self::on( $key ) ) {
				$out = array_merge( $out, $d['pages'] );
			}
		}
		return $out;
	}

	/** Solo chiavi note con valore 0/1 ("reports" vive in reports_enabled). */
	public static function sanitize( array $in ): array {
		$out = array();
		foreach ( self::defs() as $key => $_ ) {
			if ( 'reports' !== $key && array_key_exists( $key, $in ) ) {
				$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
			}
		}
		return $out;
	}
}
