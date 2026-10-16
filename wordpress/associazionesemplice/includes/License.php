<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Punto UNICO in cui il plugin decide se una funzione avanzata è disponibile: `License::allows( 'funzione' )`.
 *
 * La licenza è una chiave legata a UN dominio, emessa dal portale (gratuita o a pagamento) e verificata ogni giorno con il server delle licenze
 * ({@see LicenseClient}). Il plugin gratuito funziona anche senza chiave: la licenza abilita i servizi collegati (prova Pro, avvisi di
 * aggiornamento) e, con il plugin Pro, le funzioni del piano acquistato. Mai nessuna funzione di base dipende dalla licenza.
 *
 * Finché la verifica non è attiva (nessuna credenziale, oppure sito locale) lo stato è `standby` e tutto è consentito.
 * Vedi docs/LICENZE.md.
 */
final class License {

	const OPT_INSTALL_ID  = 'asem_install_id';
	const OPT_INSTALL_URL = 'asem_install_url';
	const OPT_STATE       = 'asem_license_state';

	/** Funzioni avanzate che si bloccano se la licenza a pagamento non è in regola (solo con il plugin Pro installato). */
	const FEATURES = array(
		'export',            // esportazione dei dati (CSV prima nota, rendiconto, soci…)
		'member_area',       // accesso di soci e soci volontari
		'online_payments',   // pagamenti online (WooCommerce)
		'official_notices',  // avvisi ufficiali / notifiche push agli iscritti
		'pwa',               // app installabile
	);

	/**
	 * Livelli di licenza. Il portale vende Free, Pro Lite (senza contabilità) e Pro Full (tutto); «contabile» è il vecchio livello intermedio
	 * (conti, report e ricevute ma non IVA, 5 per mille e anni solari), ancora riconosciuto.
	 */
	const PLAN_FREE       = 'free';
	const PLAN_LITE       = 'lite';
	const PLAN_ACCOUNTING = 'accounting';
	const PLAN_FISCAL     = 'fiscal'; // = Pro Full

	const PLANS = array( self::PLAN_FREE, self::PLAN_LITE, self::PLAN_ACCOUNTING, self::PLAN_FISCAL );

	/** Livello della licenza. Senza verifica attiva (standby) valgono tutte le funzioni; senza chiave, con la verifica attiva, vale il gratuito. */
	public static function plan(): string {
		$s = self::state();
		if ( LicensePolicy::STATUS_NOKEY === $s['status'] ) {
			return self::PLAN_FREE;
		}
		return in_array( (string) $s['plan'], self::PLANS, true ) ? (string) $s['plan'] : self::PLAN_FISCAL;
	}

	public static function plan_label( string $plan ): string {
		$map = array( self::PLAN_FREE => 'Gratuito', self::PLAN_LITE => 'Pro Lite', self::PLAN_ACCOUNTING => 'Pro (contabile)', self::PLAN_FISCAL => 'Pro Full' );
		return $map[ $plan ] ?? $plan;
	}

	public static function key(): string {
		return (string) Settings::get( 'license_key' );
	}

	/** La verifica con il server è attiva su questo sito? */
	public static function enabled(): bool {
		return LicenseClient::enabled();
	}

	/** @return array stato salvato: status, since, checked_at, url, plan, reason, expires_at, next_check_at, error */
	public static function state(): array {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'status'        => (string) ( $s['status'] ?? LicensePolicy::STATUS_STANDBY ),
			'since'         => $s['since'] ?? null,
			'checked_at'    => $s['checked_at'] ?? null,
			'url'           => $s['url'] ?? null,
			'plan'          => $s['plan'] ?? null,
			'reason'        => (string) ( $s['reason'] ?? '' ),
			'expires_at'    => $s['expires_at'] ?? null,
			'next_check_at' => $s['next_check_at'] ?? null,
			'in_grace'      => ! empty( $s['in_grace'] ),
			'error'         => (string) ( $s['error'] ?? '' ),
		);
	}

	/** Lo scrive la verifica con il server (e i test). */
	public static function set_state( string $status, ?string $since = null, ?string $payment_url = null, ?string $plan = null, array $extra = array() ): void {
		update_option(
			self::OPT_STATE,
			array( 'status' => $status, 'since' => $since, 'checked_at' => Db::now(), 'url' => $payment_url, 'plan' => $plan ) + $extra
		);
	}

	/** Indirizzo per regolarizzare il pagamento: il portale delle licenze (modificabile con il filtro `asem_license_portal_url`). */
	public static function payment_url(): string {
		$stored = (string) ( self::state()['url'] ?? '' );
		return '' !== $stored ? $stored : (string) apply_filters( 'asem_license_portal_url', 'https://associazionesemplice.it/accedi' );
	}

	/** Dove si ottiene la chiave gratuita: il portale, con il dominio di questo sito già compilato. */
	public static function free_url(): string {
		$base   = (string) apply_filters( 'asem_license_free_url', 'https://associazionesemplice.it/free' );
		$domain = LicenseClient::normalize_domain( home_url() );
		return '' === $domain ? $base : add_query_arg( 'domain', rawurlencode( $domain ), $base );
	}

	public static function policy(): array {
		$s = self::state();
		return LicensePolicy::evaluate( $s['status'], $s['since'], Db::today() );
	}

	public static function allows( string $feature ): bool {
		return ! in_array( $feature, self::policy()['blocked'], true );
	}

	/**
	 * La licenza non è in regola (pagamento mancante o dominio non più associato)? Allora Associazione Semplice Pro torna alle funzioni di base.
	 * Senza il plugin Pro non c'è niente da sospendere: l'edizione gratuita non perde mai nulla.
	 */
	public static function degraded(): bool {
		return Edition::pro_present() && in_array( self::policy()['status'], LicensePolicy::penalized_statuses(), true );
	}

	/**
	 * Verifica la chiave con il server e salva l'esito. Se il server non risponde lo stato precedente resta com'è (una revoca non
	 * si «annulla» per un errore di rete, e un errore di rete non toglie le funzioni).
	 *
	 * @return array ['ok' => bool, 'message' => string]
	 */
	public static function sync(): array {
		if ( ! LicenseClient::enabled() ) {
			return array( 'ok' => true, 'message' => LicenseClient::is_local() ? 'Ambiente di sviluppo locale: non serve la licenza.' : 'La verifica della licenza non è ancora attiva.' );
		}
		$key = self::key();
		if ( '' === $key ) {
			self::set_state( LicensePolicy::STATUS_NOKEY, null, null, self::PLAN_FREE );
			return array( 'ok' => true, 'message' => 'Nessuna chiave inserita: funzionano le funzioni di base.' );
		}
		$domain = LicenseClient::normalize_domain( home_url() );
		if ( '' === $domain ) {
			return self::failed( 'Indirizzo del sito non utilizzabile per la licenza (serve un nome di dominio).' );
		}
		$r = LicenseClient::validate( $key, $domain );
		if ( is_wp_error( $r ) ) {
			return self::failed( $r->get_error_message() );
		}
		if ( ! empty( $r['valid'] ) && ! empty( $r['attestation'] ) ) {
			// Il server firma la risposta: se la firma non torna non ci si fida (si resta allo stato precedente).
			$jwks = LicenseClient::jwks();
			if ( $jwks && null === LicenseClient::verify_attestation( (string) $r['attestation'], $jwks, LicenseClient::ISSUER, (string) ( $r['product_code'] ?? '' ), $domain ) ) {
				return self::failed( 'La risposta del server delle licenze non ha una firma valida.' );
			}
		}
		$i = LicenseClient::interpret( $r );
		self::set_state(
			$i['status'], $i['since'], null, $i['plan'],
			array( 'reason' => $i['reason'], 'expires_at' => $i['expires_at'], 'next_check_at' => $i['next_check_at'], 'in_grace' => $i['in_grace'] )
		);
		$msg = LicenseClient::reason_label( $i['reason'] );
		if ( LicensePolicy::STATUS_ACTIVE === $i['status'] ) {
			$msg = 'Licenza ' . self::plan_label( $i['plan'] ) . ' valida' . ( $i['expires_at'] ? ' fino al ' . gmdate( 'd/m/Y', strtotime( $i['expires_at'] ) ) : '' ) . '.' . ( $i['in_grace'] ? ' ' . LicenseClient::reason_label( 'IN_GRACE' ) : '' );
		}
		return array( 'ok' => LicensePolicy::STATUS_ACTIVE === $i['status'], 'message' => '' !== $msg ? $msg : 'Licenza non valida.' );
	}

	/** Errore di rete o di risposta: lo si ricorda per mostrarlo, ma lo stato non cambia. */
	private static function failed( string $message ): array {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		update_option( self::OPT_STATE, array_merge( $s, array( 'error' => $message, 'error_at' => Db::now() ) ) );
		return array( 'ok' => false, 'message' => $message );
	}

	/** Lavoro giornaliero di WP-Cron. */
	public static function cron(): void {
		self::sync();
	}

	/**
	 * Identità di QUESTA installazione: un id casuale che resta finché il sito non cambia indirizzo.
	 * Se il sito viene copiato altrove (es. produzione -> staging) la copia porta con sé il database, quindi lo
	 * stesso id: lo si riconosce perché l'indirizzo non coincide più, e si genera un id nuovo.
	 *
	 * @return array ['id'=>string, 'url'=>string, 'domain'=>string, 'moved'=>bool]
	 */
	public static function installation(): array {
		$url   = home_url();
		$host  = LicenseRules::host( $url );
		$id    = (string) get_option( self::OPT_INSTALL_ID, '' );
		$known = (string) get_option( self::OPT_INSTALL_URL, '' );
		$moved = false;
		if ( '' === $id || ( '' !== $known && LicenseRules::host( $known ) !== $host ) ) {
			$moved = '' !== $id;
			$id    = wp_generate_uuid4();
			update_option( self::OPT_INSTALL_ID, $id );
		}
		if ( $known !== $url ) {
			update_option( self::OPT_INSTALL_URL, $url );
		}
		return array( 'id' => $id, 'url' => $url, 'domain' => LicenseClient::normalize_domain( $url ) ?: self::domain( $url ), 'moved' => $moved );
	}

	/** Stato mostrabile nelle impostazioni. */
	public static function status(): array {
		$key    = self::key();
		$inst   = self::installation();
		$policy = self::policy();
		$state  = self::state();
		$enabled = self::enabled();
		$note   = LicensePolicy::STATUS_STANDBY === $policy['status'] ? 'Verifica della licenza non ancora attiva: tutte le funzioni sono disponibili.' : LicensePolicy::message( $policy['status'] );
		if ( LicensePolicy::STATUS_ACTIVE === $policy['status'] ) {
			$note = 'Licenza ' . self::plan_label( self::plan() ) . ' valida' . ( $state['expires_at'] ? ' fino al ' . gmdate( 'd/m/Y', strtotime( (string) $state['expires_at'] ) ) : '' ) . '.';
		}
		return array(
			'state'      => '' === $key ? 'none' : 'unchecked',
			'status'     => $policy['status'],
			'domain'     => $inst['domain'],
			'install_id' => $inst['id'],
			'local'      => LicenseClient::is_local(),
			'enabled'    => $enabled,
			'plan'       => self::plan(),
			'plan_label' => self::plan_label( self::plan() ),
			'checked_at' => $state['checked_at'],
			'reason'     => LicenseClient::reason_label( $state['reason'] ),
			'error'      => $state['error'],
			'note'       => $note,
		);
	}

	/** Dominio senza schema, percorso e "www." (per mostrarlo): "https://www.Esempio.it/x" => "esempio.it". */
	public static function domain( string $url ): string {
		$host = LicenseRules::host( $url );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
