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
		echo '</tbody></table><h2>Pagamenti online</h2><table class="form-table"><tbody>';
		echo '<tr><th>Come incassare online</th><td><select name="payment_provider" id="aps-pay-provider">' . Ui::options( PaymentConfig::providers(), $s['payment_provider'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">WooCommerce e Stripe/PayPal sono alternative: ne usi una. Per ora è solo la <strong>configurazione</strong>: i pagamenti restano in sede finché non attiviamo l\'integrazione.</p></td></tr>';
		echo self::gateway_row( 'stripe_mode', 'Stripe — modalità', $s, 'select', array( 'test' => 'Prova (test)', 'live' => 'Reale (live)' ), 'aps-pay-stripe' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::gateway_row( 'stripe_publishable_key', 'Stripe — chiave pubblicabile', $s, 'text', array(), 'aps-pay-stripe', 'pk_test_… / pk_live_…' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::secret_row( 'stripe_secret_key', 'Stripe — chiave segreta', 'aps-pay-stripe', 'sk_test_… / sk_live_… (o rk_… con restrizioni)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::secret_row( 'stripe_webhook_secret', 'Stripe — segreto del webhook', 'aps-pay-stripe', 'whsec_… (da Stripe → Sviluppatori → Webhook)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="aps-pay-stripe"><th>Indirizzo del webhook</th><td><code>' . esc_html( rest_url( 'apsemplice/v1/webhooks/stripe' ) ) . '</code><p class="description">Da inserire in Stripe quando attiveremo i pagamenti (evento <code>checkout.session.completed</code>).</p></td></tr>';
		echo self::gateway_row( 'paypal_mode', 'PayPal — modalità', $s, 'select', array( 'sandbox' => 'Prova (sandbox)', 'live' => 'Reale (live)' ), 'aps-pay-paypal' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::gateway_row( 'paypal_client_id', 'PayPal — Client ID', $s, 'text', array(), 'aps-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::secret_row( 'paypal_client_secret', 'PayPal — Client Secret', 'aps-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( PaymentConfig::NONE !== $s['payment_provider'] ) {
			$check = PaymentConfig::validate( Settings::payment_config() );
			foreach ( $check['errors'] as $e ) {
				echo '<tr><th></th><td class="aps-neg">⚠ ' . esc_html( $e ) . '</td></tr>';
			}
			foreach ( $check['warnings'] as $w ) {
				echo '<tr><th></th><td class="aps-warn">ℹ ' . esc_html( $w ) . '</td></tr>';
			}
		}
		echo '<tr><th>Sicurezza delle chiavi</th><td><p class="description">Le chiavi segrete sono salvate <strong>cifrate</strong> nel database e non vengono mai mostrate né scritte nel registro azioni: si inseriscono qui e basta, senza toccare file. '
			. 'La cifratura è legata a questo sito: se copi il database su un altro sito (ad esempio lo staging) le chiavi non vi sono leggibili e vanno reinserite. È voluto: lo staging non può usare per sbaglio le chiavi reali. Usa chiavi di prova finché non sei sicuro.</p></td></tr>';
		foreach ( \ApSemplice\Settings::SECRET_KEYS as $sk ) {
			if ( Settings::secret_unreadable( $sk ) ) {
				echo '<tr><th></th><td class="aps-warn">⚠ Una chiave è salvata ma non è leggibile su questo sito: reinseriscila.</td></tr>';
				break;
			}
		}
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<h2>Prova di connessione</h2><p class="description">Usa le chiavi già salvate (salva prima le impostazioni). Non muove denaro: Stripe legge il saldo, PayPal chiede un token di accesso.</p><div style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( array( PaymentConfig::STRIPE => 'Verifica connessione Stripe', PaymentConfig::PAYPAL => 'Verifica connessione PayPal' ) as $prov => $label ) {
			Ui::form_open( 'aps_test_gateway', Ui::url( 'aps-settings' ) );
			echo Ui::hidden( 'provider', $prov ) . '<button class="button">' . esc_html( $label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '</div>';
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

	/** Riga di impostazione non segreta di un gateway. */
	private static function gateway_row( string $key, string $label, array $s, string $type, array $options, string $row_class, string $placeholder = '' ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		if ( 'select' === $type ) {
			return $head . '<select name="' . esc_attr( $key ) . '">' . Ui::options( $options, $s[ $key ] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $head . '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $s[ $key ] ) . '" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></td></tr>';
	}

	/** Riga di una chiave segreta: non si mostra mai il valore, solo una maschera; vuoto = non cambiare. */
	private static function secret_row( string $key, string $label, string $row_class, string $hint ): string {
		$head = '<tr class="' . esc_attr( $row_class ) . '"><th>' . esc_html( $label ) . '</th><td>';
		$has   = Settings::has_secret( $key );
		$plain = Settings::secret( $key );
		$ph    = $has ? ( '' !== $plain ? Secrets::mask( $plain ) . ' (salvata)' : 'salvata ma non leggibile su questo sito (database copiato da un altro sito?): reinseriscila' ) : '';
		return $head . '<input type="password" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $ph ) . '" autocomplete="new-password" class="regular-text"> '
			. ( $has ? '<label><input type="checkbox" name="clear_' . esc_attr( $key ) . '" value="1"> rimuovi</label>' : '' )
			. '<p class="description">Lascia vuoto per non cambiarla.' . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p></td></tr>';
	}
}
