<?php
namespace ApSemplice\Admin;

use ApSemplice\Bank;
use ApSemplice\Iban;

defined( 'ABSPATH' ) || exit;

/** Bonifico bancario: IBAN mostrati ai soci e inviati per email. Fa parte delle funzioni di base (con i pagamenti online c'è anche nella loro pagina). */
final class BankPage {

	public static function render(): void {
		Ui::header( 'Impostazioni: bonifico bancario' );
		self::card();
		Ui::footer();
	}

	/** Bonifico bancario: IBAN mostrati ai soci e inviati per email. Si modificano solo da qui (amministratori); ogni cambio avvisa gli amministratori. */
	public static function card(): void {
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
		Ui::form_open( 'apse_save_bank', Ui::url( 'apse-bank' ) );
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

}
