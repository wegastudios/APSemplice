<?php
// Funzioni di aiuto per le pagine (stato e tipo delle licenze, importi, date).

/** Stato mostrabile di una licenza: [testo, classe]. Una licenza «attiva» ma oltre la scadenza risulta scaduta. */
function sub_status( array $s ): array {
	$exp = ! empty( $s['expires_at'] ) ? strtotime( $s['expires_at'] . ' UTC' ) : 0;
	if ( 'canceled' === $s['status'] ) {
		return array( 'Annullato', 'warn' );
	}
	if ( 'merged' === $s['status'] ) {
		return array( 'Rinnovo registrato', '' );
	}
	if ( 'pending' === $s['status'] ) {
		return array( 'In attesa', 'warn' );
	}
	if ( $exp && $exp < time() ) {
		return array( 'Scaduta', 'bad' );
	}
	if ( 'past_due' === $s['status'] ) {
		return array( 'Pagamento non riuscito', 'bad' );
	}
	return array( 'Attiva', '' );
}

/** Tipo di licenza in italiano: gratuita, prova, manuale, ricorrente o singolo. */
function sub_kind( array $s ): string {
	switch ( $s['provider'] ) {
		case 'free':
			return 'Gratuita';
		case 'trial':
			return 'Prova';
		case 'manual':
			return 'Manuale';
	}
	$who = 'stripe' === $s['provider'] ? 'Carta' : 'PayPal';
	return $who . ' · ' . ( 'once' === ( $s['mode'] ?? 'recurring' ) ? 'singolo' : ( 'year' === $s['period'] ? 'annuale ricorrente' : 'mensile ricorrente' ) );
}

function money( $cents ): string {
	$cents = (int) $cents;
	return number_format( $cents / 100, ( $cents % 100 ) ? 2 : 0, ',', '.' ) . ' €';
}

function date_it( ?string $utc ): string {
	return $utc ? date( 'd/m/Y', strtotime( $utc . ' UTC' ) ) : '—';
}
