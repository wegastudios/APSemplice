<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibilità con WP All Import (e simili) come alternativa all'import da Excel/CSV del plugin.
 *
 * Soci, ospiti e prima nota stanno in tabelle proprie, non in articoli: per questo il plugin registra due "tipi di contenuto
 * di appoggio" che WP All Import vede e può riempire come qualunque altro. Ogni riga importata diventa un elemento
 * (con i dati nei campi personalizzati `asem_*`); a fine importazione ({@see WpAllImport::on_import_done()}) il plugin li legge,
 * li passa per le stesse regole e la stessa anteprima logica dell'import da file, li registra e toglie quelli riusciti.
 * Quelli con errori restano in elenco, con il motivo, in "Import con WP All Import".
 */
final class WpAllImport {

	const TYPE_LEDGER = 'asem_import_tx';
	const TYPE_PEOPLE = 'asem_import_person';
	const META_RESULT = 'asem_result';
	const META_MSG    = 'asem_message';

	/** Campi personalizzati => intestazione italiana riconosciuta dall'import. */
	const LEDGER_FIELDS = array(
		'asem_date' => 'Data', 'asem_type' => 'Tipo', 'asem_account' => 'Conto', 'asem_method' => 'Modalità', 'asem_category' => 'Voce',
		'asem_amount' => 'Importo', 'asem_income' => 'Entrata', 'asem_expense' => 'Uscita', 'asem_description' => 'Descrizione',
		'asem_ref' => 'Riferimento', 'asem_card' => 'N. tessera', 'asem_person' => 'Persona', 'asem_activity' => 'Attività', 'asem_month' => 'Competenza',
	);
	const PEOPLE_FIELDS = array(
		'asem_card_number' => 'Numero tessera', 'asem_member_type' => 'Tipo', 'asem_first_name' => 'Nome', 'asem_last_name' => 'Cognome',
		'asem_email' => 'Email', 'asem_phone' => 'Telefono', 'asem_tax_code' => 'Codice fiscale', 'asem_host' => 'Ospite di',
	);

	public static function register(): void {
		if ( did_action( 'init' ) ) {
			self::register_types();
		} else {
			add_action( 'init', array( __CLASS__, 'register_types' ) );
		}
		add_action( 'pmxi_after_post_import', array( __CLASS__, 'on_import_done' ), 20, 1 ); // WP All Import: a importazione finita
	}

	public static function register_types(): void {
		$caps = array();
		foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'create_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts', 'edit_private_posts', 'edit_published_posts' ) as $c ) {
			$caps[ $c ] = Plugin::CAP; // solo chi gestisce il plugin: nessun altro può "caricare" movimenti o soci
		}
		foreach ( array( self::TYPE_LEDGER => 'Movimenti AssociazioneSemplice (import)', self::TYPE_PEOPLE => 'Soci e ospiti AssociazioneSemplice (import)' ) as $type => $label ) {
			register_post_type(
				$type,
				array(
					'labels'              => array( 'name' => $label, 'singular_name' => $label ),
					'public'              => false,
					'show_ui'             => true,   // così WP All Import lo propone tra i tipi in cui importare
					'show_in_menu'        => false,
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'publicly_queryable'  => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'supports'            => array( 'title', 'custom-fields' ),
					'capabilities'        => $caps,
					'map_meta_cap'        => false,
				)
			);
		}
	}

	// ---------- Elementi in attesa ----------

	/** @return \WP_Post[] elementi da elaborare (senza esito) oppure, con $with_errors, solo quelli rimasti con un errore */
	public static function staged( string $type, bool $errors_only = false ): array {
		$q = new \WP_Query(
			array(
				'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => Limits::get( 'import_max_rows' ),
				'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'update_post_term_cache' => false,
				'meta_query' => $errors_only // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tabelle del plugin, nessuna API di WordPress equivalente
					? array( array( 'key' => self::META_RESULT, 'value' => 'error' ) )
					: array( array( 'key' => self::META_RESULT, 'compare' => 'NOT EXISTS' ) ),
			)
		);
		return $q->posts;
	}

	public static function pending_count(): int {
		return count( self::staged( self::TYPE_LEDGER ) ) + count( self::staged( self::TYPE_PEOPLE ) );
	}

	public static function on_import_done( $import_id = 0 ): void {
		if ( ! self::pending_count() ) {
			return;
		}
		try {
			self::process();
		} catch ( \Throwable $e ) {
			Audit::log( 'wpai.failed', 'import', null, array( 'error' => mb_substr( $e->getMessage(), 0, 200 ) ) ); // non deve interrompere WP All Import
		}
	}

	/** Tabella (con intestazione) dai campi personalizzati degli elementi. @return array ['rows','lines'] */
	private static function table( array $posts, array $fields ): array {
		$rows  = array( array_values( $fields ) );
		$lines = array( 0 );
		foreach ( $posts as $p ) {
			$row = array();
			foreach ( array_keys( $fields ) as $meta ) {
				$row[] = trim( (string) get_post_meta( $p->ID, $meta, true ) );
			}
			$rows[]  = $row;
			$lines[] = (int) $p->ID;
		}
		return array( 'rows' => $rows, 'lines' => $lines );
	}

	private static function allowed( \WP_Post $p ): bool {
		$author = (int) $p->post_author;
		return 0 === $author || user_can( $author, Plugin::CAP ); // l'importazione parte da un amministratore
	}

	private static function mark( int $post_id, string $message ): void {
		update_post_meta( $post_id, self::META_RESULT, 'error' );
		update_post_meta( $post_id, self::META_MSG, mb_substr( $message, 0, 250 ) );
	}

	/** @return array ['people'=>int, 'ledger'=>int, 'duplicates'=>int, 'errors'=>int] */
	public static function process(): array {
		$out  = array( 'people' => 0, 'ledger' => 0, 'duplicates' => 0, 'errors' => 0 );
		$opts = array(
			'default_type' => (string) Settings::get( 'wpai_default_type' ), 'default_account_id' => (int) Settings::get( 'wpai_default_account_id' ),
			'mark_members' => (bool) Settings::get( 'wpai_mark_members' ), 'keep_balances' => (bool) Settings::get( 'wpai_keep_balances' ),
		);
		$batches = array();
		foreach ( array( self::TYPE_PEOPLE => self::PEOPLE_FIELDS, self::TYPE_LEDGER => self::LEDGER_FIELDS ) as $type => $fields ) {
			$posts = array();
			foreach ( self::staged( $type ) as $p ) {
				if ( self::allowed( $p ) ) {
					$posts[] = $p;
				} else {
					self::mark( (int) $p->ID, 'L\'autore di questo elemento non può gestire il plugin: non importato.' );
					$out['errors']++;
				}
			}
			if ( $posts ) {
				$batches[ $type ] = array( 'posts' => $posts, 'table' => self::table( $posts, $fields ) );
			}
		}
		foreach ( $batches as $type => $b ) {
			$kind  = self::TYPE_PEOPLE === $type ? 'people' : 'ledger';
			$sheet = array( 'name' => 'WP All Import', 'rows' => $b['table']['rows'], 'lines' => $b['table']['lines'] );
			try {
				$preview = ImportService::preview_sheets( array( $sheet ), $opts );
				$res     = ImportService::apply( $preview, array_merge( $opts, array( 'source' => 'WP All Import' ) ) );
			} catch ( \Throwable $e ) {
				foreach ( $b['posts'] as $p ) {
					self::mark( (int) $p->ID, $e->getMessage() );
					$out['errors']++;
				}
				continue;
			}
			$failed = array();
			foreach ( (array) ( $res[ $kind ]['failed'] ?? array() ) as $f ) {
				if ( preg_match( '/riga (\d+): (.*)$/', (string) $f, $m ) ) {
					$failed[ (int) $m[1] ] = $m[2];
				}
			}
			$actions = array();
			foreach ( (array) ( $preview[ $kind ]['plan'] ?? array() ) as $pl ) {
				$actions[ (int) $pl['row']['line'] ] = array( $pl['action'], (string) $pl['message'] );
			}
			foreach ( $b['posts'] as $p ) {
				$id = (int) $p->ID;
				list( $action, $msg ) = $actions[ $id ] ?? array( 'error', 'Riga non letta' );
				if ( isset( $failed[ $id ] ) ) {
					self::mark( $id, $failed[ $id ] );
					$out['errors']++;
				} elseif ( in_array( $action, array( 'error', 'skip' ), true ) ) {
					self::mark( $id, $msg );
					$out['errors']++;
				} else {
					'duplicate' === $action ? $out['duplicates']++ : $out[ $kind ]++;
					wp_delete_post( $id, true );
				}
			}
		}
		Audit::log( 'wpai.processed', 'import', null, $out );
		return $out;
	}

	/** Rimette in coda gli elementi con errore (dopo aver corretto, ad esempio, le impostazioni). */
	public static function retry(): int {
		$n = 0;
		foreach ( array( self::TYPE_PEOPLE, self::TYPE_LEDGER ) as $type ) {
			foreach ( self::staged( $type, true ) as $p ) {
				delete_post_meta( $p->ID, self::META_RESULT );
				delete_post_meta( $p->ID, self::META_MSG );
				$n++;
			}
		}
		return $n;
	}

	/** Elimina gli elementi rimasti con un errore. */
	public static function clear_errors(): int {
		$n = 0;
		foreach ( array( self::TYPE_PEOPLE, self::TYPE_LEDGER ) as $type ) {
			foreach ( self::staged( $type, true ) as $p ) {
				wp_delete_post( $p->ID, true );
				$n++;
			}
		}
		return $n;
	}
}
