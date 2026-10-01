<?php
namespace ApSemplice\Admin;

use ApSemplice\Money;
use ApSemplice\PaymentItems;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Elenco dei pagamenti online (Stripe / PayPal) con il loro stato e quelli da controllare. */
final class PaymentsPage {

	const STATUS = array(
		'paid' => 'Pagato', 'pending' => 'In attesa di conferma', 'created' => 'Avviato', 'processing' => 'In registrazione',
		'cancelled' => 'Annullato dal socio', 'failed' => 'Non riuscito', 'expired' => 'Scaduto',
	);

	public static function render(): void {
		$status = Ui::get_str( 'status' );
		$review = '1' === Ui::get_str( 'review' );
		$rows   = Plugin::payments()->list( array( 'status' => $status, 'review' => $review ), 200 );
		$svc    = Plugin::payments();

		Ui::header( 'Pagamenti online' );
		if ( ! $svc->enabled() ) {
			echo '<div class="notice notice-info inline"><p>I pagamenti online non sono attivi: scegli Stripe o PayPal e inserisci le chiavi in <a href="' . esc_url( Ui::url( 'aps-settings' ) ) . '">Impostazioni</a>.</p></div>';
		}
		echo '<form method="get" class="aps-filters"><input type="hidden" name="page" value="aps-payments"><select name="status">' . Ui::options( self::STATUS, $status, 'Tutti gli stati' ) . '</select> ' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label><input type="checkbox" name="review" value="1"' . checked( $review, true, false ) . '> Solo da controllare</label> <button class="button">Filtra</button></form>';
		Ui::form_open( 'aps_check_payments', Ui::url( 'aps-payments' ) );
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
				$state = '<span class="aps-ok">' . $state . '</span>';
			} elseif ( in_array( $r['status'], array( 'failed', 'expired' ), true ) ) {
				$state = '<span class="aps-neg">' . $state . '</span>';
			}
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ) . '</td><td>' . esc_html( (string) $r['payer_name'] ) . '</td>'
				. '<td>' . esc_html( 'paypal' === $r['provider'] ? 'PayPal' : 'Stripe' ) . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td><td>' . $state // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $r['review'] ? '<br><strong class="aps-warn">⚠ da controllare</strong>' : '' ) . ( $r['error'] ? '<br><span class="description">' . esc_html( $r['error'] ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( implode( '; ', $items ) ) . '<br><span class="description">' . esc_html( (string) $r['provider_ref'] ) . '</span></td><td>';
			if ( $r['review'] ) {
				Ui::form_open( 'aps_payment_reviewed', Ui::url( 'aps-payments' ) );
				echo Ui::hidden( 'id', $r['id'] ) . '<button class="button button-small">Controllato</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">I pagamenti riusciti sono già in <a href="' . esc_url( Ui::url( 'aps-ledger' ) ) . '">Prima nota</a> sul conto "Stripe" o "PayPal". '
			. 'Le commissioni del gateway e il trasferimento sul conto corrente (payout) si registrano a mano con una spesa e un giroconto. I rimborsi si fanno dal pannello del gateway e si registrano come spesa.</p>';
		Ui::footer();
	}
}
