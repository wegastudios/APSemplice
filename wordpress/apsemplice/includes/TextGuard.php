<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Riconosce nei messaggi i contenuti con cui di solito si dirottano i pagamenti: indirizzi web e coordinate bancarie (IBAN).
 * Logica pura: serve a limitare ciò che possono scrivere i volontari negli avvisi (vedi Limits «notice_links»).
 */
final class TextGuard {

	/** Indirizzi web (con o senza https://) e collegamenti brevi comuni. */
	public static function has_link( string $s ): bool {
		return (bool) preg_match( '#(?:https?://|ftp://|www\.|\bt\.me/|\bwa\.me/)#i', $s )
			|| (bool) preg_match( '#\b[a-z0-9][a-z0-9\-]*\.(?:com|it|net|org|eu|info|biz|io|co|me|ly|gl|tk|xyz|top|click|link|app|site|online|shop|store|pay|page|cc|us|uk|de|fr|es|ru|cn)(?:/|\b)#i', $s );
	}

	/** IBAN scritti di seguito o a gruppi di quattro (ad esempio «IT60 X054 2811 1010 0000 0123 456»). */
	public static function has_iban( string $s ): bool {
		return (bool) preg_match( '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?\b/i', $s );
	}

	public static function has_payment_hint( string $s ): bool {
		return self::has_link( $s ) || self::has_iban( $s );
	}
}
