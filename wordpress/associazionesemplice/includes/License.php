<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Punto UNICO in cui il plugin decide se una funzione è disponibile: `License::allows( 'funzione' )`.
 *
 * Oggi la verifica con il server è in "standby": nessun server, quindi lo stato è `standby` e tutto è consentito.
 * Quando il server esisterà scriverà lo stato con {@see License::set_state()}; il resto è già pronto:
 *  - {@see LicenseRules}: una licenza = un dominio (sottodomini compresi), al massimo 2 installazioni;
 *  - {@see LicensePolicy}: cosa succede se non è in regola (popup, settimana di tolleranza, funzioni bloccate).
 * Vedi docs/LICENZE.md.
 */
final class License {

	const OPT_INSTALL_ID  = 'asem_install_id';
	const OPT_INSTALL_URL = 'asem_install_url';
	const OPT_STATE       = 'asem_license_state';

	/** Funzioni avanzate che si bloccano se la licenza non è in regola. */
	const FEATURES = array(
		'export',            // esportazione dei dati (CSV prima nota, rendiconto, soci…)
		'member_area',       // accesso di soci e soci volontari
		'online_payments',   // pagamenti online (WooCommerce)
		'official_notices',  // avvisi ufficiali / notifiche push agli iscritti
		'pwa',               // app installabile
	);

	/** Livelli di licenza: contabile (conti, pagamenti, report, comunicazioni…) e fiscale (in più IVA, 5 per mille, ricevute e anni solari). */
	const PLAN_ACCOUNTING = 'accounting';
	const PLAN_FISCAL     = 'fiscal';

	/** Livello della licenza: lo comunica il servizio delle licenze; finché la verifica non c'è (standby) valgono tutte le funzioni. */
	public static function plan(): string {
		$p = (string) ( self::state()['plan'] ?? '' );
		return self::PLAN_ACCOUNTING === $p ? self::PLAN_ACCOUNTING : self::PLAN_FISCAL;
	}

	public static function key(): string {
		return (string) Settings::get( 'license_key' );
	}

	/** @return array ['status'=>LicensePolicy::STATUS_*, 'since'=>?'Y-m-d', 'checked_at'=>?string] */
	public static function state(): array {
		$s = get_option( self::OPT_STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'status'     => (string) ( $s['status'] ?? LicensePolicy::STATUS_STANDBY ),
			'since'      => $s['since'] ?? null,
			'checked_at' => $s['checked_at'] ?? null,
			'url'        => $s['url'] ?? null,
			'plan'       => $s['plan'] ?? null,
		);
	}

	/** Lo scrive il client del server delle licenze (e i test). */
	public static function set_state( string $status, ?string $since = null, ?string $payment_url = null, ?string $plan = null ): void {
		update_option( self::OPT_STATE, array( 'status' => $status, 'since' => $since, 'checked_at' => Db::now(), 'url' => $payment_url, 'plan' => $plan ) );
	}

	/** Indirizzo per regolarizzare il pagamento: lo comunica il servizio delle licenze insieme allo stato. */
	public static function payment_url(): string {
		return (string) ( self::state()['url'] ?? '' );
	}

	public static function policy(): array {
		$s = self::state();
		return LicensePolicy::evaluate( $s['status'], $s['since'], Db::today() );
	}

	public static function allows( string $feature ): bool {
		return ! in_array( $feature, self::policy()['blocked'], true );
	}

	/** La licenza non è in regola (pagamento mancante o dominio non più associato)? Allora AssociazioneSemplice Pro torna alle funzioni di base. */
	public static function degraded(): bool {
		return in_array( self::policy()['status'], LicensePolicy::penalized_statuses(), true );
	}

	/**
	 * Identità di QUESTA installazione: un id casuale che resta finché il sito non cambia indirizzo.
	 * Se il sito viene copiato altrove (es. produzione -> staging) la copia porta con sé il database, quindi lo
	 * stesso id: lo si riconosce perché l'indirizzo non coincide più, e si genera un id nuovo. Così la copia
	 * è una installazione distinta e non "ruba" l'attivazione dell'originale.
	 *
	 * @return array ['id'=>string, 'url'=>string, 'domain'=>string (registrabile), 'moved'=>bool]
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
		return array( 'id' => $id, 'url' => $url, 'domain' => LicenseRules::registrable_domain( $url ), 'moved' => $moved );
	}

	/** Stato mostrabile nelle impostazioni. */
	public static function status(): array {
		$key    = self::key();
		$inst   = self::installation();
		$policy = self::policy();
		return array(
			'state'        => '' === $key ? 'none' : 'unchecked',
			'status'       => $policy['status'],
			'domain'       => $inst['domain'],
			'install_id'   => $inst['id'],
			'local'        => LicenseRules::is_local( $inst['url'] ),
			'max_installs' => LicenseRules::MAX_INSTALLS,
			'note'         => LicensePolicy::STATUS_STANDBY === $policy['status'] ? 'Verifica della licenza non ancora attiva: tutte le funzioni sono disponibili.' : LicensePolicy::message( $policy['status'] ),
		);
	}

	/** Dominio senza schema, percorso e "www." (per mostrarlo): "https://www.Esempio.it/x" => "esempio.it". */
	public static function domain( string $url ): string {
		$host = LicenseRules::host( $url );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
