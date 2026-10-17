<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso «ci sono aggiornamenti»: chiede al portale di Associazione Semplice qual è l'ultima versione del plugin gratuito e del Pro
 * (`GET /api/plugin/latest`, risposta `{"free":{"version":"1.1.13","url":"…"},"pro":{…}}`) e la confronta con quella installata.
 * Non manda niente al portale (nessuna chiave, nessun dato del sito): la risposta è la stessa per tutti. Funziona solo con una chiave di
 * licenza inserita (anche gratuita), mai sui siti locali, e si spegne da Impostazioni → Limiti e soglie. Il Pro e, finché non è su
 * WordPress.org, anche il gratuito si scaricano dall'area clienti: WordPress da solo non li aggiorna.
 */
final class UpdateCheck {

	const API = 'https://associazionesemplice.it/api/plugin/latest';
	const OPT = 'asem_update_latest';

	/** Versione in forma «1.2» o «1.2.3(.4)»: tutto il resto della risposta si scarta. */
	public static function valid_version( string $v ): bool {
		return (bool) preg_match( '/^\d{1,3}(\.\d{1,4}){1,3}$/', $v );
	}

	/**
	 * Risposta del portale → versioni valide per prodotto. Una voce malformata si ignora; l'indirizzo di scarico è accettato solo se https.
	 *
	 * @param mixed $body testo JSON
	 * @return array<string,array{version:string,url:string}>
	 */
	public static function parse( $body ): array {
		$j = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $j ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'free', 'pro' ) as $p ) {
			$v = isset( $j[ $p ]['version'] ) && is_string( $j[ $p ]['version'] ) ? trim( $j[ $p ]['version'] ) : '';
			if ( ! self::valid_version( $v ) ) {
				continue;
			}
			$u           = isset( $j[ $p ]['url'] ) && is_string( $j[ $p ]['url'] ) ? trim( $j[ $p ]['url'] ) : '';
			$out[ $p ] = array( 'version' => $v, 'url' => 0 === strpos( $u, 'https://' ) ? $u : '' );
		}
		return $out;
	}

	public static function is_newer( string $installed, string $latest ): bool {
		return self::valid_version( $installed ) && self::valid_version( $latest ) && version_compare( $latest, $installed, '>' );
	}

	/** Il controllo è attivo: scelta fatta dall'amministratore, chiave di licenza inserita, sito non locale. */
	public static function enabled(): bool {
		return 1 === Limits::get( 'update_notice' ) && '' !== License::key() && ! LicenseClient::is_local();
	}

	/** Ultimo esito salvato. @return array{checked_at:?string,error:string,free?:array,pro?:array} */
	public static function latest(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s + array( 'checked_at' => null, 'error' => '' ) : array( 'checked_at' => null, 'error' => '' );
	}

	/** Lavoro orario di WP-Cron: parte solo se è passato il tempo scelto dall'ultimo controllo. */
	public static function cron(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$last = self::latest()['checked_at'];
		if ( $last && strtotime( (string) $last . ' UTC' ) + Limits::get( 'update_check_hours' ) * HOUR_IN_SECONDS > time() ) {
			return;
		}
		self::fetch();
	}

	/** Chiede le ultime versioni e salva l'esito. Con un errore di rete restano le versioni già note. */
	public static function fetch(): bool {
		$api = (string) apply_filters( 'asem_update_api', self::API );
		$res = wp_remote_get( $api, array( 'timeout' => 8, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$old = self::latest();
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$msg = is_wp_error( $res ) ? $res->get_error_message() : 'Risposta ' . (int) wp_remote_retrieve_response_code( $res );
			update_option( self::OPT, array_merge( $old, array( 'checked_at' => Db::now(), 'error' => $msg ) ), false );
			return false;
		}
		$v = self::parse( wp_remote_retrieve_body( $res ) );
		update_option( self::OPT, array_merge( $v ? $v : array_intersect_key( $old, array_flip( array( 'free', 'pro' ) ) ), array( 'checked_at' => Db::now(), 'error' => $v ? '' : 'Risposta non valida.' ) ), false );
		return (bool) $v;
	}

	/**
	 * Aggiornamenti disponibili per ciò che è installato (il Pro solo se presente).
	 *
	 * @return array<int,array{product:string,label:string,installed:string,latest:string,url:string}>
	 */
	public static function pending(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		$l   = self::latest();
		$out = array();
		$set = array( 'free' => array( 'Associazione Semplice', defined( 'ASEM_VERSION' ) ? (string) ASEM_VERSION : '' ) );
		if ( Edition::pro_present() && defined( 'ASEM_PRO_VERSION' ) ) {
			$set['pro'] = array( 'Associazione Semplice Pro', (string) ASEM_PRO_VERSION );
		}
		foreach ( $set as $p => $info ) {
			if ( isset( $l[ $p ]['version'] ) && self::is_newer( $info[1], (string) $l[ $p ]['version'] ) ) {
				$out[] = array( 'product' => $p, 'label' => $info[0], 'installed' => $info[1], 'latest' => (string) $l[ $p ]['version'], 'url' => (string) ( $l[ $p ]['url'] ?? '' ) );
			}
		}
		return $out;
	}

	/** Frase per le impostazioni e per l'esito di «Controlla adesso»: ultime versioni note e data dell'ultimo controllo. */
	public static function summary(): string {
		if ( 1 !== Limits::get( 'update_notice' ) ) {
			return 'Avviso aggiornamenti spento (Impostazioni → Tecniche → Limiti e soglie).';
		}
		$l = self::latest();
		if ( ! $l['checked_at'] ) {
			return 'Aggiornamenti: non ancora controllati.';
		}
		$when = mysql2date( 'd/m/Y H:i', (string) $l['checked_at'] );
		if ( '' !== (string) $l['error'] ) {
			return 'Aggiornamenti: ultimo controllo del ' . $when . ' non riuscito (' . $l['error'] . ').';
		}
		$pending = self::pending();
		if ( $pending ) {
			return 'Aggiornamenti: disponibile ' . implode( ', ', array_map( function ( $u ) {
				return $u['label'] . ' ' . $u['latest'] . ' (hai la ' . $u['installed'] . ')';
			}, $pending ) ) . '. Controllato il ' . $when . '.';
		}
		return 'Aggiornamenti: sei all\'ultima versione. Controllato il ' . $when . '.';
	}

	/** Dove si scarica: l'indirizzo indicato dal portale (https), altrimenti l'area clienti. */
	public static function download_url( array $u ): string {
		return '' !== $u['url'] ? $u['url'] : (string) apply_filters( 'asem_license_portal_url', 'https://associazionesemplice.it/accedi' );
	}
}
