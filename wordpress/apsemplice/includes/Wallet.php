<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tessera nel telefono: Apple Wallet (.pkpass) e Google Wallet. Facoltativo: funziona solo se il gestore carica le credenziali
 * (certificato Apple "Pass Type ID" e/o account di servizio Google) dalla pagina "Tessera e Wallet". Le chiavi private sono cifrate.
 *
 * La tessera nel wallet è un'immagine del momento dell'emissione (nome, numero, scadenza). Se è attivo il QR della tessera,
 * contiene anche quel QR, che verifica sempre la validità in diretta.
 */
final class Wallet {

	public static function register(): void {
		add_action( 'admin_post_apse_wallet_apple', array( __CLASS__, 'handle_apple' ) );
		add_action(
			'admin_post_nopriv_apse_wallet_apple',
			function () {
				wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
				exit;
			}
		);
	}

	// ---------- Configurazione ----------

	/** @return array|null credenziali complete per Apple Wallet, oppure null */
	public static function apple_config(): ?array {
		if ( ! Settings::wallet_enabled() ) {
			return null;
		}
		$c = array(
			'pass_type' => (string) Settings::get( 'wallet_apple_pass_type' ), 'team' => (string) Settings::get( 'wallet_apple_team' ),
			'cert'      => (string) Settings::get( 'wallet_apple_cert_pem' ), 'wwdr' => (string) Settings::get( 'wallet_apple_wwdr_pem' ),
			'key'       => Settings::secret( 'wallet_apple_key_pem' ),
		);
		foreach ( $c as $v ) {
			if ( '' === $v ) {
				return null;
			}
		}
		return $c;
	}

	/** @return array|null credenziali complete per Google Wallet, oppure null */
	public static function google_config(): ?array {
		if ( ! Settings::wallet_enabled() ) {
			return null;
		}
		$c = array( 'issuer' => (string) Settings::get( 'wallet_google_issuer' ), 'email' => (string) Settings::get( 'wallet_google_email' ), 'key' => Settings::secret( 'wallet_google_key_pem' ) );
		foreach ( $c as $v ) {
			if ( '' === $v ) {
				return null;
			}
		}
		return $c;
	}

	/**
	 * Salva le credenziali Apple. I file arrivano già letti (contenuto), così la logica si prova senza caricamenti reali.
	 *
	 * @param string|null $p12_bytes contenuto del .p12 (certificato + chiave)
	 * @param string|null $wwdr_bytes contenuto del certificato intermedio di Apple (.cer o .pem)
	 * @return string messaggio
	 * @throws \InvalidArgumentException
	 */
	public static function save_apple( array $in, ?string $p12_bytes, ?string $wwdr_bytes ): string {
		$set  = array();
		$note = array();
		if ( null !== $p12_bytes && '' !== $p12_bytes ) {
			$cred = WalletCredentials::from_p12( $p12_bytes, (string) ( $in['password'] ?? '' ) );
			WalletCredentials::check_pair( $cred['cert'], $cred['key'] );
			$info                         = WalletCredentials::cert_info( $cred['cert'] );
			$set['wallet_apple_cert_pem'] = $cred['cert'];
			$set['wallet_apple_key_pem']  = $cred['key'];
			$set['wallet_apple_pass_type'] = '' !== trim( (string) ( $in['pass_type'] ?? '' ) ) ? trim( (string) $in['pass_type'] ) : $info['pass_type'];
			$set['wallet_apple_team']      = '' !== trim( (string) ( $in['team'] ?? '' ) ) ? trim( (string) $in['team'] ) : $info['team'];
			$note[]                        = 'certificato caricato (scade il ' . $info['expires'] . ')';
		} else {
			foreach ( array( 'pass_type' => 'wallet_apple_pass_type', 'team' => 'wallet_apple_team' ) as $field => $key ) {
				if ( isset( $in[ $field ] ) && '' !== trim( (string) $in[ $field ] ) ) {
					$set[ $key ] = trim( (string) $in[ $field ] );
				}
			}
		}
		if ( null !== $wwdr_bytes && '' !== $wwdr_bytes ) {
			$pem = WalletCredentials::to_pem( $wwdr_bytes );
			if ( ! @openssl_x509_read( $pem ) ) {
				throw new \InvalidArgumentException( 'Il certificato intermedio WWDR non è valido (serve il file .cer di Apple, "Worldwide Developer Relations").' );
			}
			$set['wallet_apple_wwdr_pem'] = $pem;
			$note[]                       = 'certificato WWDR caricato';
		}
		if ( ! $set ) {
			throw new \InvalidArgumentException( 'Non hai indicato nulla da salvare.' );
		}
		Settings::update( $set );
		Audit::log( 'wallet.apple_saved', 'settings' );
		return 'Apple Wallet: ' . ( $note ? implode( ', ', $note ) : 'impostazioni salvate' ) . '.';
	}

	/** @throws \InvalidArgumentException */
	public static function save_google( array $in, ?string $json_bytes ): string {
		$set = array();
		if ( isset( $in['issuer'] ) && '' !== trim( (string) $in['issuer'] ) ) {
			$issuer = preg_replace( '/[^0-9]/', '', (string) $in['issuer'] );
			if ( '' === $issuer ) {
				throw new \InvalidArgumentException( 'L\'ID emittente di Google Wallet è un numero (lo trovi in Google Pay & Wallet Console).' );
			}
			$set['wallet_google_issuer'] = $issuer;
		}
		if ( null !== $json_bytes && '' !== $json_bytes ) {
			$g                          = WalletCredentials::google_from_json( $json_bytes );
			$set['wallet_google_email'] = $g['email'];
			$set['wallet_google_key_pem'] = $g['key'];
		}
		if ( ! $set ) {
			throw new \InvalidArgumentException( 'Non hai indicato nulla da salvare.' );
		}
		Settings::update( $set );
		Audit::log( 'wallet.google_saved', 'settings' );
		return 'Google Wallet: impostazioni salvate.';
	}

	public static function clear( string $which ): void {
		if ( 'apple' === $which ) {
			Settings::clear_secret( 'wallet_apple_key_pem' );
			Settings::update( array( 'wallet_apple_cert_pem' => '', 'wallet_apple_wwdr_pem' => '', 'wallet_apple_pass_type' => '', 'wallet_apple_team' => '' ) );
		} elseif ( 'google' === $which ) {
			Settings::clear_secret( 'wallet_google_key_pem' );
			Settings::update( array( 'wallet_google_email' => '', 'wallet_google_issuer' => '' ) );
		}
		Audit::log( 'wallet.cleared', 'settings', null, array( 'which' => $which ) );
	}

	// ---------- Tessere ----------

	/** @return array dati della tessera di un socio, comuni ai due wallet */
	public static function card_data( array $person ): array {
		if ( ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Gli ospiti non hanno la tessera.' );
		}
		$until = MemberType::is_auto_renewed( $person['type'] ) ? null : Plugin::people()->active_until( (int) $person['id'] );
		$org   = (string) Settings::get( 'association_name' );
		$exp   = null;
		if ( $until ) {
			$exp = ( new \DateTimeImmutable( $until . ' 23:59:59', wp_timezone() ) )->format( 'c' );
		}
		return array(
			'id'          => (int) $person['id'],
			'serial'      => 'apse-' . (int) $person['id'],
			'name'        => trim( $person['first_name'] . ' ' . $person['last_name'] ),
			'type'        => Levels::label( $person ),
			'card_number' => (string) ( $person['card_number'] ?: '—' ),
			'until_text'  => MemberType::is_auto_renewed( $person['type'] ) ? 'Sempre rinnovata' : ( $until ? ( new \DateTimeImmutable( $until ) )->format( 'd/m/Y' ) : '—' ),
			'expires_iso' => $exp,
			'org'         => '' !== $org ? $org : 'Associazione',
			'color'       => (string) ( Settings::get( 'accent_color' ) ?: '#2271b1' ),
			'url'         => Settings::card_qr_enabled() ? Settings::card_url( (int) $person['id'] ) : '',
			'description' => 'Tessera associativa',
		);
	}

	private static function person_or_fail( int $person_id ): array {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Socio non trovato.' );
		}
		return $p;
	}

	/** @throws \InvalidArgumentException */
	public static function apple_pass( int $person_id ): string {
		$cfg = self::apple_config();
		if ( ! $cfg ) {
			throw new \InvalidArgumentException( 'Apple Wallet non è configurato.' );
		}
		return ApplePass::build( $cfg, self::card_data( self::person_or_fail( $person_id ) ) );
	}

	/** @throws \InvalidArgumentException */
	public static function google_url( int $person_id ): string {
		$cfg = self::google_config();
		if ( ! $cfg ) {
			throw new \InvalidArgumentException( 'Google Wallet non è configurato.' );
		}
		$u = wp_parse_url( home_url() );
		return GoogleWallet::save_url( $cfg, self::card_data( self::person_or_fail( $person_id ) ), ( $u['scheme'] ?? 'https' ) . '://' . ( $u['host'] ?? '' ) . ( isset( $u['port'] ) ? ':' . $u['port'] : '' ), time() );
	}

	public static function apple_url( int $person_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_wallet_apple', 'person' => $person_id ), admin_url( 'admin-post.php' ) ), 'apse_wallet_apple_' . $person_id );
	}

	/** Pulsanti "Aggiungi al wallet" per la tessera digitale del socio (vuoto se nessun wallet è configurato). */
	public static function buttons( array $person ): string {
		if ( ! MemberType::is_member( $person['type'] ) ) {
			return '';
		}
		$html = '';
		if ( self::apple_config() ) {
			$html .= '<a class="apsf-btn wp-element-button" href="' . esc_url( self::apple_url( (int) $person['id'] ) ) . '">Aggiungi ad Apple Wallet</a>';
		}
		if ( self::google_config() ) {
			try {
				$html .= '<a class="apsf-btn wp-element-button" href="' . esc_url( self::google_url( (int) $person['id'] ) ) . '" target="_blank" rel="noopener">Salva su Google Wallet</a>';
			} catch ( \InvalidArgumentException $e ) {
				unset( $e ); // nessun pulsante se la tessera non si può creare
			}
		}
		return '' === $html ? '' : '<div class="apsf-wallet">' . $html . '</div>';
	}

	public static function handle_apple(): void {
		$pid = isset( $_GET['person'] ) ? (int) $_GET['person'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'apse_wallet_apple_' . $pid );
		if ( ! current_user_can( 'apse_view_person', $pid ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$bin = self::apple_pass( $pid );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.apple.pkpass' );
		header( 'Content-Disposition: attachment; filename="tessera.pkpass"' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput -- file binario
		exit;
	}
}
