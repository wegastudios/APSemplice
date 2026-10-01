<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Cosa succede quando la licenza non è in regola (pagamento mancante, dominio non più associato).
 * Logica pura: dato lo stato e la data di inizio del problema decide popup e funzioni bloccate.
 *
 * Regola concordata:
 *  - i dati restano sempre leggibili, ma **coperti da un popup che chiede il pagamento**;
 *  - il popup si può chiudere solo nella **prima settimana**; poi non più;
 *  - **da subito** si bloccano le funzioni avanzate: esportazione dei dati e accesso di soci e volontari
 *    (e, quando esisteranno, pagamenti online, avvisi, app).
 */
final class LicensePolicy {

	const GRACE_DAYS = 7;

	// Stati. "standby" = verifica non attiva (oggi): nessuna restrizione.
	const STATUS_STANDBY    = 'standby';
	const STATUS_ACTIVE     = 'active';
	const STATUS_UNPAID     = 'unpaid';      // pagamento mancante o scaduto
	const STATUS_UNLICENSED = 'unlicensed';  // dominio rimosso dalla licenza o chiave non valida

	const POPUP_NONE     = 'none';
	const POPUP_CLOSABLE = 'closable';
	const POPUP_LOCKED   = 'locked';

	/** @return string[] stati che attivano le restrizioni */
	public static function penalized_statuses(): array {
		return array( self::STATUS_UNPAID, self::STATUS_UNLICENSED );
	}

	/**
	 * @param string      $status  uno degli STATUS_*
	 * @param string|null $since   Y-m-d in cui è iniziato il problema (null = oggi)
	 * @param string      $today   Y-m-d
	 * @return array ['status', 'popup', 'days_left' (giorni in cui il popup è ancora chiudibile), 'blocked' (funzioni bloccate)]
	 */
	public static function evaluate( string $status, ?string $since, string $today ): array {
		if ( ! in_array( $status, self::penalized_statuses(), true ) ) {
			return array( 'status' => $status, 'popup' => self::POPUP_NONE, 'days_left' => 0, 'blocked' => array() );
		}
		$days = 0;
		if ( $since ) {
			$diff = ( new \DateTimeImmutable( $since ) )->diff( new \DateTimeImmutable( $today ) );
			$days = $diff->invert ? 0 : (int) $diff->days;
		}
		$closable = $days < self::GRACE_DAYS;
		return array(
			'status'    => $status,
			'popup'     => $closable ? self::POPUP_CLOSABLE : self::POPUP_LOCKED,
			'days_left' => $closable ? self::GRACE_DAYS - $days : 0,
			'blocked'   => License::FEATURES, // tutto subito, anche durante la settimana di tolleranza
		);
	}

	/** Testo del popup per lo stato. */
	public static function message( string $status ): string {
		if ( self::STATUS_UNLICENSED === $status ) {
			return 'Questo sito non risulta più associato a una licenza valida di APSemplice.';
		}
		return 'Il pagamento della licenza di APSemplice non risulta effettuato.';
	}
}
