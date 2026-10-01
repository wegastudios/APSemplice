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
		echo '<h2>Pagine del sito e shortcode</h2><p>Soci e volontari usano il sito, non wp-admin. Le viste si inseriscono con Gutenberg (blocchi <em>APSemplice</em> e <em>Contenuto riservato</em>), con Elementor (widget <em>APSemplice</em> e <em>Contenuto riservato</em>) oppure con questi shortcode:</p>';
		Ui::form_open( 'aps_create_pages', Ui::url( 'aps-settings' ) );
		echo '<p><button class="button">Crea le pagine standard</button> <span class="description">Area soci, Area volontari (visibile solo ai volontari) e Attività ed eventi, con gli shortcode già dentro. Poi le impagini come vuoi.</span></p>';
		Ui::form_close();
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Shortcode</th><th>Cosa mostra</th></tr></thead><tbody>';
		foreach ( \ApSemplice\Frontend\Shortcodes::VIEWS as $slug => $label ) {
			echo '<tr><td><code>[apsemplice_' . esc_html( $slug ) . ']</code></td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '<tr><td><code>[apsemplice_attivita tipo="evento" anno="2025/2026" date="5"]</code></td><td>Filtri: tipo = corso / evento / ricorrente, anno sociale, date da mostrare</td></tr>';
		echo '<tr><td><code>[apsemplice_riservato accesso="soci"]…[/apsemplice_riservato]</code></td><td>Parte di pagina visibile solo ai soci (accesso = soci / volontari / attivita, con attivita="12,13")</td></tr>';
		echo '</tbody></table><p class="description">Per riservare una <strong>pagina o un articolo intero</strong> usa il riquadro «Accesso (APSemplice)» nell\'editor: puoi renderlo visibile ai soli soci, ai volontari o agli iscritti a una o più attività (es. il programma della prima lezione).</p>';
		echo '<h2>Informazioni</h2><p>Per ora il plugin è utilizzabile in amministrazione solo dagli utenti con ruolo Amministratore (capability <code>aps_manage</code>). '
			. 'I soci sono utenti WordPress con ruolo "Socio APS", senza accesso a wp-admin; volontari e soci useranno l\'area riservata, che parla con l\'API REST <code>' . esc_html( rest_url( 'apsemplice/v1' ) ) . '</code>.</p>';
		Ui::footer();
	}
}
