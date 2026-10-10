<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Donations;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Donazioni con PayPal: il modulo [associazionesemplice_donazioni] porta il donatore alla pagina di donazione di PayPal. Solo amministratori. */
final class DonatePage {

	public static function render(): void {
		$s = Settings::all();
		Ui::header( 'Impostazioni: donazioni con PayPal' );
		echo '<p class="description">Il modulo mostra gli importi proposti e un pulsante «Dona con PayPal»: il donatore paga sul sito di PayPal, il sito non tratta nessun dato di pagamento. '
			. 'Le donazioni arrivano direttamente sul tuo conto PayPal e si registrano in prima nota come «Erogazione liberale» (<a href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Nuovo incasso</a>).</p>';
		Ui::form_open( 'asem_save_donate', Ui::url( 'asem-donate' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Raccolta donazioni</th><td><label><input type="checkbox" name="donate_enabled" value="1"' . checked( ! empty( $s['donate_enabled'] ), true, false ) . '> Accendi il modulo di donazione</label>'
			. '<p class="description">Spento di default. Il modulo compare dove inserisci lo shortcode <code>[associazionesemplice_donazioni]</code> (o il blocco/widget «AssociazioneSemplice»).</p></td></tr>';
		echo '<tr><th>Conto PayPal</th><td><input type="text" name="donate_paypal" value="' . esc_attr( (string) $s['donate_paypal'] ) . '" class="regular-text" autocomplete="off">'
			. '<p class="description">L\'email del conto PayPal dell\'associazione, oppure il suo ID commerciante (13 caratteri, lo trovi nel profilo PayPal). Serve un conto Business abilitato a ricevere donazioni.</p></td></tr>';
		echo '<tr><th>Importi proposti</th><td><input type="text" name="donate_amounts" value="' . esc_attr( (string) $s['donate_amounts'] ) . '" class="regular-text">'
			. '<p class="description">In euro, separati da punto e virgola (al massimo 8), ad esempio <code>5;10;20;50</code>. Chi vuole può sempre scegliere un altro importo su PayPal.</p></td></tr>';
		echo '<tr><th>Causale</th><td><input type="text" name="donate_purpose" value="' . esc_attr( (string) $s['donate_purpose'] ) . '" class="regular-text" maxlength="120">'
			. '<p class="description">Il titolo del modulo e il motivo che compare su PayPal. Vuoto = «Donazione a» e il nome dell\'ente.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		if ( Donations::enabled() ) {
			echo '<h2>Anteprima</h2>' . \AssociazioneSemplice\Frontend\Views::donate(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		Ui::footer();
	}
}
