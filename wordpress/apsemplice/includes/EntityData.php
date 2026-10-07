<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati dell'ente (identità, sede, partita IVA) letti da un modulo e controllati: li usano la scheda «Dati e fiscalità»
 * e la configurazione guidata, così le regole sono una sola. Con $full = false si considera solo ciò che il modulo contiene.
 */
final class EntityData {

	/**
	 * @param array $p    dati inviati
	 * @param bool  $full true: il modulo contiene tutti i campi (una casella non spuntata vale «no»); false: solo i campi presenti
	 * @return array valori per Settings::update()
	 * @throws \InvalidArgumentException
	 */
	public static function values( array $p, bool $full ): array {
		$txt  = function ( string $k ) use ( $p ): string {
			return sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
		};
		$has  = function ( string $k ) use ( $p, $full ): bool {
			return $full || array_key_exists( $k, $p );
		};
		$vals = array();

		if ( $has( 'entity_type' ) || $has( 'member_term' ) ) {
			$ent = mb_strtolower( trim( $txt( 'entity_type' ) ), 'UTF-8' );
			$mem = mb_strtolower( trim( $txt( 'member_term' ) ), 'UTF-8' );
			if ( ! isset( Terms::entity_types( (string) Settings::get( 'entity_types_custom' ) )[ $ent ] ) ) {
				throw new \InvalidArgumentException( 'Scegli un tipo di ente dall\'elenco.' );
			}
			if ( ! isset( Terms::member_terms( (string) Settings::get( 'member_terms_custom' ) )[ $mem ] ) ) {
				throw new \InvalidArgumentException( 'Scegli un termine per chi partecipa dall\'elenco.' );
			}
			$vals['entity_type'] = $ent;
			$vals['member_term'] = $mem;
		}
		foreach ( array( 'association_name', 'runts_number', 'legal_address', 'legal_zip', 'legal_city', 'legal_province' ) as $k ) {
			if ( $has( $k ) ) {
				$vals[ $k ] = $txt( $k );
			}
		}
		if ( $has( 'tax_code' ) ) {
			$cf = TaxCode::normalize( $txt( 'tax_code' ) );
			if ( '' !== $cf && ! Fiscal::is_valid_entity_tax_code( $cf ) ) {
				throw new \InvalidArgumentException( 'Il codice fiscale dell\'ente non è valido: controllalo (11 cifre, oppure 16 caratteri se è una persona fisica).' );
			}
			$vals['tax_code'] = $cf;
		}
		if ( $has( 'social_year_start_month' ) ) {
			$vals['social_year_start_month'] = (int) ( $p['social_year_start_month'] ?? 9 );
		}
		if ( $has( 'pec' ) ) {
			$pec = trim( $txt( 'pec' ) );
			if ( '' !== $pec && ! is_email( $pec ) ) {
				throw new \InvalidArgumentException( 'La PEC non è un indirizzo email valido.' );
			}
			$vals['pec'] = $pec;
		}
		if ( $has( 'has_vat' ) ) { // la partita IVA e tutto ciò che ne dipende si legge insieme
			$on = ! empty( $p['has_vat'] );
			$vat = Fiscal::normalize_vat( $txt( 'vat_number' ) );
			if ( $on && ! Fiscal::is_valid_vat( $vat ) ) {
				throw new \InvalidArgumentException( 'La partita IVA non è valida: sono 11 cifre, l\'ultima è di controllo.' );
			}
			$vals['has_vat']             = $on ? 1 : 0;
			$vals['vat_number']          = $on ? $vat : '';
			$vals['fiscal_regime']       = $txt( 'fiscal_regime' );
			$vals['vat_default_rate']    = (int) ( $p['vat_default_rate'] ?? 22 );
			$vals['vat_prices_mode']     = $txt( 'vat_prices_mode' );
			$vals['vat_membership_rate'] = $on ? ( $p['vat_membership_rate'] ?? '' ) : '';
			$vals['sdi_code']            = $txt( 'sdi_code' );
		}
		return $vals;
	}
}
