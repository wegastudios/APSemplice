<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Raccolta di donazioni con PayPal. Il sito non parla con PayPal: mostra un modulo che porta il donatore alla pagina di donazione di
 * PayPal (paypal.com/donate) già compilata con il conto dell'ente, la causale e l'importo scelto. Il pagamento avviene tutto su PayPal.
 * Le donazioni ricevute si registrano in prima nota come «Erogazione liberale».
 */
final class Donations {

	const URL         = 'https://www.paypal.com/donate';
	const MAX_AMOUNTS = 8;
	const MAX_CENTS   = 1000000; // 10.000 € per importo proposto

	/** Email PayPal oppure ID commerciante (13 lettere e cifre maiuscole). Altro => vuoto. */
	public static function clean_account( string $v ): string {
		$v = trim( $v );
		if ( preg_match( '/^[A-Z0-9]{13}$/', $v ) ) {
			return $v;
		}
		return is_email( $v ) ? strtolower( mb_substr( $v, 0, 190 ) ) : '';
	}

	/** «5; 10 20;7,50» => «5;10;20;7,50» (euro interi o con i decimali, in ordine, senza doppioni, al massimo 8). */
	public static function clean_amounts( string $v ): string {
		$out = array();
		foreach ( preg_split( '/[;\s]+/', trim( $v ) ) ?: array() as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$cents = Money::parse( $p );
			if ( null === $cents || $cents <= 0 || $cents > self::MAX_CENTS ) {
				continue;
			}
			$out[ $cents ] = Money::plain( $cents );
		}
		ksort( $out );
		return implode( ';', array_slice( array_values( $out ), 0, self::MAX_AMOUNTS ) );
	}

	/** @return string[] importi proposti, in euro, come testo («5», «7,50») */
	public static function amounts(): array {
		$s = self::clean_amounts( (string) Settings::get( 'donate_amounts' ) );
		return '' === $s ? array() : explode( ';', $s );
	}

	public static function account(): string {
		return self::clean_account( (string) Settings::get( 'donate_paypal' ) );
	}

	public static function enabled(): bool {
		return ! empty( Settings::get( 'donate_enabled' ) ) && '' !== self::account();
	}

	/** Causale: quella scelta, altrimenti «Donazione a <ente>». */
	public static function purpose(): string {
		$p = trim( (string) Settings::get( 'donate_purpose' ) );
		if ( '' !== $p ) {
			return $p;
		}
		$name = trim( (string) Settings::get( 'association_name' ) );
		return '' !== $name ? 'Donazione a ' . $name : 'Donazione';
	}

	/** Importo nel formato di PayPal (punto decimale) a partire da quello scritto in euro. */
	public static function paypal_amount( string $euro ): string {
		$cents = Money::parse( $euro );
		return null === $cents || $cents <= 0 ? '' : number_format( $cents / 100, 2, '.', '' );
	}
}
