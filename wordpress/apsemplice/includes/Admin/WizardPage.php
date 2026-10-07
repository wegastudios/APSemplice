<?php
namespace ApSemplice\Admin;

use ApSemplice\Levels;
use ApSemplice\Money;
use ApSemplice\Pages;
use ApSemplice\PaymentConfig;
use ApSemplice\Settings;
use ApSemplice\Terms;
use ApSemplice\Wizard;
use ApSemplice\WooBridge;
use ApSemplice\WooLinks;

defined( 'ABSPATH' ) || exit;

/** Configurazione guidata: le impostazioni principali in un'unica pagina, applicate insieme. */
final class WizardPage {

	/** Collegamenti ai tutorial: si aprono in una nuova finestra. */
	public static function tutorial_links( string $provider ): string {
		$out = array();
		foreach ( Wizard::tutorials()[ $provider ] ?? array() as $l ) {
			$out[] = '<a href="' . esc_url( $l[1] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $l[0] ) . ' ↗</a>';
		}
		return $out ? '<span class="description apse-wiz-links">Guide: ' . implode( ' · ', $out ) . '</span>' : '';
	}

	private static function product_select( string $name, int $current, array $products ): string {
		$opts = array( '' => $current ? 'Lascia il collegamento attuale' : 'Non collegare adesso', 'new' => 'Crea un prodotto nuovo' );
		foreach ( $products as $id => $label ) {
			$opts[ (string) $id ] = $label;
		}
		return '<select name="' . esc_attr( $name ) . '">' . Ui::options( $opts, '' ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render(): void {
		$s    = Settings::all();
		$woo  = WooBridge::active();
		$euro = $woo && WooBridge::currency_is_euro();
		Ui::header( 'Configurazione guidata' );
		echo '<p>Poche scelte per mettere in funzione il plugin: ente, quote, funzioni, pagamenti e pagine del sito. Puoi saltare ciò che non ti serve e riaprire questa pagina in qualsiasi momento da Impostazioni → Generale. Le funzioni facoltative restano spente se non le scegli.</p>';
		Ui::form_open( 'apse_wizard_save', Ui::url( 'apse-wizard' ) );

		// 1. Ente
		$ents = array_keys( Terms::entity_types( (string) $s['entity_types_custom'] ) );
		$mems = array_keys( Terms::member_terms( (string) $s['member_terms_custom'] ) );
		echo '<h2>1. Il tuo ente</h2><table class="form-table"><tbody>';
		echo '<tr><th>Tipo di ente</th><td><select name="entity_type">' . Ui::options( array_combine( $ents, $ents ), (string) $s['entity_type'] ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <select name="member_term">' . Ui::options( array_combine( $mems, $mems ), (string) $s['member_term'] ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Tipo di ente e termine per chi partecipa (socio, iscritto, sostenitore…): tutti i testi si adattano da soli.</p></td></tr>';
		echo '<tr><th>Denominazione</th><td><input type="text" name="association_name" value="' . esc_attr( (string) $s['association_name'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Codice fiscale dell\'ente</th><td><input type="text" name="tax_code" value="' . esc_attr( (string) $s['tax_code'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>L\'anno sociale inizia a</th><td><select name="social_year_start_month">' . Ui::options( Ui::MONTHS, (int) $s['social_year_start_month'] ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';

		// 2. Quote
		echo '<h2>2. Quote</h2><table class="form-table"><tbody>';
		echo '<tr><th>Quota associativa proposta</th><td><input type="text" name="membership_fee" value="' . esc_attr( Money::plain( (int) $s['membership_fee_cents'] ) ) . '" inputmode="decimal"> €<p class="description">Vale per tutti i livelli che non hanno una quota propria. I livelli di socio (ordinari, ridotti, sostenitori…) si definiscono poi in Impostazioni → Generale.</p></td></tr>';
		echo '<tr><th>Sconto nucleo familiare</th><td><input type="number" min="0" max="100" name="family_discount_pct" value="' . (int) $s['family_discount_pct'] . '"> %<p class="description">0 = nessuno sconto.</p></td></tr>';
		echo '</tbody></table>';

		// 3. Funzioni
		echo '<h2>3. Funzioni facoltative</h2><p class="description">Tutte spente di default: accendi solo quelle che ti servono. App e notifiche hanno la loro scheda.</p>';
		echo '<input type="hidden" name="features_present" value="1">';
		foreach ( SettingsPage::FEATURES as $k => $label ) {
			if ( in_array( $k, array( 'pwa_enabled', 'push_enabled' ), true ) ) {
				continue;
			}
			echo '<label><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}

		// 4. Pagamenti
		echo '<h2>4. Pagamenti dei soci</h2><p class="description">Come i soci versano quote e contributi dal sito. Puoi cambiare idea in qualsiasi momento da Pagamenti online.</p>';
		$current = (string) $s['payment_provider'];
		foreach ( Wizard::payment_choices() as $val => $c ) {
			$off  = PaymentConfig::WOOCOMMERCE === $val && ! $euro;
			$note = '';
			if ( PaymentConfig::WOOCOMMERCE === $val ) {
				$note = ! $woo ? ' <em>WooCommerce non è stato rilevato su questo sito: installalo e attivalo per usare questa opzione.</em>' : ( ! $euro ? ' <em>WooCommerce è attivo, ma il negozio non usa l\'euro: non è utilizzabile.</em>' : ' <strong>WooCommerce è attivo su questo sito.</strong>' );
			}
			$providers = array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL, PaymentConfig::WOOCOMMERCE );
			$links     = '';
			foreach ( PaymentConfig::BOTH === $val ? array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ) : ( in_array( $val, $providers, true ) ? array( $val ) : array() ) as $pv ) {
				$links .= ' ' . self::tutorial_links( $pv );
			}
			echo '<p><label><input type="radio" name="payment_choice" value="' . esc_attr( $val ) . '"' . checked( $current === ( 'none' === $val ? PaymentConfig::NONE : $val ), true, false ) . disabled( $off, true, false ) . '> <strong>' . esc_html( $c[0] ) . '</strong></label><br><span class="description">' . esc_html( $c[1] ) . '</span>' . $note . $links . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><label><input type="checkbox" name="bank_enabled" value="1"' . checked( ! empty( $s['bank_enabled'] ), true, false ) . '> Mostra anche le coordinate bancarie (bonifico)</label><br><span class="description">Gli IBAN si inseriscono subito dopo, in Pagamenti online. Il bonifico può affiancare o sostituire i pagamenti online.</span></p>';

		// 4b. Prodotti WooCommerce
		if ( $euro ) {
			$products = WooBridge::products();
			$map      = WooLinks::map();
			echo '<div class="apse-wiz-woo"><h3>Prodotti di WooCommerce</h3><p class="description">Vale se scegli il checkout di WooCommerce: ogni quota deve corrispondere a un prodotto del negozio. Puoi collegarne uno esistente oppure crearlo ora (prodotto virtuale, non visibile in vetrina; il prezzo applicato lo calcola il plugin).</p><table class="form-table"><tbody>';
			foreach ( Levels::all( true ) as $lv ) {
				$cur = (int) ( $map[ 'level:' . (int) $lv['id'] ] ?? 0 );
				echo '<tr><th>Quota «' . esc_html( (string) $lv['name'] ) . '»</th><td>' . self::product_select( 'product_level[' . (int) $lv['id'] . ']', $cur, $products ) . ( $cur ? ' <span class="description">già collegata</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$def = (int) $s['woo_default_product'];
			echo '<tr><th>Altre voci (corsi, eventi…)</th><td>' . self::product_select( 'product_default', $def, $products ) . ( $def ? ' <span class="description">già impostato</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tbody></table></div>';
		}

		// 5. Pagine
		$have = Pages::existing();
		echo '<h2>5. Pagine del sito</h2><p class="description">Le pagine con gli shortcode già inseriti; potrai poi personalizzarne l\'impaginazione. Quelle già create non si duplicano.</p>';
		echo '<input type="hidden" name="pages_present" value="1">';
		foreach ( Pages::defs() as $key => $d ) {
			echo '<label><input type="checkbox" name="pages[]" value="' . esc_attr( $key ) . '"' . checked( isset( $have[ $key ] ) || ! empty( $d['default'] ), true, false ) . disabled( isset( $have[ $key ] ), true, false ) . '> <strong>' . esc_html( $d['title'] ) . '</strong>'
				. ( isset( $have[ $key ] ) ? ' <em>(già creata)</em>' : '' ) . '</label><br><span class="description" style="margin-left:24px">' . esc_html( $d['hint'] ) . '</span><br>';
		}

		echo '<p class="submit"><button class="button button-primary button-hero">Applica la configurazione</button></p>';
		Ui::form_close();
		Ui::form_open( 'apse_wizard_skip', Ui::url( 'apse' ), false, 'apse-inline' );
		echo '<p><button class="button-link">Salta per ora</button></p>';
		Ui::form_close();
		Ui::footer();
	}
}
