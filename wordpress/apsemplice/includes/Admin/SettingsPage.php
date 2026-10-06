<?php
namespace ApSemplice\Admin;

use ApSemplice\CancelPolicy;
use ApSemplice\License;
use ApSemplice\Money;
use ApSemplice\PaymentConfig;
use ApSemplice\Secrets;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/** Livelli di socio: righe dinamiche (nome, base, quota, attivo), si aggiungono senza limiti. */
	private static function levels_section(): void {
		$rows = array();
		foreach ( \ApSemplice\Levels::all() as $lv ) {
			$rows[] = array( 'id' => (int) $lv['id'], 'name' => $lv['name'], 'base' => $lv['base_type'], 'fee' => null === $lv['fee_cents'] ? '' : Money::plain( (int) $lv['fee_cents'] ), 'active' => (bool) (int) $lv['active'], 'used' => \ApSemplice\Levels::in_use( (int) $lv['id'] ) );
		}
		echo '<h2>Livelli di socio</h2><p class="description">Ogni livello ha il suo nome (come appare sulla tessera e negli elenchi), una base che ne decide il comportamento e, se serve, una quota propria. '
			. 'Lascia vuota la quota per usare quella proposta qui sopra: così puoi avere, ad esempio, soci ordinari, soci ridotti, sostenitori o soci onorari con quote diverse. '
			. 'Un livello con dei soci non si cancella: se lo togli resta, ma non si può più assegnare.</p>';
		Ui::form_open( 'apse_save_levels', Ui::url( 'apse-settings' ) );
		echo '<div class="apse-levels" data-bases="' . esc_attr( wp_json_encode( \ApSemplice\Levels::bases() ) ) . '" data-rows="' . esc_attr( wp_json_encode( $rows ) ) . '"><div class="apse-levels-rows"></div>'
			. '<p><button type="button" class="button" data-add="1">+ Aggiungi livello</button></p></div>';
		echo '<p><button class="button button-primary">Salva i livelli</button></p>';
		Ui::form_close();
	}

	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Impostazioni' );
		Ui::form_open( 'apse_save_settings', Ui::url( 'apse-settings' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Quota associativa proposta</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €</td></tr>';
		echo '<tr><th>Assicurazioni</th><td><label><input type="checkbox" name="insurance_volunteers" value="1"' . checked( ! empty( $s['insurance_volunteers'] ), true, false ) . '> Registro delle assicurazioni dei volontari</label><br>'
			. '<label><input type="checkbox" name="insurance_association" value="1"' . checked( ! empty( $s['insurance_association'] ), true, false ) . '> Polizze dell\'associazione (responsabilità civile, infortuni dei soci)</label>'
			. '<p class="description">Spente di default. Attivandole compare la scheda «Assicurazioni» in Registri, con le scadenze delle polizze e gli avvisi in Bacheca.</p></td></tr>';
		echo '<tr><th>Sconto nucleo familiare</th><td><input type="number" min="0" max="100" name="family_discount_pct" value="' . (int) $s['family_discount_pct'] . '"> %<p class="description">I familiari di un capofamiglia (si sceglie nella scheda del socio) pagano la quota ridotta di questa percentuale; il capofamiglia paga la quota piena. 0 = nessuno sconto.</p></td></tr>';
		echo '<tr><th>Durata tessera socio fondatore</th><td><input type="number" min="1" name="founder_years" value="' . (int) $s['founder_years'] . '"> anni<p class="description">Il socio fondatore ha la tessera sempre rinnovata: la scadenza viene fissata a questo numero di anni dall\'ingresso.</p></td></tr>';
		echo '<tr><th>Ospiti: soglia di segnalazione</th><td><input type="number" min="0" max="20" name="guest_max_events" value="' . (int) $s['guest_max_events'] . '"> partecipazioni<p class="description">Dopo quante partecipazioni a eventi e corsi un non socio viene segnalato come "da invitare a iscriversi" (di solito 1 o 2: oltre, anche per ragioni assicurative, dovrebbe iscriversi). <strong>Non blocca nulla</strong>: evidenzia chi gestisce gli ospiti negli elenchi, nella scheda e all\'ingresso. Le partecipazioni di chi si registra più volte (stesso cellulare, email o nome) si sommano. 0 = nessuna segnalazione.</p></td></tr>';
		echo '<tr><th>Consiglio direttivo</th><td><input type="number" min="0" max="30" name="board_councillors" value="' . (int) $s['board_councillors'] . '"> consiglieri<p class="description">Le cariche sono 1 presidente, 1 vicepresidente e questo numero di consiglieri. Le assegni dalla scheda del socio fondatore o ordinario in regola.</p></td></tr>';
		echo '<tr><th>Pagina area riservata</th><td>' . wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput
			array( 'name' => 'member_area_page_id', 'selected' => (int) $s['member_area_page_id'], 'show_option_none' => '— home del sito —', 'option_none_value' => '0', 'echo' => 0 )
		) . '<p class="description">La pagina del sito dove soci e volontari accedono alla propria area (si crea dalla sezione «Pagine del sito e shortcode» qui sotto). Chi ha solo il ruolo "Socio APS" viene indirizzato qui al posto di wp-admin.</p></td></tr>';
		$lic = License::status();
		echo '<tr><th>Chiave di licenza</th><td><input type="text" name="license_key" value="' . esc_attr( (string) $s['license_key'] ) . '" class="regular-text" autocomplete="off">'
			. '<p class="description">Una licenza vale per un dominio (<code>' . esc_html( $lic['domain'] ) . '</code>, sottodomini compresi) e per al massimo '
			. (int) $lic['max_installs'] . ' installazioni attive insieme su quel dominio, ad esempio il sito e il suo staging. '
			. ( $lic['local'] ? 'Questo è un ambiente locale: non richiede licenza. ' : '' )
			. esc_html( $lic['note'] ) . '</p><p class="description">ID di questa installazione: <code>' . esc_html( $lic['install_id'] ) . '</code></p></td></tr>';
		echo '</tbody></table><h2>Eventi: cancellazioni</h2><table class="form-table"><tbody>';
		echo '<tr><th>Termine predefinito per annullare</th><td><select name="cancel_policy_default">' . Ui::options( CancelPolicy::labels(), $s['cancel_policy_default'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">Vale per gli eventi creati come «cancellabili» senza un termine proprio. Gli eventi gratuiti si annullano sempre; quelli a pagamento mai, ma si può cambiare nominativo.</p></td></tr>';

		echo '</tbody></table><h2>Aspetto e messaggi del sito</h2><table class="form-table"><tbody>';
		echo '<tr><th>Colore d\'accento</th><td><label><input type="checkbox" name="accent_custom" value="1"' . checked( '' !== (string) $s['accent_color'], true, false ) . '> Usa un colore mio</label> '
			. '<input type="color" name="accent_color" value="' . esc_attr( '' !== (string) $s['accent_color'] ? (string) $s['accent_color'] : '#1f6f5c' ) . '">'
			. '<p class="description">Per pulsanti e tessera nelle pagine dei soci. Senza spunta si usa il colore principale del tema.</p></td></tr>';
		echo '<tr><th>Invito al pagamento</th><td><textarea name="payment_hint" rows="2" class="large-text">' . esc_textarea( Settings::payment_hint() ) . '</textarea>'
			. '<p class="description">Mostrato ai soci che hanno importi da pagare (finché i pagamenti online non sono attivi).</p></td></tr>';
		echo '<tr><th>Messaggio sui contenuti riservati</th><td><input type="text" name="gate_message" value="' . esc_attr( (string) $s['gate_message'] ) . '" class="large-text" placeholder="Automatico: «Contenuto riservato ai soci.»">'
			. '<p class="description">Se lo compili sostituisce il messaggio automatico mostrato a chi non può vedere un contenuto riservato.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		self::levels_section();
		echo '<h2>Pagine del sito e shortcode</h2><p>Soci e volontari usano il sito, non wp-admin. Le viste si inseriscono con Gutenberg (blocchi <em>APSemplice</em> e <em>Contenuto riservato</em>), con Elementor (widget <em>APSemplice</em> e <em>Contenuto riservato</em>) oppure con questi shortcode:</p>';
		Ui::form_open( 'apse_create_pages', Ui::url( 'apse-settings' ) );
		echo '<p><button class="button">Crea le pagine standard</button> <span class="description">Area soci, Area volontari (visibile solo ai volontari) e Attività ed eventi, con gli shortcode già inseriti. Puoi poi personalizzarne l\'impaginazione.</span></p>';
		Ui::form_close();
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Shortcode</th><th>Cosa mostra</th></tr></thead><tbody>';
		foreach ( \ApSemplice\Frontend\Shortcodes::VIEWS as $slug => $label ) {
			echo '<tr><td><code>[apsemplice_' . esc_html( $slug ) . ']</code></td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '<tr><td><code>[apsemplice_attivita tipo="evento" anno="2025/2026" date="5"]</code></td><td>Filtri: tipo = corso / evento / ricorrente, anno sociale, date da mostrare</td></tr>';
		echo '<tr><td><code>[apsemplice_riservato accesso="soci"]…[/apsemplice_riservato]</code></td><td>Parte di pagina visibile solo ai soci (accesso = soci / volontari / attivita, con attivita="12,13")</td></tr>';
		echo '</tbody></table><p class="description">Per riservare una <strong>pagina o un articolo intero</strong> usa il riquadro «Accesso (APSemplice)» nell\'editor: puoi renderlo visibile ai soli soci, ai volontari o agli iscritti a una o più attività (es. il programma della prima lezione).</p>';
		echo '<h2>Informazioni</h2><p>L\'amministrazione è riservata agli utenti con ruolo Amministratore (capability <code>apse_manage</code>). '
			. 'I soci sono utenti WordPress con ruolo "Socio APS", senza accesso a wp-admin; soci e volontari usano l\'area riservata, che comunica con l\'API REST <code>' . esc_html( rest_url( 'apsemplice/v1' ) ) . '</code>.</p>';
		Ui::footer();
	}

	/** Riga di impostazione non segreta di un gateway. */
	public static function gateway_row( string $key, string $label, array $s, string $type, array $options, string $row_class, string $placeholder = '' ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		if ( 'select' === $type ) {
			return $head . '<select name="' . esc_attr( $key ) . '">' . Ui::options( $options, $s[ $key ] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $head . '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $s[ $key ] ) . '" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></td></tr>';
	}

	/** Riga di una chiave segreta: non si mostra mai il valore, solo una maschera; vuoto = non cambiare. */
	public static function secret_row( string $key, string $label, string $row_class, string $hint ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		$has   = Settings::has_secret( $key );
		$plain = Settings::secret( $key );
		$ph    = $has ? ( '' !== $plain ? Secrets::mask( $plain ) . ' (salvata)' : 'salvata ma non leggibile su questo sito (database copiato da un altro sito?): reinseriscila' ) : '';
		return $head . '<input type="password" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $ph ) . '" autocomplete="new-password" class="regular-text"> '
			. ( $has ? '<label><input type="checkbox" name="clear_' . esc_attr( $key ) . '" value="1"> rimuovi</label>' : '' )
			. '<p class="description">Lascia vuoto per non cambiarla.' . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p></td></tr>';
	}
}
