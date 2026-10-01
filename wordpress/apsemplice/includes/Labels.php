<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APS_TESTS' ) || exit;

/** Etichette e classificazioni usate da contabilità e report. */
final class Labels {

	public static function account_types(): array {
		return array( 'cash' => 'Cassa contanti', 'bank' => 'Conto corrente', 'pos' => 'Conto POS', 'other' => 'Altro' );
	}

	public static function methods(): array {
		return array( 'cash' => 'Contanti', 'bank_transfer' => 'Bonifico', 'pos' => 'POS / carta', 'check' => 'Assegno', 'other' => 'Altro' );
	}

	/** kind => [etichetta, è un'entrata, è un'uscita] */
	public static function category_kinds(): array {
		return array(
			'membership'               => array( 'Quota associativa', true, false ),
			'activity_fee'             => array( 'Quota attività / corso', true, false ),
			'donation'                 => array( 'Erogazione liberale', true, false ),
			'other_income'             => array( 'Altra entrata', true, false ),
			'instructor_reimbursement' => array( 'Compenso / rimborso istruttore', false, true ),
			'member_reimbursement'     => array( 'Rimborso spese socio', false, true ),
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
