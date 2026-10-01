<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\PeopleCsv;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class ImportPage {

	public static function render(): void {
		Ui::header( 'Importa soci da CSV', '<a class="page-title-action" href="' . esc_url( Ui::url( 'aps-people' ) ) . '">← Elenco</a>' );
		$token = Ui::get_str( 'token' );
		$plan  = '' !== $token ? get_transient( 'aps_import_' . get_current_user_id() . '_' . sanitize_key( $token ) ) : null;
		if ( is_array( $plan ) ) {
			self::preview( $token, $plan );
		} else {
			self::upload();
		}
		Ui::footer();
	}

	private static function upload(): void {
		echo '<p>Carica un file CSV (da Excel: <em>File → Salva con nome → CSV</em>). La prima riga contiene le intestazioni. Colonne riconosciute, in qualunque ordine: '
			. '<strong>Numero tessera, Tipo, Nome, Cognome, Email, Telefono, Codice fiscale</strong>. Nome, Cognome ed <strong>Email</strong> sono obbligatori: ogni socio diventa un utente WordPress. '
			. 'Il Tipo può essere <em>fondatore</em>, <em>ordinario</em> o <em>volontario</em>; se manca si usa quello scelto qui sotto. Gli ospiti si aggiungono dalla scheda del socio.</p>';
		Ui::form_open( 'aps_import_preview', Ui::url( 'aps-import' ), true );
		echo '<p><input type="file" name="file" accept=".csv,text/csv,text/plain" required></p>';
		$types = array();
		foreach ( MemberType::member_types() as $t ) {
			$types[ $t ] = MemberType::label( $t );
		}
		echo '<p>Tipo predefinito: <select name="default_type">' . Ui::options( $types, MemberType::ORDINARY ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Carica e controlla' );
		Ui::form_close();
		$template = 'data:text/csv;charset=utf-8,' . rawurlencode( "\xEF\xBB\xBF" . PeopleCsv::TEMPLATE );
		echo '<p><a href="' . esc_attr( $template ) . '" download="modello-import-soci.csv">Scarica un modello CSV</a></p>';
	}

	private static function preview( string $token, array $plan ): void {
		$counts = array( 'create' => 0, 'update' => 0, 'error' => 0 );
		foreach ( $plan as $p ) {
			$counts[ $p['action'] ]++;
		}
		echo '<p><strong>' . (int) $counts['create'] . '</strong> nuovi · <strong>' . (int) $counts['update'] . '</strong> da aggiornare · <strong class="aps-neg">' . (int) $counts['error'] . '</strong> con errori (saltati).</p>';
		if ( $counts['create'] + $counts['update'] > 0 ) {
			Ui::form_open( 'aps_import_apply', Ui::url( 'aps-import', array( 'token' => $token ) ) );
			echo Ui::hidden( 'token', $token ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><label><input type="checkbox" name="mark_members" value="1"> Segna come iscritti all\'anno sociale ' . esc_html( Settings::social_year()->label() ) . ' (non vale per i fondatori, sempre in regola)</label></p>';
			submit_button( 'Importa ' . ( $counts['create'] + $counts['update'] ) . ' soci' );
			Ui::form_close();
		}
		echo '<table class="widefat striped"><thead><tr><th>Riga</th><th>Tessera</th><th>Nome</th><th>Email</th><th>Tipo</th><th>Esito</th></tr></thead><tbody>';
		$labels = array( 'create' => 'Nuovo', 'update' => 'Aggiorna', 'error' => 'Errore' );
		foreach ( $plan as $p ) {
			$r   = $p['row'];
			$cls = 'error' === $p['action'] ? 'aps-neg' : ( 'update' === $p['action'] ? 'aps-warn' : 'aps-ok' );
			echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( (string) $r['card'] ) . '</td><td>' . esc_html( $r['first'] . ' ' . $r['last'] ) . '</td><td>' . esc_html( (string) $r['email'] ) . '</td>';
			echo '<td>' . esc_html( $p['type'] ? MemberType::label( $p['type'] ) : '—' ) . '</td><td class="' . esc_attr( $cls ) . '">' . esc_html( $labels[ $p['action'] ] . ( $p['message'] ? ' — ' . $p['message'] : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
