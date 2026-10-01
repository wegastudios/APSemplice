<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\WpAllImport;

defined( 'ABSPATH' ) || exit;

/** Istruzioni, impostazioni e coda degli elementi importati con WP All Import. */
final class WpAiPage {

	private static function fields_table( array $fields, string $note ): void {
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Campo personalizzato</th><th>Cosa contiene</th></tr></thead><tbody>';
		foreach ( $fields as $meta => $label ) {
			echo '<tr><td><code>' . esc_html( $meta ) . '</code></td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html( $note ) . '</p>';
	}

	public static function render(): void {
		Ui::header( 'Import con WP All Import', '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-import' ) ) . '">← Import da Excel/CSV</a>' );
		$active = defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin' );
		echo '<div class="notice notice-' . ( $active ? 'success' : 'info' ) . ' inline"><p>' . ( $active ? 'WP All Import è attivo su questo sito.' : 'WP All Import non risulta attivo. Se non lo usi, l\'import da <a href="' . esc_url( Ui::url( 'apse-import' ) ) . '">Excel/CSV</a> fa già tutto.' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="apse-card"><h2>Come si fa</h2><ol>'
			. '<li>In <strong>All Import → Nuova importazione</strong> carica il tuo file (Excel, CSV, XML, anche da indirizzo web o Google Fogli).</li>'
			. '<li>In "Importa in" scegli <strong>Movimenti APSemplice (import)</strong> per la prima nota oppure <strong>Soci e ospiti APSemplice (import)</strong> per soci e ospiti.</li>'
			. '<li>Come titolo metti quello che vuoi (es. la data e la descrizione): non conta.</li>'
			. '<li>In <strong>Campi personalizzati</strong> aggiungi i campi qui sotto, uno per colonna, e trascina i dati del tuo file. Quelli che non ti servono lasciali fuori.</li>'
			. '<li>Avvia l\'importazione. Al termine il plugin legge gli elementi, applica le stesse regole dell\'import da file, li registra e <strong>toglie quelli riusciti</strong>. Quelli con errori restano qui sotto con il motivo.</li>'
			. '</ol><p class="description">Non serve altro, né funzioni da copiare. Puoi anche pianificare l\'importazione in WP All Import (ad esempio da un file che aggiorni ogni settimana): i doppioni in prima nota vengono riconosciuti e saltati.</p></div>';

		echo '<div class="apse-cols"><div class="apse-col"><div class="apse-card"><h2>Prima nota</h2>';
		self::fields_table( WpAllImport::LEDGER_FIELDS, 'Servono la data e un importo: "apse_amount" (con il segno: negativo = uscita) oppure "apse_income" e "apse_expense". Date: 15/01/2024 o 2024-01-15. Importi: 1.234,56 o 1234.56. I conti che non esistono si creano.' );
		echo '</div></div><div class="apse-col"><div class="apse-card"><h2>Soci e ospiti</h2>';
		self::fields_table( WpAllImport::PEOPLE_FIELDS, 'Per i soci servono nome, cognome ed email. Per gli ospiti: "apse_member_type" = ospite e "apse_host" = tessera, email o nome e cognome del socio. Tipi: fondatore, ordinario, volontario, ospite.' );
		echo '</div></div></div>';

		// Valori usati quando manca la colonna
		$types = array();
		foreach ( MemberType::member_types() as $t ) {
			$types[ $t ] = MemberType::label( $t );
		}
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		echo '<div class="apse-card"><h2>Impostazioni dell\'import</h2>';
		Ui::form_open( 'apse_save_wpai', Ui::url( 'apse-wpai' ) );
		echo '<table class="form-table"><tbody>'
			. '<tr><th>Tipo di socio se manca</th><td><select name="default_type">' . Ui::options( $types, (string) Settings::get( 'wpai_default_type' ) ) . '</select></td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><th>Conto se manca</th><td><select name="default_account_id">' . Ui::options( $accounts, (int) Settings::get( 'wpai_default_account_id' ) ?: null, '— nessuno —' ) . '</select></td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<tr><th>Saldi attuali</th><td><label><input type="checkbox" name="keep_balances" value="1"' . checked( (int) Settings::get( 'wpai_keep_balances' ), 1, false ) . '> Non cambiare i saldi attuali dei conti (consigliato per anni passati)</label></td></tr>'
			. '<tr><th>Iscrizione</th><td><label><input type="checkbox" name="mark_members" value="1"' . checked( (int) Settings::get( 'wpai_mark_members' ), 1, false ) . '> Segna i soci importati come iscritti all\'anno sociale ' . esc_html( Settings::social_year()->label() ) . '</label></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '</div>';

		// Coda
		$pending = array_merge( WpAllImport::staged( WpAllImport::TYPE_PEOPLE ), WpAllImport::staged( WpAllImport::TYPE_LEDGER ) );
		$errors  = array_merge( WpAllImport::staged( WpAllImport::TYPE_PEOPLE, true ), WpAllImport::staged( WpAllImport::TYPE_LEDGER, true ) );
		echo '<div class="apse-card"><h2>Elementi in coda</h2><p><strong>' . count( $pending ) . '</strong> da elaborare · <strong class="apse-neg">' . count( $errors ) . '</strong> con errori.</p>';
		echo '<p>';
		Ui::form_open( 'apse_wpai_process', Ui::url( 'apse-wpai' ), false, 'apse-inline' );
		echo '<button class="button button-primary"' . ( $pending ? '' : ' disabled' ) . '>Elabora adesso</button>';
		Ui::form_close();
		echo ' ';
		if ( $errors ) {
			Ui::form_open( 'apse_wpai_retry', Ui::url( 'apse-wpai' ), false, 'apse-inline' );
			echo '<button class="button">Riprova quelli con errore</button>';
			Ui::form_close();
			echo ' ';
			Ui::form_open( 'apse_wpai_clear', Ui::url( 'apse-wpai' ), false, 'apse-inline' );
			echo '<button class="button" data-confirm="Eliminare gli elementi con errori?">Elimina quelli con errore</button>';
			Ui::form_close();
		}
		echo '</p>';
		if ( $errors ) {
			echo '<table class="widefat striped"><thead><tr><th>Elemento</th><th>Tipo</th><th>Errore</th></tr></thead><tbody>';
			foreach ( array_slice( $errors, 0, 200 ) as $p ) {
				echo '<tr><td>' . esc_html( get_the_title( $p ) ?: ( '#' . $p->ID ) ) . '</td><td>' . esc_html( WpAllImport::TYPE_PEOPLE === $p->post_type ? 'Socio / ospite' : 'Movimento' ) . '</td><td>' . esc_html( (string) get_post_meta( $p->ID, WpAllImport::META_MSG, true ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		Ui::footer();
	}
}
