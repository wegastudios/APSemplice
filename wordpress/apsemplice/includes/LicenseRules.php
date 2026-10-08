<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole delle licenze, pure e senza rete: le usa il plugin per mostrare lo stato e le userà identiche
 * il server delle licenze quando verrà attivato.
 *
 *  - una licenza è legata a UN dominio registrabile (es. "esempio.it"): vale per tutti i suoi sottodomini
 *    (www, staging, test…) ma non per altri domini;
 *  - al massimo {@see LicenseRules::MAX_INSTALLS} installazioni attive insieme su quel dominio
 *    (tipicamente produzione + staging);
 *  - gli ambienti locali di sviluppo (localhost, *.local, *.test, indirizzi IP) non contano e non servono licenza;
 *  - riattivare la stessa installazione (stesso id) non occupa un posto in più.
 */
final class LicenseRules {

	const MAX_INSTALLS = 2;

	const REASON_OK              = 'ok';
	const REASON_LOCAL           = 'local';            // sviluppo locale: libero
	const REASON_ALREADY_ACTIVE  = 'already_active';
	const REASON_DOMAIN_MISMATCH = 'domain_mismatch';  // la licenza è di un altro dominio
	const REASON_LIMIT_REACHED   = 'limit_reached';    // già 2 installazioni su questo dominio

	/** Suffissi pubblici a più etichette più comuni. Il server userà la Public Suffix List completa. */
	const MULTI_LABEL_SUFFIXES = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'com.br', 'com.mx',
		'com.ar', 'co.za', 'com.tr', 'com.cn', 'co.in', 'com.pl', 'com.es', 'org.es', 'com.pt',
	);

	/** Host in minuscolo, senza schema, porta, percorso. */
	public static function host( string $url_or_host ): string {
		$s = strtolower( trim( $url_or_host ) );
		if ( false === strpos( $s, '://' ) ) {
			$s = 'http://' . $s;
		}
		$host = parse_url( $s, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- lettura/scrittura in streaming di file grandi
		return is_string( $host ) ? rtrim( $host, '.' ) : '';
	}

	public static function is_local( string $url_or_host ): bool {
		$h = self::host( $url_or_host );
		if ( '' === $h || false === strpos( $h, '.' ) ) {
			return true; // "localhost", nomi di macchina
		}
		if ( filter_var( $h, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test', '.invalid', '.example' ) as $suffix ) {
			if ( substr( $h, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/** "https://staging.blog.esempio.it/x" => "esempio.it"; "https://www.esempio.co.uk" => "esempio.co.uk". */
	public static function registrable_domain( string $url_or_host ): string {
		$h = self::host( $url_or_host );
		if ( '' === $h ) {
			return '';
		}
		$labels = explode( '.', $h );
		if ( count( $labels ) <= 2 ) {
			return $h;
		}
		$last_two = implode( '.', array_slice( $labels, -2 ) );
		$take     = in_array( $last_two, self::MULTI_LABEL_SUFFIXES, true ) ? 3 : 2;
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/** Due indirizzi appartengono allo stesso dominio (sottodomini compresi). */
	public static function same_domain( string $a, string $b ): bool {
		$da = self::registrable_domain( $a );
		return '' !== $da && $da === self::registrable_domain( $b );
	}

	/**
	 * Si può attivare questa installazione?
	 *
	 * @param string|null $licensed_domain dominio registrabile a cui la licenza è già legata (null = ancora libera)
	 * @param array       $installs        installazioni attive [ ['id'=>..., 'url'=>...], ... ]
	 * @param string      $site_url        indirizzo dell'installazione che chiede l'attivazione
	 * @param string      $install_id      id dell'installazione (stabile finché il sito non cambia indirizzo)
	 * @return array ['allowed'=>bool, 'reason'=>REASON_*, 'domain'=>?string, 'used'=>int, 'max'=>int]
	 */
	public static function evaluate( ?string $licensed_domain, array $installs, string $site_url, string $install_id ): array {
		$base = array( 'allowed' => false, 'domain' => $licensed_domain, 'used' => 0, 'max' => self::MAX_INSTALLS );
		if ( self::is_local( $site_url ) ) {
			return array_merge( $base, array( 'allowed' => true, 'reason' => self::REASON_LOCAL ) );
		}
		$site_domain = self::registrable_domain( $site_url );
		$domain      = $licensed_domain ?: $site_domain; // la prima attivazione lega la licenza al dominio
		if ( $site_domain !== $domain ) {
			return array_merge( $base, array( 'reason' => self::REASON_DOMAIN_MISMATCH ) );
		}
		$on_domain = array_values(
			array_filter(
				$installs,
				function ( $i ) use ( $domain ) {
					return ! self::is_local( (string) $i['url'] ) && self::registrable_domain( (string) $i['url'] ) === $domain;
				}
			)
		);
		$used = count( $on_domain );
		foreach ( $on_domain as $i ) {
			if ( (string) $i['id'] === $install_id ) {
				return array_merge( $base, array( 'allowed' => true, 'reason' => self::REASON_ALREADY_ACTIVE, 'domain' => $domain, 'used' => $used ) );
			}
		}
		if ( $used >= self::MAX_INSTALLS ) {
			return array_merge( $base, array( 'reason' => self::REASON_LIMIT_REACHED, 'domain' => $domain, 'used' => $used ) );
		}
		return array_merge( $base, array( 'allowed' => true, 'reason' => self::REASON_OK, 'domain' => $domain, 'used' => $used + 1 ) );
	}

	public static function reason_label( string $reason ): string {
		$map = array(
			self::REASON_OK              => 'Installazione attivabile.',
			self::REASON_LOCAL           => 'Ambiente di sviluppo locale: non richiede licenza.',
			self::REASON_ALREADY_ACTIVE  => 'Questa installazione è già attiva.',
			self::REASON_DOMAIN_MISMATCH => 'La licenza è legata a un altro dominio.',
			self::REASON_LIMIT_REACHED   => 'Sono già attive 2 installazioni su questo dominio: disattivane una per liberare il posto.',
		);
		return $map[ $reason ] ?? $reason;
	}
}
