<?php
namespace ApSemplice\Admin;

use ApSemplice\Fiscal;
use ApSemplice\Settings;
use ApSemplice\Terms;

defined( 'ABSPATH' ) || exit;

/** Dati dell'ente e fiscalità: denominazione, sede, codice fiscale, partita IVA e regime. */
final class EntityPage {

	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Dati dell\'ente e fiscalità' );
		Ui::form_open( 'apse_save_entity', Ui::url( 'apse-entity' ) );
		$ents = array_keys( Terms::entity_types( (string) $s['entity_types_custom'] ) );
		$mems = array_keys( Terms::member_terms( (string) $s['member_terms_custom'] ) );
		echo '<h2>Ente</h2><table class="form-table"><tbody>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Tipo di ente</th><td><select name="entity_type">' . Ui::options( array_combine( $ents, $ents ), (string) $s['entity_type'] ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<select name="member_term">' . Ui::options( array_combine( $mems, $mems ), (string) $s['member_term'] ) . '</select><p class="description">Tipo di ente e termine per chi partecipa: tutti i testi si adattano. I tipi e i termini aggiuntivi si scrivono in Testi e lingua.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Iscrizione (RUNTS, registro…)</th><td><input type="text" name="runts_number" value="' . esc_attr( (string) $s['runts_number'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';

		echo '<h2>Sede e contatti</h2><table class="form-table"><tbody>';
		echo '<tr><th>Indirizzo</th><td><input type="text" name="legal_address" value="' . esc_attr( (string) $s['legal_address'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>CAP, comune, provincia</th><td><input type="text" name="legal_zip" value="' . esc_attr( (string) $s['legal_zip'] ) . '" size="6" placeholder="CAP"> <input type="text" name="legal_city" value="' . esc_attr( (string) $s['legal_city'] ) . '" placeholder="Comune"> <input type="text" name="legal_province" value="' . esc_attr( (string) $s['legal_province'] ) . '" size="4" placeholder="Prov."></td></tr>';
		echo '<tr><th>PEC</th><td><input type="email" name="pec" value="' . esc_attr( (string) $s['pec'] ) . '" class="regular-text"></td></tr>';
		echo '</tbody></table>';

		echo '<h2>Partita IVA</h2><table class="form-table"><tbody>';
		echo '<tr><th>L\'ente ha la partita IVA</th><td><label><input type="checkbox" name="has_vat" value="1" id="apse-has-vat"' . checked( ! empty( $s['has_vat'] ), true, false ) . '> Sì</label>'
			. '<p class="description">Senza partita IVA non compare nulla di fiscale: niente aliquote né importi IVA. Con la partita IVA, quote, attività, incassi e spese indicano se l\'importo è IVA compresa o esclusa e l\'aliquota. La gestione è volutamente essenziale: i quadri e le liquidazioni li completa il commercialista.</p></td></tr>';
		echo '</tbody></table><div id="apse-vat-block"><table class="form-table"><tbody>';
		echo '<tr><th>Partita IVA</th><td><input type="text" name="vat_number" value="' . esc_attr( (string) $s['vat_number'] ) . '" class="regular-text" maxlength="13" placeholder="11 cifre"></td></tr>';
		echo '<tr><th>Regime</th><td><select name="fiscal_regime">' . Ui::options( Fiscal::regimes(), (string) $s['fiscal_regime'] ) . '</select><p class="description">Nel regime forfettario l\'IVA non si applica: le voci restano senza aliquota.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$rates = array();
		foreach ( Fiscal::RATES as $r ) {
			$rates[ $r ] = $r . '%';
		}
		echo '<tr><th>Aliquota proposta</th><td><select name="vat_default_rate">' . Ui::options( $rates, (int) $s['vat_default_rate'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Gli importi si inseriscono</th><td><select name="vat_prices_mode">' . Ui::options( array( Fiscal::INCLUDED => 'IVA compresa', Fiscal::EXCLUDED => 'IVA esclusa' ), (string) $s['vat_prices_mode'] ) . '</select><p class="description">Vale come scelta iniziale: su ogni quota, attività, incasso e spesa si può indicare diversamente.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Codice destinatario (SDI)</th><td><input type="text" name="sdi_code" value="' . esc_attr( (string) $s['sdi_code'] ) . '" size="9" maxlength="7"><p class="description">Facoltativo: serve se emetti fatture elettroniche.</p></td></tr>';
		echo '</tbody></table></div>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<script>(function(){var c=document.getElementById("apse-has-vat"),b=document.getElementById("apse-vat-block");if(!c||!b)return;function s(){b.style.display=c.checked?"":"none";}c.addEventListener("change",s);s();})();</script>';
		Ui::footer();
	}
}
