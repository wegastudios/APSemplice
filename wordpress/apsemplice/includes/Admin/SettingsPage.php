<?php
namespace ApSemplice\Admin;

use ApSemplice\License;
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
		echo '<tr><th>Pagina area riservata</th><td>' . wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput
			array( 'name' => 'member_area_page_id', 'selected' => (int) $s['member_area_page_id'], 'show_option_none' => '— home del sito —', 'option_none_value' => '0', 'echo' => 0 )
		) . '<p class="description">La pagina del sito dove soci e volontari lavorano (la creeremo con uno shortcode). Chi ha solo il ruolo "Socio APS" viene mandato qui al posto di wp-admin.</p></td></tr>';
		$lic = License::status();
		echo '<tr><th>Chiave di licenza</th><td><input type="text" name="license_key" value="' . esc_attr( (string) $s['license_key'] ) . '" class="regular-text" autocomplete="off">'
			. '<p class="description">Una licenza vale per un dominio (<code>' . esc_html( $lic['domain'] ) . '</code>, sottodomini compresi) e per al massimo '
			. (int) $lic['max_installs'] . ' installazioni attive insieme su quel dominio, ad esempio il sito e il suo staging. '
			. ( $lic['local'] ? 'Questo è un ambiente locale: non richiede licenza. ' : '' )
			. esc_html( $lic['note'] ) . '</p><p class="description">ID di questa installazione: <code>' . esc_html( $lic['install_id'] ) . '</code></p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<h2>Informazioni</h2><p>Per ora il plugin è utilizzabile in amministrazione solo dagli utenti con ruolo Amministratore (capability <code>aps_manage</code>). '
			. 'I soci sono utenti WordPress con ruolo "Socio APS", senza accesso a wp-admin; volontari e soci useranno l\'area riservata, che parla con l\'API REST <code>' . esc_html( rest_url( 'apsemplice/v1' ) ) . '</code>.</p>';
		Ui::footer();
	}
}
