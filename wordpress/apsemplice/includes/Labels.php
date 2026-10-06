<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Etichette e classificazioni usate da contabilità e report. */
final class Labels {

	public static function account_types(): array {
		return array( 'cash' => 'Cassa contanti', 'bank' => 'Conto corrente', 'pos' => 'Conto POS', 'other' => 'Altro' );
	}

	/** Modalità di pagamento che deriva dal tipo di conto. */
	public static function method_for_account( string $type ): string {
		return array( 'cash' => 'cash', 'bank' => 'bank_transfer', 'pos' => 'pos' )[ $type ] ?? 'other';
	}

	public static function methods(): array {
		return array( 'cash' => 'Contanti', 'bank_transfer' => 'Bonifico', 'pos' => 'POS / carta', 'check' => 'Assegno', 'stripe' => 'Carta online (Stripe)', 'paypal' => 'PayPal', 'woocommerce' => 'Negozio online (WooCommerce)', 'other' => 'Altro' );
	}

	/** kind => [etichetta, è un'entrata, è un'uscita] */
	public static function category_kinds(): array {
		return array(
			'membership'               => array( 'Quota associativa', true, false ),
			'activity_fee'             => array( 'Quota attività / corso', true, false ),
			'donation'                 => array( 'Erogazione liberale', true, false ),
			'other_income'             => array( 'Altra entrata', true, false ),
			'member_reimbursement'     => array( 'Rimborso spese socio/volontario', false, true ),
			'activity_cost'            => array( 'Costo attività', false, true ),
			'general_cost'             => array( 'Costo generale', false, true ),
			'adjustment'               => array( 'Rettifica di cassa', true, true ),
		);
	}

	public static function tx_types(): array {
		return array( 'income' => 'Entrata', 'expense' => 'Uscita', 'transfer_in' => 'Giroconto in entrata', 'transfer_out' => 'Giroconto in uscita' );
	}

	public static function is_transfer( string $type ): bool {
		return 'transfer_in' === $type || 'transfer_out' === $type;
	}

	/** +1 per le righe che aumentano il saldo del conto, -1 per quelle che lo diminuiscono. */
	public static function sign( string $type ): int {
		return ( 'income' === $type || 'transfer_in' === $type ) ? 1 : -1;
	}
}
