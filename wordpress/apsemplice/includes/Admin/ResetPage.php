<?php
namespace ApSemplice\Admin;

use ApSemplice\Reset;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Sistema → Azzeramento dati: si riparte da zero. Solo amministratori, in due passaggi, con frase e password. */
final class ResetPage {

	public static function render(): void {
		Ui::header( 'Azzeramento dati' );
		$p = Reset::preview();
		echo '<div class="notice notice-error inline"><p><strong>Operazione definitiva.</strong> Cancella i dati dell\'associazione per poter ripartire da zero. Prima di cancellare dovrai scaricare una copia completa (con gli allegati) e conservarla tu: sul sito non ne resta nessuna.</p></div>';

		echo '<h2>Cosa c\'è adesso</h2><ul style="list-style:disc;margin-left:20px">'
			. '<li>' . (int) $p['people'] . ' soci e volontari, ' . (int) $p['guests'] . ' ospiti</li>'
			. '<li>' . (int) $p['activities'] . ' attività ed eventi, con prenotazioni, iscrizioni e presenze</li>'
			. '<li>' . (int) $p['transactions'] . ' movimenti in prima nota, ' . (int) $p['memberships'] . ' iscrizioni all\'anno, ' . (int) $p['minutes'] . ' verbali</li>'
			. '<li>' . (int) $p['attachments'] . ' allegati (' . (int) $p['files'] . ' file di scontrini e fatture)</li></ul>';

		$step = Ui::get_int( 'step', 1 );
		if ( 2 === $step ) {
			self::review( $p );
		} else {
			self::choose( $p );
		}
		Ui::footer();
	}

	private static function choose( array $p ): void {
		echo '<form method="get"><input type="hidden" name="page" value="apse-reset"><input type="hidden" name="step" value="2">';
		echo '<h2>Che cosa vuoi azzerare</h2>';
		echo '<p><label><input type="radio" name="mode" value="data" checked> <strong>Solo i dati</strong></label><br><span class="description">Cancella soci, ospiti, attività, prima nota, registri, pagamenti, comunicazioni, ricevute e allegati. <strong>Restano</strong> le impostazioni, i testi personalizzati, le pagine del sito e il registro azioni. Si ricreano conti, voci e livelli predefiniti.</span></p>';
		echo '<p><label><input type="radio" name="mode" value="factory"> <strong>Ripristino di fabbrica</strong></label><br><span class="description">Come appena installato: cancella anche impostazioni, testi, pagine create dalla procedura e registro azioni, e riapre la configurazione guidata. Le chiavi dei pagamenti online (Stripe, PayPal) restano.</span></p>';
		echo '<p><label><input type="checkbox" name="users" value="1" checked> Elimina anche gli accessi dei soci (' . (int) $p['users'] . ' utenti WordPress con il solo ruolo «Socio APS»)</label><br><span class="description">Mai gli amministratori, la segreteria e gli altri utenti del sito.</span></p>';
		echo '<p><button class="button button-primary">Continua</button></p></form>';
	}

	private static function review( array $p ): void {
		$mode  = 'factory' === Ui::get_str( 'mode' ) ? Reset::FACTORY : Reset::DATA;
		$users = '1' === Ui::get_str( 'users' );
		echo '<div class="notice notice-warning inline" style="padding:12px 16px"><h3 style="margin-top:0">Cosa succede</h3>';
		echo '<p><strong>Vengono cancellati per sempre:</strong> tutti i soci e gli ospiti, le attività e gli eventi con prenotazioni, iscrizioni e presenze, <strong>tutta la prima nota</strong> (incassi, spese, giroconti, rettifiche), i conti e i fondi, le iscrizioni all\'anno, i verbali, le assicurazioni, il 5x1000, i pagamenti online registrati, le comunicazioni, le ricevute emesse (e la loro numerazione) e i file allegati (' . (int) $p['files'] . ').</p>';
		if ( Reset::FACTORY === $mode ) {
			echo '<p><strong>Ripristino di fabbrica:</strong> vengono cancellati anche le impostazioni, i testi personalizzati, l\'elenco delle pagine create dalla procedura (le pagine WordPress restano, con i loro shortcode), le richieste di accesso e il registro azioni. La configurazione guidata si riapre.</p>';
		} else {
			echo '<p><strong>Restano:</strong> impostazioni, testi personalizzati, pagine del sito e registro azioni (con la registrazione di questo azzeramento).</p>';
		}
		echo '<p>' . ( $users ? 'Vengono eliminati anche <strong>' . (int) $p['users'] . ' accessi di soci</strong> (utenti con il solo ruolo «Socio APS»).' : 'Gli accessi dei soci (utenti WordPress) non vengono toccati, ma resteranno senza una scheda collegata.' ) . '</p>';
		echo '<p><strong>Prima di cancellare scarica una copia completa dei dati e conservala in un posto sicuro</strong> (è un file con i dati personali di soci e ospiti: trattala come tale). Sul sito non ne resta nessuna: nemmeno le copie salvate in precedenza, che verranno eliminate insieme ai dati.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Backup::download_url( true ) ) . '">Scarica la copia completa</a> ' . ( Backup::downloaded_recently() ? '<span style="color:#1a7f37">✔ Copia scaricata: puoi procedere.</span>' : '<span style="color:#b32d2e">Senza questo passaggio l\'azzeramento non parte.</span>' ) . '</p></div>';

		echo '<h3>Conferma</h3>';
		Ui::form_open( 'apse_reset_all', Ui::url( 'apse-reset', array( 'step' => 2, 'mode' => $mode, 'users' => $users ? 1 : 0 ) ) );
		echo Ui::hidden( 'mode', $mode ) . Ui::hidden( 'users', $users ? 1 : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label><input type="checkbox" name="confirm" value="1"> Ho letto e ho capito: i dati verranno cancellati per sempre.</label></p>';
		echo '<p><label>Scrivi <strong>' . esc_html( Reset::PHRASE ) . '</strong> per confermare:<br><input type="text" name="phrase" class="regular-text" autocomplete="off"></label></p>';
		echo '<p><label>La tua password di amministratore:<br><input type="password" name="password" class="regular-text" autocomplete="current-password"></label></p>';
		echo '<p><button class="button button-primary" style="background:#b32d2e;border-color:#b32d2e">Azzera i dati</button> <a class="button" href="' . esc_url( Ui::url( 'apse-reset' ) ) . '">← Cambia scelta</a></p>';
		Ui::form_close();
	}
}
