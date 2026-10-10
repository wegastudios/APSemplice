<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tessera per Google Wallet: un indirizzo "Salva su Google Wallet" che contiene la tessera in un JWT firmato (RS256)
 * con la chiave dell'account di servizio dell'associazione. Nessuna chiamata a Google da parte del sito.
 */
final class GoogleWallet {

	const MAX_JWT = 1800; // limite di Google per il JWT contenuto in un indirizzo

	public static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( string $s ): string {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) );
	}

	private static function text( string $v ): array {
		return array( 'defaultValue' => array( 'language' => 'it', 'value' => $v ) );
	}

	/**
	 * @param array $cfg  issuer (ID emittente), email (account di servizio), key (PEM)
	 * @param array $card id (univoco per la persona), name, type, card_number, until_text, org, color (#RRGGBB), url (valore del QR, o '')
	 * @return string indirizzo di salvataggio
	 * @throws \InvalidArgumentException
	 */
	public static function save_url( array $cfg, array $card, string $origin, int $now ): string {
		$issuer = preg_replace( '/[^0-9]/', '', (string) $cfg['issuer'] );
		if ( '' === $issuer ) {
			throw new \InvalidArgumentException( 'ID emittente di Google Wallet non valido.' );
		}
		$class_id = $issuer . '.asem_card';
		$rgb      = Png::rgb( (string) ( $card['color'] ?? '' ) );
		$obj      = array(
			'id'                 => $issuer . '.asem_p' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $card['id'] ),
			'classId'            => $class_id,
			'state'              => 'ACTIVE',
			'hexBackgroundColor' => sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] ),
			'cardTitle'          => self::text( '' !== (string) $card['org'] ? (string) $card['org'] : 'Tessera' ),
			'header'             => self::text( (string) $card['name'] ),
			'subheader'          => self::text( (string) $card['type'] ),
			'textModulesData'    => array(
				array( 'id' => 'card', 'header' => 'Tessera n.', 'body' => (string) $card['card_number'] ),
				array( 'id' => 'valid', 'header' => 'Valida fino al', 'body' => (string) $card['until_text'] ),
			),
		);
		if ( ! empty( $card['url'] ) ) {
			$obj['barcode'] = array( 'type' => 'QR_CODE', 'value' => (string) $card['url'], 'alternateText' => '' );
		}
		$last = '';
		// Se il JWT supera il limite si tolgono i dettagli facoltativi, uno alla volta.
		foreach ( array( array(), array( 'subheader' ), array( 'subheader', 'textModulesData' ) ) as $drop ) {
			$o = $obj;
			foreach ( $drop as $k ) {
				unset( $o[ $k ] );
			}
			$last = self::jwt(
				$cfg,
				array(
					'iss'     => (string) $cfg['email'], 'aud' => 'google', 'typ' => 'savetowallet', 'iat' => $now, 'origins' => array( $origin ),
					'payload' => array( 'genericClasses' => array( array( 'id' => $class_id ) ), 'genericObjects' => array( $o ) ),
				)
			);
			if ( strlen( $last ) <= self::MAX_JWT ) {
				return 'https://pay.google.com/gp/v/save/' . $last;
			}
		}
		throw new \InvalidArgumentException( 'La tessera è troppo lunga per Google Wallet (' . strlen( $last ) . ' caratteri).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
	}

	public static function jwt( array $cfg, array $claims ): string {
		$key = @openssl_pkey_get_private( (string) $cfg['key'] );
		if ( ! $key ) {
			throw new \InvalidArgumentException( 'Chiave di Google Wallet non valida: ricarica il file JSON dell\'account di servizio.' );
		}
		$input = self::b64url( (string) json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.'
			. self::b64url( (string) json_encode( $claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$sig   = '';
		if ( ! openssl_sign( $input, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new \InvalidArgumentException( 'Firma per Google Wallet non riuscita.' );
		}
		return $input . '.' . self::b64url( $sig );
	}
}
