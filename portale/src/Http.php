<?php
namespace Portale;

/** Trasporto HTTP (curl). Sostituibile nei test con {@see Http::$transport}. */
final class Http {

	/** @var callable|null function (string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
	public static $transport = null;

	/** @return array [codice HTTP, corpo] */
	public static function send( string $method, string $url, array $headers = array(), ?string $body = null ): array {
		if ( null !== self::$transport ) {
			return call_user_func( self::$transport, $method, $url, $headers, $body );
		}
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_FOLLOWLOCATION => false,
		) );
		if ( null !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		}
		$raw    = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return array( $status, false === $raw ? '' : (string) $raw );
	}

	/** Ripete solo su errore di rete, 429 e 5xx, con attesa crescente; i 4xx non si ripetono. */
	public static function withRetry( string $method, string $url, array $headers, ?string $body, int $tries = 3 ): array {
		$last = array( 0, '' );
		for ( $i = 0; $i < $tries; $i++ ) {
			$last = self::send( $method, $url, $headers, $body );
			if ( 0 !== $last[0] && 429 !== $last[0] && $last[0] < 500 ) {
				return $last;
			}
			if ( $i < $tries - 1 && null === self::$transport ) {
				sleep( 1 << $i );
			}
		}
		return $last;
	}
}
