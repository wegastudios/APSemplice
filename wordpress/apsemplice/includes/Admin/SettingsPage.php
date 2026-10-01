<?php
namespace ApSemplice\Admin;

use ApSemplice\Money;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Impostazioni' );
		Ui::form_open( 'aps_save_settings', Ui::url( 'aps-settings' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Quota associativa proposta</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €</td></tr>';
		echo '<tr><th>Durata tessera socio fondatore</th><td><input type="number" min="1" name="founder_years" value="' . (int) $s['founder_years'] . '"> anni<p class="description">Il socio fondatore ha la tessera sempre rinnovata: la scadenza viene fissata a questo numero di anni dall\'ingresso.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<h2>Informazioni</h2><p>Per ora il plugin è utilizzabile solo dagli utenti con ruolo Amministratore (capability <code>aps_manage</code>). I soci sono utenti WordPress con ruolo "Socio APS", senza accesso all\'area di amministrazione.</p>';
		Ui::footer();
	}
}
