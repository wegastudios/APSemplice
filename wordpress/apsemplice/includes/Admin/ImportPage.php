<?php
namespace ApSemplice\Admin;

use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\PeopleCsv;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Import di soci, ospiti e prima nota da Excel (.xlsx) o CSV, con anteprima prima di scrivere. */
final class ImportPage {

	const LEDGER_TEMPLATE = "Data;Tipo;Conto;Modalità;Voce;Importo;Descrizione;Riferimento;N. tessera;Attività;Competenza\r\n15/01/2024;Entrata;Cassa contanti;Contanti;Quota associativa;10,00;Quota 2023/2024;;1;;\r\n20/01/2024;Uscita;Conto corrente;Bonifico;Costo generale;45,50;Affitto sala;FT-12;;;\r\n";
	const GUESTS_TEMPLATE = "Tipo;Nome;Cognome;Cellulare;Ospite di\r\nospite;Gino;Rossi;333 1234567;1\r\n";

	public static function render(): void {
		Ui::header( 'Importa da Excel o CSV', '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-people' ) ) . '">← Soci</a>' );
		$token = Ui::get_str( 'token' );
		$prev  = '' !== $token ? get_transient( 'apse_import_' . get_current_user_id() . '_' . sanitize_key( $token ) ) : null;
		if ( is_array( $prev ) ) {
			self::preview( $token, $prev );
		} else {
			self::upload();
		}
		Ui::footer();
	}

	private static function data_link( string $csv, string $file, string $label ): string {
		return '<a href="' . esc_attr( 'data:text/csv;charset=utf-8,' . rawurlencode( "\xEF\xBB\xBF" . $csv ) ) . '" download="' . esc_attr( $file ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function upload(): void {
		echo '<p>Carica un file <strong>Excel (.xlsx)</strong> o <strong>CSV</strong> con i <strong>soci</strong>, gli <strong>ospiti</strong> e/o la <strong>prima nota</strong>, anche di anni passati. '
			. 'Se nel file Excel ci sono più fogli (es. "Soci", "Ospiti", "Prima nota") li leggo tutti: capisco da solo a cosa serve ognuno dalle intestazioni. Prima di scrivere qualcosa vedrai un\'anteprima.</p>';
		echo '<div class="apse-cols"><div class="apse-col"><div class="apse-card"><h2>Soci e ospiti</h2>'
			. '<p>Colonne riconosciute, in qualunque ordine: <strong>Numero tessera, Tipo, Nome, Cognome, Email, Telefono, Codice fiscale</strong> e, per gli ospiti, <strong>Cellulare</strong> (obbligatorio) e <strong>Ospite di</strong> (tessera, email o nome e cognome del socio). '
			. 'Nome e Cognome sono obbligatori; per i soci serve poi <strong>almeno uno tra Email, Cellulare e Numero tessera</strong>. Con l\'email il socio ha subito il suo accesso all\'area riservata; senza, resta registrato e si attiva dopo con un <strong>link da mandare su WhatsApp</strong> (lo trovi nell\'elenco soci). Il Tipo può essere <em>fondatore</em>, <em>ordinario</em>, <em>volontario</em> oppure <em>ospite</em>.</p></div></div>'
			. '<div class="apse-col"><div class="apse-card"><h2>Prima nota</h2>'
			. '<p>Colonne: <strong>Data, Importo</strong> (oppure <strong>Entrata</strong> e <strong>Uscita</strong>), e se vuoi <strong>Tipo, Conto, Modalità, Voce, Descrizione, Riferimento, N. tessera, Persona, Attività, Competenza</strong>. '
			. 'Riconosco anche il file che esporta questo plugin. Le date possono essere 15/01/2024 o 2024-01-15; gli importi 1.234,56 o 1234.56. I conti che non esistono si creano; le voci non riconosciute finiscono in "Altra entrata" / "Costo generale".</p></div></div></div>';
		Ui::form_open( 'apse_import_preview', Ui::url( 'apse-import' ), true );
		echo '<p><input type="file" name="file" accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></p>';
		$types = array();
		foreach ( MemberType::member_types() as $t ) {
			$types[ $t ] = MemberType::label( $t );
		}
		$accounts = array();
		foreach ( Plugin::ledger()->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		echo '<p>Tipo socio se manca la colonna: <select name="default_type">' . Ui::options( $types, MemberType::ORDINARY ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' · Conto della prima nota se manca la colonna: <select name="default_account_id">' . Ui::options( $accounts, null, '— nessuno —' ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( 'Carica e controlla' );
		Ui::form_close();
		echo '<p>Modelli CSV: ' . self::data_link( PeopleCsv::TEMPLATE, 'modello-soci.csv', 'soci' ) . ' · ' . self::data_link( self::GUESTS_TEMPLATE, 'modello-ospiti.csv', 'ospiti' ) . ' · ' . self::data_link( self::LEDGER_TEMPLATE, 'modello-prima-nota.csv', 'prima nota' ) . ' <span class="description">(si aprono anche con Excel)</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Usi <strong>WP All Import</strong>? Si può importare anche da lì: vedi <a href="' . esc_url( Ui::url( 'apse-wpai' ) ) . '">Import con WP All Import</a>.</p>';
		self::history();
	}

	/** Import già fatti, con l'annullamento in blocco. */
	private static function history(): void {
		$batches = \ApSemplice\ImportService::batches( 20 );
		if ( ! $batches ) {
			return;
		}
		echo '<div class="apse-card"><h2>Import già fatti</h2><table class="widefat striped"><thead><tr><th>N.</th><th>Quando</th><th>Origine</th><th>Cosa è entrato</th><th></th></tr></thead><tbody>';
		foreach ( $batches as $b ) {
			$s     = $b['summary'];
			$parts = array_filter(
				array(
					! empty( $s['people_created'] ) ? $s['people_created'] . ' soci/ospiti nuovi' : '',
					! empty( $s['people_updated'] ) ? $s['people_updated'] . ' schede aggiornate' : '',
					! empty( $s['transactions'] ) ? $s['transactions'] . ' movimenti' : '',
					! empty( $s['transfers'] ) ? $s['transfers'] . ' giroconti' : '',
				)
			);
			echo '<tr><td>' . (int) $b['id'] . '</td><td>' . esc_html( mysql2date( 'd/m/Y H:i', $b['created_at'] ) ) . '<br><span class="description">' . esc_html( (string) $b['display_name'] ) . '</span></td>'
				. '<td>' . esc_html( $b['source'] ) . '</td><td>' . esc_html( implode( ' · ', $parts ) ) . '</td><td>';
			if ( ! empty( $b['undone_at'] ) ) {
				echo '<span class="description">Annullato il ' . esc_html( mysql2date( 'd/m/Y H:i', $b['undone_at'] ) ) . '</span>';
			} else {
				Ui::form_open( 'apse_import_undo', Ui::url( 'apse-import' ), false, 'apse-inline' );
				echo Ui::hidden( 'batch_id', $b['id'] ) . '<button class="button button-small" data-confirm="Annullare TUTTO questo import? Si annullano i movimenti, si tolgono i soci creati se non sono ancora stati usati e si rimettono i dati dei soci aggiornati. Non si può rifare con un clic.">Annulla questo import</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p class="description">L\'annullamento in blocco annulla i movimenti (restano nel registro come annullati), rimette i saldi iniziali dei conti, toglie i conti nuovi rimasti vuoti, ripristina i dati dei soci aggiornati e rimuove i soci e gli ospiti creati che non sono stati ancora usati (iscritti ad attività, prenotati, con movimenti o pagamenti): quelli già usati restano e vengono elencati.</p></div>';
	}

	private static function badge( string $action, bool $warn ): string {
		$map = array(
			'create' => array( 'Nuovo', 'apse-ok' ), 'update' => array( 'Aggiorna', 'apse-warn' ), 'error' => array( 'Errore', 'apse-neg' ),
			'transfer' => array( 'Giroconto', 'apse-ok' ), 'paired' => array( 'Giroconto', '' ), 'duplicate' => array( 'Già presente', 'apse-warn' ), 'skip' => array( 'Saltato', 'apse-warn' ),
		);
		$m = $map[ $action ] ?? array( $action, '' );
		return '<span class="' . esc_attr( $m[1] ) . '">' . esc_html( $m[0] ) . '</span>';
	}

	private static function preview( string $token, array $prev ): void {
		$people = $prev['people'] ?? null;
		$ledger = $prev['ledger'] ?? null;
		$ok     = 0;
		if ( $people ) {
			$c = array( 'create' => 0, 'update' => 0, 'error' => 0 );
			foreach ( $people['plan'] as $p ) {
				$c[ $p['action'] ]++;
			}
			$ok += $c['create'] + $c['update'];
			echo '<div class="apse-card"><h2>Soci e ospiti</h2><p><strong>' . (int) $c['create'] . '</strong> nuovi · <strong>' . (int) $c['update'] . '</strong> da aggiornare · <strong class="apse-neg">' . (int) $c['error'] . '</strong> con errori (saltati).</p>';
			self::people_table( $people['plan'] );
			echo '</div>';
		}
		if ( $ledger ) {
			$s   = $ledger['summary'];
			$ok += $s['counts']['create'] + $s['counts']['transfer'];
			echo '<div class="apse-card"><h2>Prima nota</h2>';
			echo '<p><strong>' . (int) $s['counts']['create'] . '</strong> movimenti da importare'
				. ( $s['counts']['transfer'] ? ' · <strong>' . (int) $s['counts']['transfer'] . '</strong> giroconti' : '' )
				. ( $s['counts']['duplicate'] ? ' · <strong class="apse-warn">' . (int) $s['counts']['duplicate'] . '</strong> già presenti (saltati)' : '' )
				. ( $s['counts']['skip'] ? ' · <strong class="apse-warn">' . (int) $s['counts']['skip'] . '</strong> saltati' : '' )
				. ' · <strong class="apse-neg">' . (int) $s['counts']['error'] . '</strong> con errori (saltati)'
				. ( $s['counts']['warn'] ? ' · ' . (int) $s['counts']['warn'] . ' con avvisi' : '' ) . '.</p>';
			if ( $s['from'] ) {
				echo '<p>Periodo: dal ' . esc_html( Ui::date( $s['from'] ) ) . ' al ' . esc_html( Ui::date( $s['to'] ) ) . ' · Entrate <strong>' . esc_html( Money::format( $s['income'] ) ) . '</strong> · Uscite <strong>' . esc_html( Money::format( $s['expense'] ) ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( $s['per_account'] ) {
				echo '<p>Effetto sui conti: ';
				$parts = array();
				foreach ( $s['per_account'] as $name => $delta ) {
					$parts[] = esc_html( $name ) . ' <strong>' . esc_html( ( $delta >= 0 ? '+' : '−' ) . ' ' . Money::format( abs( $delta ) ) ) . '</strong>' . ( in_array( $name, $s['new_accounts'], true ) ? ' (conto nuovo)' : '' );
				}
				echo implode( ' · ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			self::ledger_table( $ledger['plan'] );
			echo '</div>';
		}
		if ( ! empty( $prev['ignored'] ) ) {
			echo '<p class="description">Fogli non riconosciuti (ignorati): ' . esc_html( implode( ', ', $prev['ignored'] ) ) . '</p>';
		}
		if ( $ok > 0 ) {
			Ui::form_open( 'apse_import_apply', Ui::url( 'apse-import', array( 'token' => $token ) ) );
			echo Ui::hidden( 'token', $token ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $people ) {
				echo '<p><label><input type="checkbox" name="mark_members" value="1"> Segna i soci come iscritti all\'anno sociale ' . esc_html( Settings::social_year()->label() ) . ' (non vale per i fondatori, sempre in regola)</label></p>';
			}
			if ( $ledger ) {
				echo '<p><label><input type="checkbox" name="keep_balances" value="1" checked> <strong>Non cambiare i saldi attuali dei conti</strong> (consigliato per le annualità passate): aggiusto il saldo iniziale dei conti già esistenti, così il saldo di oggi resta com\'è e la storia si completa. Toglila se il file contiene TUTTA la storia e vuoi che i saldi derivino dai movimenti.</label></p>';
			}
			submit_button( 'Importa' );
			Ui::form_close();
		}
		echo '<p><a href="' . esc_url( Ui::url( 'apse-import' ) ) . '">← Scegli un altro file</a></p>';
	}

	private static function people_table( array $plan ): void {
		echo '<table class="widefat striped"><thead><tr><th>Riga</th><th>Tessera</th><th>Nome</th><th>Email</th><th>Tipo</th><th>Esito</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $plan as $p ) {
			if ( ++$shown > 300 && 'error' !== $p['action'] ) {
				continue;
			}
			$r = $p['row'];
			echo '<tr><td>' . esc_html( ( $r['sheet'] ? $r['sheet'] . ' · ' : '' ) . $r['line'] ) . '</td><td>' . esc_html( (string) $r['card'] ) . '</td><td>' . esc_html( $r['first'] . ' ' . $r['last'] ) . '</td><td>' . esc_html( (string) $r['email'] ) . '</td>';
			echo '<td>' . esc_html( $p['type'] ? MemberType::label( $p['type'] ) : '—' ) . '</td><td>' . self::badge( $p['action'], false ) . esc_html( $p['message'] ? ' — ' . $p['message'] : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( ! empty( $p['host_text'] ) ? esc_html( ' · ospite di ' . $p['host_text'] ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $plan ) > 300 ) {
			echo '<p class="description">Mostro le prime 300 righe e tutte quelle con errori.</p>';
		}
	}

	private static function ledger_table( array $plan ): void {
		echo '<table class="widefat striped"><thead><tr><th>Riga</th><th>Data</th><th>Conto</th><th>Voce</th><th>Importo</th><th>Esito</th></tr></thead><tbody>';
		$cats  = array();
		$shown = 0;
		foreach ( Plugin::ledger()->categories() as $c ) {
			$cats[ (int) $c['id'] ] = $c['name'];
		}
		foreach ( $plan as $p ) {
			$notable = 'create' !== $p['action'] || ! empty( $p['warnings'] );
			if ( ! $notable && ++$shown > 200 ) {
				continue;
			}
			$r = $p['row'];
			$d = $p['data'];
			$title = $d && isset( $d['category_id'] ) ? ( $cats[ $d['category_id'] ] ?? '' ) : ( $d ? 'Giroconto' : '' );
			$sign  = ( $r['type'] ?? '' ) === 'expense' || ( $r['type'] ?? '' ) === 'transfer_out' ? '−' : '+';
			echo '<tr><td>' . esc_html( ( $r['sheet'] ? $r['sheet'] . ' · ' : '' ) . $r['line'] ) . '</td><td>' . esc_html( $r['date'] ? Ui::date( $r['date'] ) : '' ) . '</td><td>' . esc_html( (string) $r['account'] ) . '</td>'
				. '<td>' . esc_html( $title ?: (string) $r['category'] ) . '<br><span class="description">' . esc_html( (string) $r['desc'] ) . '</span></td>'
				. '<td>' . esc_html( $r['cents'] ? $sign . ' ' . Money::format( (int) $r['cents'] ) : '' ) . '</td>'
				. '<td>' . self::badge( $p['action'], ! empty( $p['warnings'] ) ) . esc_html( $p['message'] ? ' — ' . $p['message'] : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $p['warnings'] ? '<br><span class="description apse-warn">' . esc_html( implode( '; ', $p['warnings'] ) ) . '</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<p class="description">Mostro tutte le righe con errori o avvisi e le prime 200 delle altre. I movimenti importati si possono annullare uno per uno dalla Prima nota.</p>';
	}
}
