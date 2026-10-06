<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Copia di sicurezza: un file ZIP con tutti i dati del plugin (una tabella per file, una riga per record in formato JSON), le impostazioni
 * (senza le chiavi segrete dei pagamenti, che restano cifrate sul sito) e, se vuoi, gli allegati. Si scarica con un clic e si può
 * ripristinare sullo stesso sito: i dati attuali vengono sostituiti da quelli della copia, dopo averne fatta una di sicurezza.
 */
final class Backup {

	const OPT_LAST   = 'apse_backup_last';
	const OPT_NAMES  = array( 'apse_texts', 'apse_card_salt', 'apse_ical_salt', 'apse_access_requests' );
	const KEEP       = 3;
	const CHUNK      = 1000;
	const MAX_ENTRY  = 1073741824; // 1 GB per file dentro lo zip

	public static function register(): void {
		add_action( 'admin_post_apse_backup', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_apse_backup_saved', array( __CLASS__, 'handle_saved' ) );
	}

	// ---------- Cartella delle copie di sicurezza ----------

	public static function dir(): string {
		return rtrim( Attachments::dir(), '/\\' ) . '/backups';
	}

	private static function ensure_dir(): string {
		Attachments::prepare_dir();
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Non riesco a creare la cartella delle copie di sicurezza: controlla i permessi di wp-content/uploads.' );
		}
		return $dir;
	}

	/** Copie salvate sul sito (quelle fatte prima di un ripristino), dalla più recente. @return array[] name, size, time */
	public static function saved(): array {
		$out = array();
		foreach ( glob( self::dir() . '/*.zip' ) ?: array() as $f ) {
			if ( preg_match( '/^prima-del-ripristino-(\d{8}-\d{6})\.zip$/', basename( $f ), $m ) ) { // copie fatte da versioni precedenti, con il nome prevedibile
				$new = dirname( $f ) . '/prima-del-ripristino-' . $m[1] . '-' . bin2hex( random_bytes( 8 ) ) . '.zip';
				if ( @rename( $f, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$f = $new;
				}
			}
			$out[] = array( 'name' => basename( $f ), 'size' => (int) filesize( $f ), 'time' => (int) filemtime( $f ) );
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		return $out;
	}

	public static function last(): ?int {
		$v = (int) get_option( self::OPT_LAST, 0 );
		return $v > 0 ? $v : null;
	}

	// ---------- Contenuto ----------

	/** Nomi delle tabelle del plugin (senza il prefisso del database). @return string[] */
	public static function tables(): array {
		$db     = Db::db();
		$prefix = $db->prefix . 'apse_';
		$out    = array();
		foreach ( $db->get_col( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $prefix ) . '%' ) ) ?: array() as $t ) {
			$out[] = substr( (string) $t, strlen( $db->prefix ) );
		}
		sort( $out );
		return $out;
	}

	private static function settings_payload(): array {
		$s = Settings::all();
		foreach ( Settings::SECRET_KEYS as $k ) {
			unset( $s[ $k ] ); // le chiavi dei gateway restano sul sito
		}
		$opts = array();
		foreach ( self::OPT_NAMES as $n ) {
			$opts[ $n ] = get_option( $n, null );
		}
		return array( 'settings' => $s, 'options' => $opts );
	}

	/**
	 * Crea la copia e ne restituisce il percorso (file temporaneo o, con $to, il file indicato).
	 *
	 * @throws \RuntimeException
	 */
	public static function export( bool $with_files = false, ?string $to = null ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'Questo server non può creare file ZIP (manca l\'estensione zip di PHP).' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$db    = Db::db();
		$path  = $to ?: wp_tempnam( 'apse-copia' );
		$zip   = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Non riesco a creare il file della copia.' );
		}
		$tmp    = array();
		$counts = array();
		foreach ( self::tables() as $name ) {
			$tbl = $db->prefix . $name;
			$f   = wp_tempnam( 'apse-t' );
			$tmp[] = $f;
			$h   = fopen( $f, 'wb' );
			$n   = 0;
			for ( $off = 0; ; $off += self::CHUNK ) {
				$rows = $db->get_results( $db->prepare( "SELECT * FROM `$tbl` ORDER BY 1 LIMIT %d OFFSET %d", self::CHUNK, $off ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $r ) {
					fwrite( $h, wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
					$n++;
				}
			}
			fclose( $h );
			$counts[ $name ] = $n;
			$zip->addFile( $f, 'tabelle/' . $name . '.jsonl' );
		}
		$files = array();
		if ( $with_files ) {
			foreach ( glob( Attachments::dir() . '/*' ) ?: array() as $f ) {
				if ( is_file( $f ) && preg_match( '/^[0-9a-f]{32}$/', basename( $f ) ) ) {
					$zip->addFile( $f, 'allegati/' . basename( $f ) );
					$files[] = basename( $f );
				}
			}
		}
		$zip->addFromString( 'impostazioni.json', (string) wp_json_encode( self::settings_payload(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$zip->addFromString(
			'manifest.json',
			(string) wp_json_encode(
				array(
					'plugin' => 'apsemplice', 'version' => APSE_VERSION, 'db_version' => (string) get_option( Install::DB_VERSION_OPTION, Install::DB_VERSION ), 'created_at' => Db::now(),
					'site' => home_url(), 'prefix' => $db->prefix, 'tables' => $counts, 'attachments' => count( $files ),
				),
				JSON_PRETTY_PRINT
			)
		);
		$zip->addFromString( 'LEGGIMI.txt', "Copia di sicurezza di APSemplice.\nContiene una tabella per file (tabelle/*.jsonl, una riga JSON per record), le impostazioni (impostazioni.json) e, se scelto, gli allegati.\nSi ripristina da Impostazioni → Copia di sicurezza, sullo stesso sito.\nLe chiavi segrete dei pagamenti online non sono incluse.\n" );
		$ok = $zip->close();
		foreach ( $tmp as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok || ! is_file( $path ) ) {
			throw new \RuntimeException( 'Creazione della copia non riuscita.' );
		}
		update_option( self::OPT_LAST, time(), false );
		Audit::log( 'backup.created', 'settings', 0, array( 'tables' => count( $counts ), 'attachments' => count( $files ) ) );
		return $path;
	}

	// ---------- Ripristino ----------

	/** Controlla il file e ne legge l'indice. @throws \InvalidArgumentException */
	public static function inspect( string $zip_path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \InvalidArgumentException( 'Questo server non può leggere file ZIP.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza valida.' );
		}
		$raw = $zip->getFromName( 'manifest.json' );
		$m   = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $m ) || 'apsemplice' !== ( $m['plugin'] ?? '' ) || empty( $m['tables'] ) ) {
			$zip->close();
			throw new \InvalidArgumentException( 'Il file non è una copia di sicurezza di APSemplice.' );
		}
		if ( (int) ( $m['db_version'] ?? 0 ) > (int) Install::DB_VERSION ) {
			$zip->close();
			throw new \InvalidArgumentException( 'La copia è stata fatta con una versione più recente del plugin: aggiorna prima il plugin.' );
		}
		$known = self::tables();
		foreach ( array_keys( $m['tables'] ) as $t ) {
			if ( ! preg_match( '/^apse_[a-z_]+$/', (string) $t ) || ! in_array( $t, $known, true ) ) {
				$zip->close();
				throw new \InvalidArgumentException( 'La copia contiene una tabella sconosciuta (' . esc_html( (string) $t ) . ').' );
			}
			$st = $zip->statName( 'tabelle/' . $t . '.jsonl' );
			if ( ! $st || (int) $st['size'] > self::MAX_ENTRY ) {
				$zip->close();
				throw new \InvalidArgumentException( 'La copia è incompleta: manca la tabella ' . $t . '.' );
			}
		}
		$zip->close();
		return $m;
	}

	/** Colonne di una tabella. @return string[] */
	private static function columns( string $tbl ): array {
		return array_map( function ( $c ) {
			return (string) $c['Field'];
		}, Db::db()->get_results( "SHOW COLUMNS FROM `$tbl`", ARRAY_A ) ?: array() );
	}

	private static function insert_rows( string $tbl, array $cols, array $rows ): void {
		$db     = Db::db();
		$values = array();
		foreach ( $rows as $r ) {
			$vals = array();
			foreach ( $cols as $c ) {
				$v      = $r[ $c ] ?? null;
				$vals[] = null === $v ? 'NULL' : $db->prepare( '%s', (string) $v );
			}
			$values[] = '(' . implode( ',', $vals ) . ')';
		}
		if ( false === $db->query( "INSERT INTO `$tbl` (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ',', $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			throw new \RuntimeException( 'Errore del database durante il ripristino: ' . $db->last_error );
		}
	}

	/**
	 * Sostituisce i dati del plugin con quelli della copia. Prima salva una copia di sicurezza sul sito; il ripristino è tutto o niente.
	 *
	 * @return array tables, rows, files, safety (nome del file di sicurezza)
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function restore( string $zip_path ): array {
		$m = self::inspect( $zip_path );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$dir    = self::ensure_dir();
		$safety = $dir . '/prima-del-ripristino-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.zip'; // nome non indovinabile: la cartella potrebbe essere raggiungibile da web (nginx ignora .htaccess)
		self::export( true, $safety );
		$old = self::saved();
		foreach ( array_slice( $old, self::KEEP ) as $o ) {
			@unlink( $dir . '/' . $o['name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$db  = Db::db();
		$zip = new \ZipArchive();
		$zip->open( $zip_path );
		$rows_total = 0;
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( array_keys( $m['tables'] ) as $name ) {
				$tbl  = $db->prefix . $name;
				$cols = self::columns( $tbl );
				$db->query( "DELETE FROM `$tbl`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				$h = $zip->getStream( 'tabelle/' . $name . '.jsonl' );
				if ( ! $h ) {
					throw new \RuntimeException( 'Non riesco a leggere la tabella ' . $name . '.' );
				}
				$batch = array();
				$use   = null;
				while ( false !== ( $line = fgets( $h ) ) ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$r = json_decode( $line, true );
					if ( ! is_array( $r ) ) {
						throw new \RuntimeException( 'Riga non valida nella tabella ' . $name . '.' );
					}
					if ( null === $use ) {
						$use = array_values( array_intersect( array_keys( $r ), $cols ) ); // solo le colonne che esistono ancora
					}
					$batch[] = $r;
					$rows_total++;
					if ( count( $batch ) >= 100 ) {
						self::insert_rows( $tbl, $use, $batch );
						$batch = array();
					}
				}
				fclose( $h );
				if ( $batch ) {
					self::insert_rows( $tbl, $use, $batch );
				}
			}
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			$zip->close();
			throw new \RuntimeException( $e->getMessage() . ' Nessun dato è stato cambiato.' );
		}
		// impostazioni (le chiavi segrete restano quelle del sito) e opzioni collegate
		$raw = $zip->getFromName( 'impostazioni.json' );
		$p   = $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $p ) ) {
			$new = is_array( $p['settings'] ?? null ) ? $p['settings'] : array();
			foreach ( Settings::SECRET_KEYS as $k ) {
				unset( $new[ $k ] ); // le chiavi segrete restano quelle del sito (e Settings::update le lascia com'è)
			}
			Settings::update( $new ); // passa dai controlli delle impostazioni: una copia modificata non può inserire valori fuori regola
			foreach ( self::OPT_NAMES as $n ) {
				if ( isset( $p['options'][ $n ] ) && null !== $p['options'][ $n ] ) {
					update_option( $n, $p['options'][ $n ], false );
				}
			}
		}
		// allegati
		$files = 0;
		if ( ! empty( $m['attachments'] ) ) {
			Attachments::prepare_dir();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( preg_match( '#^allegati/([0-9a-f]{32})$#', $name, $mm ) ) {
					$data = $zip->getFromIndex( $i );
					if ( false !== $data && false !== file_put_contents( Attachments::dir() . '/' . $mm[1], $data ) ) {
						$files++;
					}
				}
			}
		}
		$zip->close();
		Texts::flush();
		Terms::flush();
		Audit::log( 'backup.restored', 'settings', 0, array( 'rows' => $rows_total, 'attachments' => $files ) );
		return array( 'tables' => count( $m['tables'] ), 'rows' => $rows_total, 'files' => $files, 'safety' => basename( $safety ) );
	}

	// ---------- Download ----------

	public static function download_url( bool $with_files ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_backup', 'files' => $with_files ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'apse_backup' );
	}

	public static function saved_url( string $name ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_backup_saved', 'name' => $name ), admin_url( 'admin-post.php' ) ), 'apse_backup_saved' );
	}

	private static function send_zip( string $path, string $filename, bool $delete ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		if ( $delete ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		exit;
	}

	public static function handle_download(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'apse_backup' );
		$with = isset( $_GET['files'] ) && '1' === (string) $_GET['files']; // phpcs:ignore WordPress.Security
		try {
			$path = self::export( $with );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}
		self::send_zip( $path, 'copia-apsemplice-' . gmdate( 'Y-m-d-His' ) . '.zip', true );
	}

	public static function handle_saved(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'apse_backup_saved' );
		$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$path = self::dir() . '/' . $name;
		if ( ! preg_match( '/^prima-del-ripristino-\d{8}-\d{6}(-[0-9a-f]{16})?\.zip$/', $name ) || ! is_file( $path ) ) {
			wp_die( 'Copia non trovata.', 404 );
		}
		self::send_zip( $path, $name, false );
	}
}
