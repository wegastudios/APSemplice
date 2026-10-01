<?php
namespace ApSemplice\Admin;

use ApSemplice\QrCode;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Tessera digitale: QR di verifica e (più avanti nella stessa pagina) Wallet di Apple e Google. */
final class CardPage {

	public static function render(): void {
		Ui::header( 'Tessera digitale: QR e Wallet' );
		echo '<div class="apse-card"><h2>QR della tessera</h2>'
			. '<p>Ogni socio ha, nella sua area riservata, un QR sulla tessera digitale. Chi lo scansiona (anche senza accedere al sito) vede subito se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza: niente altro. '
			. 'Il QR non cambia quando la tessera si rinnova, perché la verifica è sempre in diretta.</p>'
			. '<p class="description">Gli ospiti non hanno tessera. Il QR contiene solo un codice di verifica firmato (non dati personali) e non si può costruire a mano per un altro socio.</p>';
		Ui::form_open( 'apse_regen_qr', Ui::url( 'apse-card' ), false, 'apse-inline' );
		echo '<button class="button" data-confirm="Rigenerare tutti i QR? Quelli già stampati o salvati nei telefoni smetteranno di funzionare e i soci dovranno prendere il nuovo dalla loro area riservata.">Rigenera tutti i QR</button>';
		Ui::form_close();
		echo ' <span class="description">Da usare solo se un QR è stato diffuso per errore.</span></div>';
		Ui::footer();
	}
}
