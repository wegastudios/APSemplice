<?php
namespace ApSemplice\Admin;

use ApSemplice\Edition;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina che presenta le funzioni di APSemplice Pro e dei suoi due livelli di licenza. È un semplice elenco informativo (niente funzioni
 * finte né pulsanti che non fanno nulla) e compare solo se c'è qualcosa che questa installazione non ha.
 */
final class ProPage {

	/** Funzioni per livello: [funzione, titolo, descrizione]. */
	public static function catalog(): array {
		return array(
			'Pro' => array(
				array( 'payments', 'Pagamenti online', 'I soci pagano quote, mensilità ed eventi con carta (Stripe), PayPal o il checkout di WooCommerce; l\'incasso entra da solo in prima nota.' ),
				array( 'funds', 'Conti, fondi e cassa per più persone', 'Più conti (cassa, banca, POS), giroconti, fondi per i rimborsi ai volontari e un\'unica cassa per chi paga per più soci.' ),
				array( 'reports', 'Report di gestione', 'Saldi e liquidità reale, risultato per attività e anno sociale.' ),
				array( 'levels', 'Quote diverse per i soci', 'Più livelli di socio (ridotto, sostenitore, onorario…) ognuno con la sua quota.' ),
				array( 'broadcasts', 'Comunicazioni a gruppi', 'Email a tutti i soci, a chi ha la tessera in scadenza o agli iscritti di un\'attività, inviate in background.' ),
				array( 'pwa', 'App e notifiche', 'L\'area soci come app installabile sul telefono, con notifiche per avvisi, promemoria e posti liberati.' ),
				array( 'wallet', 'Tessera nel telefono', 'La tessera in Apple Wallet e Google Wallet.' ),
				array( 'door_sales', 'Incasso sul posto', 'Chi gestisce un evento incassa all\'ingresso e registra subito la presenza.' ),
				array( 'receipts', 'Ricevute e attestazioni', 'Ricevute in PDF numerate (per gli eventi valgono anche da biglietto) e attestazione annuale dei versamenti per soci e donatori.' ),
				array( 'texts', 'Personalizzazione dei testi', 'Ogni frase che i soci vedono, nelle email e nei PDF si può cambiare; più lingue, tipo di ente e termini (associazione o comitato, socio o tesserato) con gli articoli giusti.' ),
				array( 'insurance', 'Assicurazioni e presenze', 'Registro delle polizze di volontari e associazione, con scadenze, e registro delle presenze ai corsi.' ),
			),
			'Pro Fiscale (include tutto il Pro)' => array(
				array( 'vat', 'IVA', 'Aliquote su quote, attività e incassi, importi con IVA compresa o esclusa e IVA contenuta in ogni movimento.' ),
				array( 'fivepm', '5 per mille', 'Messaggio con il codice fiscale, contributi ricevuti e scadenze del rendiconto sull\'utilizzo.' ),
				array( 'fiscal', 'Anni solari e rendiconto', 'Apertura e chiusura degli anni contabili, rendiconto per cassa e prima nota per il commercialista.' ),
			),
		);
	}

	/** Se manca qualcosa da presentare (nel Pro completo la pagina non serve). */
	public static function needed(): bool {
		foreach ( self::catalog() as $rows ) {
			foreach ( $rows as $r ) {
				if ( ! Edition::has( $r[0] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function render(): void {
		Ui::header( 'APSemplice Pro' );
		echo '<p>APSemplice resta gratuito per la gestione di base di un\'associazione. <strong>APSemplice Pro</strong> è un plugin a parte che si affianca a questo e aggiunge le funzioni qui sotto, in due livelli di licenza. I dati restano gli stessi: attivandolo non si perde né si ricarica niente.</p>';
		foreach ( self::catalog() as $level => $rows ) {
			echo '<div class="apse-card"><h2>' . esc_html( $level ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td style="width:240px"><strong>' . esc_html( $r[1] ) . '</strong></td><td>' . esc_html( $r[2] ) . '</td><td style="width:90px">'
					. ( Edition::has( $r[0] ) ? '<span class="apse-ok">✔ attiva</span>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( Edition::pro_url() ) . '" target="_blank" rel="noopener">Scopri APSemplice Pro</a> <span class="description">Si apre il sito di Wega Studios.</span></p>';
		echo '<p class="description">Il livello contabile comprende conti, pagamenti, report e comunicazioni; il livello fiscale aggiunge IVA, 5 per mille e gli anni solari per il commercialista.</p>';
		Ui::footer();
	}
}
