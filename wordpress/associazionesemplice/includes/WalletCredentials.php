<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Lettura e controllo dei certificati e delle chiavi per Apple Wallet e Google Wallet. */
final class WalletCredentials {

	/** Certificato o chiave in PEM; se arriva in formato binario (DER, es. il .cer di Apple) lo converte. */
	public static function to_pem( string $bytes, string $label = 'CERTIFICATE' ): string {
		if ( false !== strpos( $bytes, '-----BEGIN' ) ) {
			return str_replace( "\r\n", "\n", trim( $bytes ) ) . "\n";
		}
		// DER binario: mai trim(), toglierebbe un ultimo byte "bianco" (0x00, 0x09, 0x0a, 0x0d, 0x20) e il certificato risulterebbe troncato.
		return '-----BEGIN ' . $label . "-----\n" . chunk_split( base64_encode( $bytes ), 64, "\n" ) . '-----END ' . $label . "-----\n";
	}

	/**
	 * Certificato e chiave privata da un file .p12.
	 *
	 * @return array ['cert'=>PEM, 'key'=>PEM]
	 * @throws \InvalidArgumentException
	 */
	public static function from_p12( string $bytes, string $password ): array {
		if ( ! function_exists( 'openssl_pkcs12_read' ) ) {
			throw new \InvalidArgumentException( 'Questo server non supporta i certificati (manca l\'estensione openssl di PHP).' );
		}
		$out = array();
		if ( ! @openssl_pkcs12_read( $bytes, $out, $password ) || empty( $out['cert'] ) || empty( $out['pkey'] ) ) {
			throw new \InvalidArgumentException( 'Impossibile leggere il file .p12: controlla la password. Se il file viene da "Accesso Portachiavi" di macOS e il problema resta, esportalo di nuovo oppure incolla certificato e chiave in formato PEM.' );
		}
		return array( 'cert' => trim( $out['cert'] ) . "\n", 'key' => trim( $out['pkey'] ) . "\n" );
	}

	/** @throws \InvalidArgumentException se la chiave non è quella del certificato */
	public static function check_pair( string $cert_pem, string $key_pem, string $password = '' ): void {
		$cert = @openssl_x509_read( $cert_pem );
		$key  = @openssl_pkey_get_private( $key_pem, $password );
		if ( ! $cert ) {
			throw new \InvalidArgumentException( 'Il certificato non è valido.' );
		}
		if ( ! $key ) {
			throw new \InvalidArgumentException( 'La chiave privata non è valida o è protetta da una password.' );
		}
		if ( ! openssl_x509_check_private_key( $cert, $key ) ) {
			throw new \InvalidArgumentException( 'La chiave privata non corrisponde al certificato.' );
		}
	}

	/** @return array pass_type, team, expires (Y-m-d), subject */
	public static function cert_info( string $cert_pem ): array {
		$x = @openssl_x509_parse( $cert_pem );
		if ( ! is_array( $x ) ) {
			throw new \InvalidArgumentException( 'Il certificato non è valido.' );
		}
		$s    = (array) ( $x['subject'] ?? array() );
		$type = (string) ( $s['UID'] ?? '' );
		$cn   = (string) ( $s['CN'] ?? '' );
		if ( '' === $type && 0 === strpos( $cn, 'Pass Type ID: ' ) ) {
			$type = substr( $cn, strlen( 'Pass Type ID: ' ) );
		}
		return array(
			'pass_type' => $type,
			'team'      => (string) ( $s['OU'] ?? '' ),
			'expires'   => isset( $x['validTo_time_t'] ) ? gmdate( 'Y-m-d', (int) $x['validTo_time_t'] ) : '',
			'subject'   => $cn,
		);
	}

	/**
	 * Dati dal file JSON dell'account di servizio di Google Cloud.
	 *
	 * @return array ['email', 'key']
	 * @throws \InvalidArgumentException
	 */
	public static function google_from_json( string $json ): array {
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) || empty( $d['client_email'] ) || empty( $d['private_key'] ) ) {
			throw new \InvalidArgumentException( 'Il file non è la chiave JSON di un account di servizio Google (mancano "client_email" e "private_key").' );
		}
		if ( ! @openssl_pkey_get_private( (string) $d['private_key'] ) ) {
			throw new \InvalidArgumentException( 'La chiave privata nel file JSON non è valida.' );
		}
		return array( 'email' => (string) $d['client_email'], 'key' => trim( (string) $d['private_key'] ) . "\n" );
	}
}
