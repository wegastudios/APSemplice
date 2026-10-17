<?php
namespace Portale;

/** Prova dei collegamenti con Stripe, PayPal e il server licenze: usa le chiavi in uso e dice se funzionano. */
final class Connections {

	/** @return array [bool riuscito, string messaggio] */
	public static function test( string $which ): array {
		switch ( $which ) {
			case 'stripe':
				return self::stripe();
			case 'paypal':
				return self::paypal();
			case 'wega':
				return self::wega();
		}
		return array( false, 'Collegamento sconosciuto.' );
	}

	private static function stripe(): array {
		$key = (string) Config::get( 'stripe.secret_key', '' );
		if ( '' === $key ) {
			return array( false, 'Stripe: manca la chiave segreta.' );
		}
		$isLive = (bool) preg_match( '/^(sk|rk)_live_/', $key );
		if ( Settings::stripeLive() !== $isLive ) {
			return array( false, 'Stripe: la modalità scelta (' . ( Settings::stripeLive() ? 'reale' : 'prova' ) . ') non corrisponde alla chiave (' . ( $isLive ? 'reale' : 'di prova' ) . ').' );
		}
		list( $status ) = Http::send( 'GET', Stripe::API . '/balance', array( 'Authorization: Bearer ' . $key ) );
		$mode = 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'rk_live_' ) ? 'modalità reale' : 'modalità di prova';
		if ( 200 === $status ) {
			return array( true, 'Stripe collegato (' . $mode . ').' );
		}
		return array( false, 401 === $status ? 'Stripe ha rifiutato la chiave: controllala.' : 'Stripe non risponde come previsto (codice ' . $status . ').' );
	}

	private static function paypal(): array {
		$id     = (string) Config::get( 'paypal.client_id', '' );
		$secret = (string) Config::get( 'paypal.secret', '' );
		if ( '' === $id || '' === $secret ) {
			return array( false, 'PayPal: mancano Client ID e segreto.' );
		}
		$live = (bool) Config::get( 'paypal.live', false );
		$api  = $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
		list( $status ) = Http::send( 'POST', $api . '/v1/oauth2/token', array( 'Authorization: Basic ' . base64_encode( $id . ':' . $secret ), 'Content-Type: application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
		if ( 200 === $status ) {
			return array( true, 'PayPal collegato (' . ( $live ? 'modalità reale' : 'sandbox di prova' ) . ').' . ( '' === (string) Config::get( 'paypal.webhook_id', '' ) ? ' Manca ancora l\'ID del webhook.' : '' ) );
		}
		return array( false, in_array( $status, array( 400, 401 ), true ) ? 'PayPal ha rifiutato le credenziali: controllale e verifica di aver scelto la modalità giusta (prova o reale).' : 'PayPal non risponde come previsto (codice ' . $status . ').' );
	}

	private static function wega(): array {
		$token = (string) Config::get( 'wega.token', '' );
		if ( '' === $token ) {
			return array( false, 'Server licenze: manca il token.' );
		}
		// Una verifica con una chiave inventata: 401 = token sbagliato; qualsiasi altra risposta dimostra che il token è riconosciuto.
		list( $status ) = Http::send(
			'POST', rtrim( (string) Config::get( 'wega.base_url' ), '/' ) . '/licenses/validate',
			array( 'Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json' ),
			(string) json_encode( array( 'license_key' => 'WEGA-00000000-00000000-00000000-00000000' ) )
		);
		if ( 401 === $status ) {
			return array( false, 'Il server licenze ha rifiutato il token: controllalo.' );
		}
		if ( 0 === $status || $status >= 500 ) {
			return array( false, 'Il server licenze non risponde (codice ' . $status . '): riprova.' );
		}
		return array( true, 'Server licenze collegato: il token è riconosciuto.' );
	}
}
