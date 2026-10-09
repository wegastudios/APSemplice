<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Punto unico da cui passano i testi che il plugin mostra o invia (pagine, email, PDF, messaggi).
 *
 * Nell'edizione gratuita i testi sono quelli originali. La personalizzazione dei testi (sostituzioni, lingue, tipo di ente e termini) è una
 * funzione di APSemplice Pro: quando c'è e la licenza è in regola il lavoro lo fa {@see TextsEngine}, altrimenti i testi passano invariati.
 */
final class Texts {

	/** La personalizzazione dei testi è disponibile? */
	public static function enabled(): bool {
		return Edition::has( 'texts' );
	}

	/** Testo semplice (email, PDF, messaggi) con le personalizzazioni, se ci sono. */
	public static function plain( string $s, ?array $map = null ): string {
		return self::enabled() ? TextsEngine::plain( $s, $map ) : $s;
	}

	/** HTML con le personalizzazioni (solo il testo, mai tag, attributi tecnici o indirizzi). */
	public static function html( string $html, ?array $map = null ): string {
		return self::enabled() ? TextsEngine::html( $html, $map ) : $html;
	}

	/** Dopo un salvataggio o un test: rilegge dal database. */
	public static function flush(): void {
		if ( Edition::installed( 'texts' ) ) {
			TextsEngine::flush();
			Terms::flush();
		}
	}

	/** Amministrazione: la pagina passa dalla sostituzione dei testi (solo con la funzione presente). */
	public static function start_admin_buffer(): void {
		if ( self::enabled() ) {
			TextsEngine::start_admin_buffer();
		}
	}

	/** wp_mail con i testi personalizzati (oggetto e messaggio). */
	public static function mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
		return (bool) wp_mail( $to, self::plain( $subject ), self::plain( $message ), $headers, $attachments );
	}
}
