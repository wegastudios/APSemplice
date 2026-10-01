<?php
namespace ApSemplice\Admin;

use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Tessera digitale: attivazione del QR, rigenerazione dei codici e (nella stessa pagina) Wallet di Apple e Google. */
final class CardPage {

	public static function render(): void {
		Ui::header( 'Tessera digitale: QR e Wallet' );
		$on = Settings::card_qr_enabled();
		echo '<div class="apse-card"><h2>QR della tessera</h2>'
			. '<p>Se lo attivi, ogni socio trova nella sua area riservata un QR sulla tessera digitale. Chi lo scansiona (anche senza accedere al sito) vede subito se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza: niente altro. '
			. 'Il QR non cambia quando la tessera si rinnova, perché la verifica è sempre in diretta. È <strong>spento di default</strong>.</p>';
		Ui::form_open( 'apse_save_card', Ui::url( 'apse-card' ) );
		echo '<p><label><input type="checkbox" name="card_qr_enabled" value="1"' . checked( $on, true, false ) . '> <strong>Attiva il QR sulla tessera digitale</strong></label></p>';
		submit_button( 'Salva', 'primary', 'submit', false );
		Ui::form_close();
		echo '<p class="description">Gli ospiti non hanno tessera. Il QR contiene solo un codice di verifica firmato (non dati personali) e non si può costruire a mano per un altro socio. '
			. 'I <strong>biglietti QR delle prenotazioni</strong> sono un\'impostazione a parte, per singolo evento: si attivano nella scheda dell\'evento.</p>';
		if ( $on ) {
			Ui::form_open( 'apse_regen_qr', Ui::url( 'apse-card' ), false, 'apse-inline' );
			echo '<button class="button" data-confirm="Rigenerare tutti i QR? Quelli già stampati o salvati nei telefoni (tessere e biglietti) smetteranno di funzionare e i soci dovranno prendere il nuovo dalla loro area riservata.">Rigenera tutti i QR</button>';
			Ui::form_close();
			echo ' <span class="description">Da usare solo se un QR è stato diffuso per errore (vale anche per i biglietti degli eventi).</span>';
		}
		echo '</div>';
		Ui::footer();
	}
}
