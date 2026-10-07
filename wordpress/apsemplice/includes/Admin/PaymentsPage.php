<?php
namespace ApSemplice\Admin;

use ApSemplice\Bank;
use ApSemplice\Iban;
use ApSemplice\Money;
use ApSemplice\PaymentConfig;
use ApSemplice\PaymentItems;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Elenco dei pagamenti online (Stripe / PayPal / negozio) con il loro stato e quelli da controllare; impostazioni dei pagamenti e del bonifico. */
final class PaymentsPage {

	const STATUS = array(
		'paid' => 'Pagato', 'pending' => 'In attesa di conferma', 'created' => 'Avviato', 'processing' => 'In registrazione',
		'cancelled' => 'Annullato dal socio', 'failed' => 'Non riuscito', 'expired' => 'Scaduto',
	);

	/** Righe di personalizzazione di un metodo di pagamento: dicitura del pulsante e nota sotto il pulsante. */
	private static function label_rows( string $gateway, string $name, array $s, string $class, string $hint ): string {
		return '<tr class="' . esc_attr( $class ) . '"><th>' . esc_html( $name ) . ' — pulsante</th><td><input type="text" name="pay_label_' . esc_attr( $gateway ) . '" value="' . esc_attr( (string) $s[ 'pay_label_' . $gateway ] ) . '" class="regular-text" maxlength="60" placeholder="' . esc_attr( PaymentConfig::DEFAULT_LABELS[ $gateway ] ) . '"> '
			. '<p class="description">La scritta del pulsante che vedono i soci. Vuoto = «' . esc_html( PaymentConfig::DEFAULT_LABELS[ $gateway ] ) . '».' . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p></td></tr>'
			. '<tr class="' . esc_attr( $class ) . '"><th>' . esc_html( $name ) . ' — nota</th><td><input type="text" name="pay_note_' . esc_attr( $gateway ) . '" value="' . esc_attr( (string) $s[ 'pay_note_' . $gateway ] ) . '" class="large-text" maxlength="300" placeholder="' . esc_attr( PaymentConfig::note( $gateway ) ) . '">'
			. '<p class="description">La frase sotto il pulsante. Vuoto = la frase standard.</p></td></tr>';
	}

	/** Impostazioni dei pagamenti online (spenti di default: "Nessuno"). */
	private static function settings_card(): void {
		$s = \ApSemplice\Settings::all();
		echo '<div class="apse-card"><h2>Come incassare online</h2>';
		Ui::form_open( 'apse_save_payment_settings', Ui::url( 'apse-payments' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Come incassare online</th><td><select name="payment_provider" id="apse-pay-provider">' . Ui::options( PaymentConfig::providers(), $s['payment_provider'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description"><strong>Nessuno</strong> disattiva i pagamenti online: i soci vedono cosa devono e, se lo attivi più sotto, le coordinate per il bonifico. '
			. '<strong>WooCommerce</strong> è alternativo a Stripe e PayPal. <strong>Stripe e PayPal insieme</strong> mostrano due pulsanti, con le diciture che scegli: utile ad esempio per offrire il pagamento a rate di PayPal accanto alla carta. '
			. 'Con Stripe o PayPal i soci pagano su una <strong>pagina ospitata dal gateway</strong> (i dati della carta non passano dal sito) e l\'incasso entra da solo in prima nota sul conto "Stripe" o "PayPal". Prima di usarli in produzione verifica il funzionamento con le chiavi di prova.</p></td></tr>';
		echo self::label_rows( PaymentConfig::STRIPE, 'Stripe', $s, 'apse-pay-stripe', 'Ad esempio «Paga con carta».' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::label_rows( PaymentConfig::PAYPAL, 'PayPal', $s, 'apse-pay-paypal', 'Se il tuo account PayPal offre il pagamento a rate puoi scrivere ad esempio «Paga a rate con PayPal» e spiegare nella nota come funziona.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::label_rows( PaymentConfig::WOOCOMMERCE, 'Negozio', $s, 'apse-pay-woocommerce', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'stripe_mode', 'Stripe — modalità', $s, 'select', array( 'test' => 'Prova (test)', 'live' => 'Reale (live)' ), 'apse-pay-stripe' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'stripe_publishable_key', 'Stripe — chiave pubblicabile', $s, 'text', array(), 'apse-pay-stripe', 'pk_test_… / pk_live_…' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'stripe_secret_key', 'Stripe — chiave segreta', 'apse-pay-stripe', 'sk_test_… / sk_live_… (o rk_… con restrizioni)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'stripe_webhook_secret', 'Stripe — segreto del webhook', 'apse-pay-stripe', 'whsec_… (da Stripe → Sviluppatori → Webhook)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="apse-pay-stripe"><th>Indirizzo del webhook</th><td><code>' . esc_html( rest_url( 'apsemplice/v1/webhooks/stripe' ) ) . '</code><p class="description">In Stripe (Sviluppatori → Webhook) aggiungi questo indirizzo con gli eventi <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> e <code>checkout.session.expired</code>, poi incolla qui il segreto <code>whsec_…</code>. È una rete di sicurezza: il pagamento si conferma anche quando il socio torna sul sito e con un controllo automatico ogni ora.</p></td></tr>';
		echo '<tr class="apse-pay-paypal"><th>Conferma dei pagamenti</th><td><p class="description">PayPal non richiede configurazioni aggiuntive: il pagamento si conferma quando il socio torna sul sito e con un controllo automatico ogni ora.</p></td></tr>';
		echo SettingsPage::gateway_row( 'paypal_mode', 'PayPal — modalità', $s, 'select', array( 'sandbox' => 'Prova (sandbox)', 'live' => 'Reale (live)' ), 'apse-pay-paypal' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'paypal_client_id', 'PayPal — Client ID', $s, 'text', array(), 'apse-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'paypal_client_secret', 'PayPal — Client Secret', 'apse-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( PaymentConfig::NONE !== $s['payment_provider'] ) {
			$check = PaymentConfig::validate( Settings::payment_config() );
			foreach ( $check['errors'] as $e ) {
				echo '<tr><th></th><td class="apse-neg">⚠ ' . esc_html( $e ) . '</td></tr>';
			}
			foreach ( $check['warnings'] as $w ) {
				echo '<tr><th></th><td class="apse-warn">ℹ ' . esc_html( $w ) . '</td></tr>';
			}
		}
		echo '<tr><th>Sicurezza delle chiavi</th><td><p class="description">Le chiavi segrete sono salvate <strong>cifrate</strong> nel database e non vengono mai mostrate né scritte nel registro azioni: si inseriscono qui e basta, senza toccare file. '
			. 'La cifratura è legata a questo sito: se copi il database su un altro sito (ad esempio lo staging) le chiavi non vi sono leggibili e vanno reinserite. Questo impedisce a un sito di prova di usare per errore le chiavi reali. Usa le chiavi di prova finché non hai verificato il funzionamento.</p></td></tr>';
		foreach ( \ApSemplice\Settings::SECRET_KEYS as $sk ) {
			if ( Settings::secret_unreadable( $sk ) ) {
				echo '<tr><th></th><td class="apse-warn">⚠ Una chiave è salvata ma non è leggibile su questo sito: reinseriscila.</td></tr>';
				break;
			}
		}
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<h2>Prova di connessione</h2><p class="description">Usa le chiavi già salvate (salva prima le impostazioni). Non muove denaro: Stripe legge il saldo, PayPal chiede un token di accesso.</p><div style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( array( PaymentConfig::STRIPE => 'Verifica connessione Stripe', PaymentConfig::PAYPAL => 'Verifica connessione PayPal' ) as $prov => $label ) {
			Ui::form_open( 'apse_test_gateway', Ui::url( 'apse-payments' ) );
			echo Ui::hidden( 'provider', $prov ) . '<button class="button">' . esc_html( $label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '</div>';
		echo '</div>';
	}

	/** Bonifico bancario: IBAN mostrati ai soci e inviati per email. Si modificano solo da qui (amministratori); ogni cambio avvisa gli amministratori. */
	private static function bank_card(): void {
		$s    = \ApSemplice\Settings::all();
		$rows = array_values( (array) $s['bank_accounts'] );
		while ( count( $rows ) < 2 ) {
			$rows[] = array();
		}
		if ( count( $rows ) < Bank::MAX_ACCOUNTS && ! empty( $rows[ count( $rows ) - 1 ]['iban'] ) ) {
			$rows[] = array(); // una riga vuota per aggiungere un altro conto
		}
		echo '<div class="apse-card"><h2>Bonifico bancario</h2>';
		echo '<p class="description">Mostra ai soci, nell\'area riservata e accanto a ciò che devono pagare, l\'IBAN dell\'associazione (o gli IBAN, se più di uno) con una causale già pronta, e permette di farselo mandare per email. '
			. 'Funziona da solo (con i pagamenti online disattivati) o insieme a Stripe e PayPal. <strong>Spento di default.</strong> '
			. 'Per mostrare gli IBAN anche a chi non è socio (ad esempio per le donazioni) usa lo shortcode <code>[apsemplice_bonifico]</code>.</p>';
		Ui::form_open( 'apse_save_bank', Ui::url( 'apse-payments' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Bonifico attivo</th><td><label><input type="checkbox" name="bank_enabled" value="1"' . checked( ! empty( $s['bank_enabled'] ), true, false ) . '> Mostra le coordinate ai soci</label></td></tr>';
		echo '<tr><th>Titolo del riquadro</th><td><input type="text" name="bank_title" value="' . esc_attr( (string) $s['bank_title'] ) . '" class="regular-text" maxlength="80" placeholder="' . esc_attr( Bank::DEFAULT_TITLE ) . '"></td></tr>';
		echo '<tr><th>Istruzioni</th><td><textarea name="bank_note" rows="2" class="large-text" maxlength="400" placeholder="' . esc_attr( Bank::DEFAULT_NOTE ) . '">' . esc_textarea( (string) $s['bank_note'] ) . '</textarea><p class="description">Compare sotto le coordinate e nelle email. Vuoto = il testo standard.</p></td></tr>';
		echo '<tr><th>Nei promemoria</th><td><label><input type="checkbox" name="bank_in_reminders" value="1"' . checked( ! empty( $s['bank_in_reminders'] ), true, false ) . '> Aggiungi le coordinate ai promemoria di pagamento (tessera e mensilità dei corsi)</label></td></tr>';
		foreach ( $rows as $i => $a ) {
			echo '<tr><th>Conto ' . (int) ( $i + 1 ) . '</th><td>'
				. '<input type="text" name="bank[' . (int) $i . '][label]" value="' . esc_attr( (string) ( $a['label'] ?? '' ) ) . '" placeholder="Nome (es. Conto principale)" maxlength="60" size="22"> '
				. '<input type="text" name="bank[' . (int) $i . '][holder]" value="' . esc_attr( (string) ( $a['holder'] ?? '' ) ) . '" placeholder="Intestatario" maxlength="120" size="26"><br>'
				. '<input type="text" name="bank[' . (int) $i . '][iban]" value="' . esc_attr( isset( $a['iban'] ) ? Iban::format( (string) $a['iban'] ) : '' ) . '" placeholder="IBAN" maxlength="42" size="34" autocomplete="off"> '
				. '<input type="text" name="bank[' . (int) $i . '][bic]" value="' . esc_attr( (string) ( $a['bic'] ?? '' ) ) . '" placeholder="BIC/SWIFT (facoltativo)" maxlength="11" size="18"> '
				. '<input type="text" name="bank[' . (int) $i . '][bank]" value="' . esc_attr( (string) ( $a['bank'] ?? '' ) ) . '" placeholder="Banca (facoltativo)" maxlength="80" size="22"><br>'
				. '<input type="text" name="bank[' . (int) $i . '][note]" value="' . esc_attr( (string) ( $a['note'] ?? '' ) ) . '" placeholder="Nota (facoltativa, es. «solo per le quote»)" maxlength="200" class="large-text">'
				. '</td></tr>';
		}
		echo '<tr><th>Sicurezza</th><td><p class="description">Le coordinate le modificano solo gli amministratori. Ogni modifica viene scritta nel registro azioni (con l\'IBAN mascherato) e <strong>avvisata per email a tutti gli amministratori</strong>: se un accesso viene rubato e il conto sostituito, ve ne accorgete subito. '
			. 'L\'email con le coordinate parte sempre e solo all\'indirizzo del socio. Lascia vuoto l\'IBAN di un conto per toglierlo.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva le coordinate' );
		Ui::form_close();
		echo '</div>';
	}

	public static function render(): void {
		$status = Ui::get_str( 'status' );
		$review = '1' === Ui::get_str( 'review' );
		$rows   = Plugin::payments()->list( array( 'status' => $status, 'review' => $review ), 200 );
		$svc    = Plugin::payments();

		Ui::header( 'Impostazioni: pagamenti online' );
		self::settings_card();
		self::bank_card();
		echo '<h2>Pagamenti ricevuti</h2>';
		if ( ! $svc->enabled() ) {
			echo '<div class="notice notice-info inline"><p>I pagamenti online non sono attivi: scegli Stripe, PayPal o entrambi e inserisci le chiavi qui sopra. Nel frattempo i soci possono pagare in sede o, se lo attivi, con il bonifico.</p></div>';
		}
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-payments"><select name="status">' . Ui::options( self::STATUS, $status, 'Tutti gli stati' ) . '</select> ' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label><input type="checkbox" name="review" value="1"' . checked( $review, true, false ) . '> Solo da controllare</label> <button class="button">Filtra</button></form>';
		Ui::form_open( 'apse_check_payments', Ui::url( 'apse-payments' ) );
		echo '<p><button class="button">Verifica i pagamenti in sospeso</button> <span class="description">Interroga il gateway sui pagamenti non ancora confermati (lo fa già da solo ogni ora).</span></p>';
		Ui::form_close();

		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Chi ha pagato</th><th>Gateway</th><th>Importo</th><th>Stato</th><th>Voci</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nessun pagamento.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$items = array_map( array( PaymentItems::class, 'line_name' ), (array) json_decode( (string) $r['items'], true ) );
			$state = esc_html( self::STATUS[ $r['status'] ] ?? $r['status'] );
			if ( 'paid' === $r['status'] ) {
				$state = '<span class="apse-ok">' . $state . '</span>';
			} elseif ( in_array( $r['status'], array( 'failed', 'expired' ), true ) ) {
				$state = '<span class="apse-neg">' . $state . '</span>';
			}
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ) . '</td><td>' . esc_html( (string) $r['payer_name'] ) . '</td>'
				. '<td>' . esc_html( array( 'paypal' => 'PayPal', 'woocommerce' => 'WooCommerce' )[ $r['provider'] ] ?? 'Stripe' ) . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td><td>' . $state // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $r['review'] ? '<br><strong class="apse-warn">⚠ da controllare</strong>' : '' ) . ( $r['error'] ? '<br><span class="description">' . esc_html( $r['error'] ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( implode( '; ', $items ) ) . '<br><span class="description">' . esc_html( (string) $r['provider_ref'] ) . '</span></td><td>';
			if ( $r['review'] ) {
				Ui::form_open( 'apse_payment_reviewed', Ui::url( 'apse-payments' ) );
				echo Ui::hidden( 'id', $r['id'] ) . '<button class="button button-small">Controllato</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">I pagamenti riusciti sono già in <a href="' . esc_url( Ui::url( 'apse-ledger' ) ) . '">Prima nota</a> sul conto "Stripe" o "PayPal". '
			. 'Le commissioni del gateway e il trasferimento sul conto corrente (payout) si registrano a mano con una spesa e un giroconto. I rimborsi si fanno dal pannello del gateway e si registrano come spesa. I bonifici arrivati sul conto si registrano a mano da «Nuovo incasso».</p>';
		Ui::footer();
	}
}
