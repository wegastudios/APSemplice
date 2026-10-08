<?php
namespace ApSemplice\Admin;

use ApSemplice\Push;
use ApSemplice\Pwa;
use ApSemplice\Settings;
use ApSemplice\WebPush;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Tecniche → App e notifiche. */
final class AppPage {

	private static function check( bool $ok, string $text, string $fix = '' ): string {
		return '<li>' . ( $ok ? '✅' : '⚠️' ) . ' ' . esc_html( $text ) . ( ! $ok && '' !== $fix ? ' <span class="description">' . esc_html( $fix ) . '</span>' : '' ) . '</li>';
	}

	public static function render(): void {
		Ui::header( 'App e notifiche' );
		$on   = Pwa::enabled();
		$push = (bool) Settings::get( 'push_enabled' );
		echo '<p class="description">Trasforma l\'area soci in un\'app installabile sul telefono (PWA): l\'icona sulla schermata Home, l\'apertura a tutto schermo e, se vuoi, le <strong>notifiche push</strong> '
			. 'per avvisi dei corsi, promemoria, comunicazioni e posti liberati. Le email continuano a partire come sempre. Spenta di default.</p>';
		Ui::form_open( 'apse_save_app', Ui::url( 'apse-app' ), true );
		echo '<p><label><input type="checkbox" name="pwa_enabled" value="1"' . checked( $on, true, false ) . '> <strong>App installabile (PWA)</strong></label><br>'
			. '<label><input type="checkbox" name="push_enabled" value="1"' . checked( $push, true, false ) . '> <strong>Notifiche push</strong></label> <span class="description">(servono l\'app accesa e una connessione https)</span></p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Nome dell\'app</th><td><input type="text" name="pwa_name" value="' . esc_attr( (string) Settings::get( 'pwa_name' ) ) . '" class="regular-text" maxlength="45" placeholder="' . esc_attr( Pwa::app_name() ) . '"> '
			. 'breve: <input type="text" name="pwa_short_name" value="' . esc_attr( (string) Settings::get( 'pwa_short_name' ) ) . '" size="12" maxlength="12" placeholder="' . esc_attr( Pwa::short_name() ) . '"><p class="description">Vuoto = denominazione dell\'associazione. Il nome breve sta sotto l\'icona.</p></td></tr>';
		$icon = (int) Settings::get( 'pwa_icon_id' );
		echo '<tr><th>Icona</th><td>' . ( $icon ? '<img src="' . esc_url( Pwa::icon_url( 192 ) ) . '" alt="" width="64" height="64" style="vertical-align:middle;border-radius:12px"> <label><input type="checkbox" name="pwa_icon_remove" value="1"> togli</label><br>' : '' )
			. '<input type="file" name="pwa_icon" accept="image/png"><p class="description">PNG quadrato, almeno 192 pixel (meglio 512×512). Senza icona si usa l\'icona del sito o un quadrato del colore d\'accento.</p></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();

		echo '<h2>Controlli</h2><ul>';
		echo self::check( is_ssl() || 0 === strpos( home_url(), 'https://' ), 'Il sito è in https', 'L\'app e le notifiche funzionano solo con https (fa eccezione localhost).' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
		echo self::check( '' !== (string) get_option( 'permalink_structure' ), 'I permalink sono "carini"', 'Con i permalink semplici gli indirizzi dell\'app (manifest e service worker) potrebbero non essere raggiungibili: scegli un altro formato in Impostazioni → Permalink.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
		echo self::check( WebPush::supported(), 'Questo server sa cifrare le notifiche (openssl)', 'Senza openssl le notifiche non sono disponibili; l\'app installabile funziona lo stesso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
		echo '</ul>';
		if ( $on ) {
			echo '<p>Indirizzi: <a target="_blank" href="' . esc_url( Pwa::url( 'apse-manifest.webmanifest' ) ) . '">manifest</a> · <a target="_blank" href="' . esc_url( Pwa::url( 'apse-sw.js' ) ) . '">service worker</a></p>';
		}
		if ( Push::enabled() ) {
			echo '<h2>Notifiche</h2><p>Dispositivi con le notifiche attive: <strong>' . (int) Push::count() . '</strong>.</p>';
			Ui::form_open( 'apse_push_test', Ui::url( 'apse-app' ), false, 'apse-inline' );
			echo '<button class="button">Invia una notifica di prova al mio utente</button> <span class="description">Prima apri l\'area soci dal telefono, con questo stesso utente, e attiva le notifiche.</span>';
			Ui::form_close();
			echo '<p class="description">Le notifiche partono insieme alle email: comunicazioni a gruppi, avvisi dei volontari, promemoria e posti liberati dalla lista d\'attesa. Chi non ha attivato le notifiche riceve solo l\'email.</p>';
			Ui::form_open( 'apse_push_reset', Ui::url( 'apse-app' ), false, 'apse-inline' );
			echo '<button class="button button-link-delete" data-confirm="Rigenerare le chiavi? Tutti i dispositivi dovranno riattivare le notifiche.">Rigenera le chiavi del sito</button> <span class="description">Solo se sospetti che siano state esposte.</span>';
			Ui::form_close();
		}
		echo '<h2>Come si usa</h2><ol><li>Accendi l\'app qui sopra e inserisci la sezione «App e notifiche» nell\'area soci (è già tra le sezioni standard, oppure con lo shortcode <code>[apsemplice_app]</code>).</li>'
			. '<li>Dal telefono i soci aprono l\'area soci: compare «Installa l\'app» (su iPhone: Condividi → Aggiungi alla schermata Home).</li>'
			. '<li>Se hai acceso le notifiche, dall\'app attivano le notifiche con un tocco. Su iPhone servono iOS 16.4 o successivo e l\'app aggiunta alla Home.</li></ol>';
		Ui::footer();
	}
}
