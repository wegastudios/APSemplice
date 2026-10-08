<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Messaggi di esito ("Spesa registrata", "Posti esauriti"…) che viaggiano nell'indirizzo dopo un'azione. Sono FIRMATI: una pagina
 * mostra solo i messaggi scritti dal sito stesso, mai un testo qualunque messo da qualcuno in un link (altrimenti un link come
 * `?apsf_err=Il tuo conto è stato sospeso, chiama il…` mostrerebbe quel testo come se fosse un avviso ufficiale).
 */
final class Flash {

	/** Firma di un messaggio: lega il testo al sito (e al tipo, ok o errore). */
	public static function sign( string $kind, string $msg ): string {
		return substr( hash_hmac( 'sha256', 'flash|' . $kind . '|' . $msg, wp_salt( 'auth' ) ), 0, 24 );
	}

	/** Indirizzo con il messaggio (errore se c'è, altrimenti esito positivo). $prefix: "apsf" (area soci) o "apse" (amministrazione). */
	public static function url( string $url, string $prefix, string $ok = '', string $err = '' ): string {
		$url  = remove_query_arg( array( $prefix . '_ok', $prefix . '_err', $prefix . '_sig' ), $url );
		$kind = '' !== $err ? 'err' : ( '' !== $ok ? 'ok' : '' );
		if ( '' === $kind ) {
			return $url;
		}
		$msg = str_replace( "'", '’', Texts::plain( 'err' === $kind ? $err : $ok ) ); // testi personalizzati; l'apostrofo dritto verrebbe tolto dal reindirizzamento e la firma non tornerebbe: si usa quello tipografico
		return add_query_arg( array( $prefix . '_' . $kind => $msg, $prefix . '_sig' => self::sign( $kind, $msg ) ), $url );
	}

	/** Messaggio da mostrare (firma valida) oppure stringhe vuote. @return array{ok:string,err:string} */
	public static function read( string $prefix ): array {
		$out = array( 'ok' => '', 'err' => '' );
		$sig = isset( $_GET[ $prefix . '_sig' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_sig' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( array( 'err', 'ok' ) as $kind ) {
			$msg = isset( $_GET[ $prefix . '_' . $kind ] ) ? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_' . $kind ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $msg && '' !== $sig && hash_equals( self::sign( $kind, $msg ), $sig ) ) {
				$out[ $kind ] = $msg;
				break;
			}
		}
		return $out;
	}
}
