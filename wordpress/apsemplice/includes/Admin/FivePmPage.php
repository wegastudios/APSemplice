<?php
namespace ApSemplice\Admin;

use ApSemplice\Db;
use ApSemplice\FivePerMille;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Contabilità → Adempimenti → 5x1000: messaggio per i soci, promemoria e contributi ricevuti con il rendiconto sull'utilizzo. */
final class FivePmPage {

	private static function date( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	public static function render(): void {
		Ui::header( '5x1000' );
		$on    = FivePerMille::enabled();
		$admin = current_user_can( Plugin::CAP );
		if ( $admin ) {
			Ui::form_open( 'apse_fivepm_settings', Ui::url( 'apse-fivepm' ) );
			echo '<p><label><input type="checkbox" name="fivepm_enabled" value="1"' . checked( $on, true, false ) . '> <strong>Attiva il 5x1000</strong></label> <span class="description">Spento di default.</span></p>';
			echo '<p>Messaggio per i soci <span class="description">(vuoto = messaggio standard; puoi usare {associazione} e {codice_fiscale})</span><br><textarea name="fivepm_text" rows="4" class="large-text" maxlength="1000">' . esc_textarea( (string) Settings::get( 'fivepm_text' ) ) . '</textarea></p>';
			echo '<p><button class="button button-primary">Salva</button></p>';
			Ui::form_close();
		}
		if ( ! $on ) {
			echo '<p class="description">Il 5x1000 è spento' . ( $admin ? '.' : ': lo attiva un amministratore.' ) . '</p>';
			Ui::footer();
			return;
		}
		$cf = trim( (string) Settings::get( 'tax_code' ) );
		if ( '' === $cf ) {
			echo '<div class="notice notice-warning inline"><p>Manca il codice fiscale dell\'associazione: inseriscilo in <a href="' . esc_url( Ui::url( 'apse-settings' ) ) . '">Impostazioni</a>, senza non si può chiedere il 5x1000.</p></div>';
		}
		echo '<h2>Chiedi il 5x1000 ai soci</h2><p>Il messaggio con il codice fiscale compare dove inserisci lo shortcode <code>[apsemplice_cinquepermille]</code> (o il blocco «APSemplice» → 5x1000). Questo è il testo:</p>';
		echo '<div class="apse-card">' . esc_html( FivePerMille::text() ) . '</div>';
		$body = 'Ciao {nome}, ' . FivePerMille::text() . "\n\nGrazie, {associazione}";
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'apse-messages', array( 'audience' => 'members_all', 'subject' => 'Il tuo 5x1000', 'body' => $body, 'preview' => 1 ) ) ) . '">Prepara il promemoria per i soci</a> '
			. '<span class="description">Apre le comunicazioni con il messaggio già scritto: controlli l\'elenco dei destinatari e lo invii.</span></p>';

		echo '<h2>Contributi ricevuti</h2><p class="description">Registra l\'importo accreditato dall\'Agenzia delle Entrate: entro 12 mesi va redatto il rendiconto sull\'utilizzo (e la relazione illustrativa), da pubblicare.</p>';
		$rows   = FivePerMille::all();
		$labels = array( FivePerMille::OPEN => 'In corso', FivePerMille::DUE_SOON => 'Rendiconto in scadenza', FivePerMille::OVERDUE => 'Rendiconto scaduto', FivePerMille::REPORTED => 'Rendicontato' );
		$cls    = array( FivePerMille::OPEN => '', FivePerMille::DUE_SOON => 'apse-warn', FivePerMille::OVERDUE => 'apse-neg', FivePerMille::REPORTED => '' );
		echo '<table class="widefat striped"><thead><tr><th>Anno</th><th>Importo</th><th>Scelte</th><th>Accreditato il</th><th>Rendiconto entro</th><th>Stato</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nessun contributo registrato.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$st = FivePerMille::status( $r );
			echo '<tr><td>' . (int) $r['year'] . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td><td>' . (int) $r['choices'] . '</td><td>' . self::date( $r['received_on'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '</td><td>' . self::date( FivePerMille::due_date( $r ) ) . '</td><td><span class="' . esc_attr( $cls[ $st ] ) . '">' . esc_html( $labels[ $st ] ) . '</span></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( FivePerMille::REPORTED !== $st ) {
				Ui::form_open( 'apse_fivepm_report', Ui::url( 'apse-fivepm' ) );
				echo Ui::hidden( 'id', $r['id'] ) . '<details><summary>Registra il rendiconto</summary><p><input type="date" name="reported_on" value="' . esc_attr( Db::today() ) . '" required></p>'
					. '<p><textarea name="report_notes" rows="4" class="large-text" placeholder="Come è stato utilizzato il contributo" required></textarea></p><p><button class="button">Salva il rendiconto</button></p></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			} else {
				echo '<details><summary>Rendiconto del ' . self::date( $r['reported_on'] ) . '</summary><p style="white-space:pre-wrap">' . esc_html( (string) $r['report_notes'] ) . '</p></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			Ui::form_open( 'apse_fivepm_delete', Ui::url( 'apse-fivepm' ), false, 'apse-inline' );
			echo Ui::hidden( 'id', $r['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questo contributo?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::form_open( 'apse_fivepm_add', Ui::url( 'apse-fivepm' ) );
		echo '<h3>Registra un contributo</h3><p>Anno di imposta <input type="number" name="year" min="2006" value="' . (int) ( (int) substr( Db::today(), 0, 4 ) - 2 ) . '" required> importo € <input type="text" name="amount" size="10" inputmode="decimal" required> '
			. 'scelte <input type="number" name="choices" min="0" value="0" style="width:6em"> accreditato il <input type="date" name="received_on" value="' . esc_attr( Db::today() ) . '" required> <button class="button button-primary">Registra</button></p>';
		Ui::form_close();
		Ui::footer();
	}
}
