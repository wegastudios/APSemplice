<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Contributi: quanto paga una persona e in che stato è il pagamento di una prenotazione. */
final class Pricing {

	const FREE    = 'free';     // gratuito
	const PAID    = 'paid';
	const PARTIAL = 'partial';
	const UNPAID  = 'unpaid';

	/**
	 * Contributo dovuto da una persona. Gli ospiti pagano il contributo ospiti se è stato impostato
	 * (anche 0 = gratuito per gli ospiti), altrimenti lo stesso dei soci.
	 */
	public static function fee_for( int $fee_cents, ?int $guest_fee_cents, string $person_type ): int {
		if ( MemberType::GUEST === $person_type && null !== $guest_fee_cents ) {
			return max( 0, $guest_fee_cents );
		}
		return max( 0, $fee_cents );
	}

	public static function booking_state( int $due, int $paid ): string {
		if ( $due <= 0 ) {
			return self::FREE;
		}
		if ( $paid >= $due ) {
			return self::PAID;
		}
		return $paid > 0 ? self::PARTIAL : self::UNPAID;
	}

	/** Quanto resta da pagare (mai negativo). */
	public static function remaining( int $due, int $paid ): int {
		return max( 0, $due - $paid );
	}
}
