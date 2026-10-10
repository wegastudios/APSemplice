<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Fiscal;

defined( 'ABSPATH' ) || exit;

/**
 * Campi dell'IVA nei moduli (aliquota e modo in cui sono scritti gli importi). Fa parte delle funzioni avanzate: l'edizione gratuita non
 * contiene questo file e i moduli non mostrano nulla di fiscale ({@see \AssociazioneSemplice\Edition}, funzione «vat»).
 */
final class VatFields {

	/**
	 * Riga del modulo per l'IVA (solo se l'ente applica l'IVA): aliquota e se l'importo scritto è IVA compresa o esclusa.
	 *
	 * @param ?int   $rate aliquota attuale (null = fuori campo IVA)
	 * @param string $mode importo indicato: 'incl' o 'escl'
	 * @param string $what a cosa si riferisce («Contributo», «Importo»…)
	 */
	public static function row( ?int $rate, string $mode, string $what = 'Gli importi', bool $auto = false ): string {
		if ( ! Fiscal::vat_applies() ) {
			return '';
		}
		$opts = $auto ? array( 'auto' => 'Automatica (dalla quota o dall\'attività)' ) + Fiscal::rate_options() : Fiscal::rate_options();
		return '<tr><th>IVA</th><td><select name="vat_rate">' . Ui::options( $opts, $auto ? 'auto' : ( null === $rate ? 'none' : (string) $rate ) ) . '</select> '
			. '<select name="vat_mode">' . Ui::options( array( Fiscal::INCLUDED => 'Importi scritti: IVA compresa', Fiscal::EXCLUDED => 'Importi scritti: IVA esclusa' ), $mode ) . '</select>'
			. '<p class="description">Aliquota applicata e modo in cui hai scritto gli importi: quello che paga chi partecipa è sempre l\'importo con l\'IVA. «Fuori campo IVA» per i contributi che non sono operazioni commerciali.</p></td></tr>';
	}

	/** Aliquota e modo letti da un modulo con {@see VatFields::row()}: [aliquota|null, 'incl'|'escl']. */
	public static function input( array $p ): array {
		$mode = isset( $p['vat_mode'] ) && Fiscal::EXCLUDED === (string) $p['vat_mode'] ? Fiscal::EXCLUDED : Fiscal::INCLUDED;
		return array( Fiscal::clean_rate( $p['vat_rate'] ?? null ), $mode );
	}
}
