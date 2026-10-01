<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Allegati dei movimenti (scontrini, fatture): PDF e foto.
 *
 * Non passano dalla libreria media di WordPress (niente caos in galleria, niente indirizzi pubblici):
 * stanno in una cartella privata dentro `uploads`, con nome casuale e senza estensione, e si aprono solo
 * da qui, con il controllo dei permessi. Un allegato non si cancella mai di nascosto: "rimuovere" lo toglie
 * dall'elenco e lo registra nel registro azioni, il file resta sul disco.
 */
final class Attachments {

	const DIR_NAME = 'apsemplice-private';

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Cartella privata ----------

	public static function dir(): string {
		$u = wp_upload_dir( null, false );
		return rtrim( (string) $u['basedir'], '/\\' ) . '/' . self::DIR_NAME;
	}

	private static function ensure_dir(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \InvalidArgumentException( 'Non riesco a creare la cartella per gli allegati: controlla i permessi di wp-content/uploads.' );
		}
		// Apache: accesso diretto negato. Altri server: i nomi sono casuali, senza estensione e la cartella non si elenca.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	private static function real_mime( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$f = finfo_open( FILEINFO_MIME_TYPE );
		if ( ! $f ) {
			return '';
		}
		$m = (string) finfo_file( $f, $path );
		finfo_close( $f );
		return $m;
	}

	// ---------- Controllo e salvataggio ----------

	/**
	 * Controlla i file caricati (campo `<input type="file" multiple>`) PRIMA di registrare qualsiasi cosa.
	 *
	 * @param array|null $files struttura di $_FILES per un campo
	 * @param int        $tx_id movimento a cui si allegano (0 se ancora da creare): serve per limite e doppioni
	 * @return array[] file pronti per add()
	 * @throws \InvalidArgumentException
	 */
	public static function prepare( ?array $files, int $tx_id = 0 ): array {
		$n = AttachmentRules::normalize_files( $files );
		if ( $n['errors'] ) {
			throw new \InvalidArgumentException( $n['errors'][0] );
		}
		$existing = $tx_id ? array_column( self::list_for( $tx_id ), 'sha256' ) : array();
		$count    = $tx_id ? count( $existing ) : 0;
		$out      = array();
		$seen     = array();
		foreach ( $n['files'] as $f ) {
			$path = $f['tmp_name'];
			if ( '' === $path || ! is_readable( $path ) ) {
				throw new \InvalidArgumentException( 'Caricamento di "' . AttachmentRules::display_name( $f['name'] ) . '" non riuscito: riprova.' );
			}
			$size = (int) filesize( $path );
			$mime = self::real_mime( $path );
			$err  = AttachmentRules::check( $f['name'], $size, $mime );
			if ( $err ) {
				throw new \InvalidArgumentException( $err );
			}
			$hash = (string) hash_file( 'sha256', $path );
			if ( in_array( $hash, $existing, true ) || isset( $seen[ $hash ] ) ) {
				throw new \InvalidArgumentException( 'Il file "' . AttachmentRules::display_name( $f['name'] ) . '" è già allegato.' );
			}
			$seen[ $hash ] = true;
			$out[]         = array( 'name' => AttachmentRules::display_name( $f['name'] ), 'tmp_name' => $path, 'size' => $size, 'mime' => $mime, 'sha256' => $hash );
		}
		if ( $count + count( $out ) > AttachmentRules::MAX_PER_TX ) {
			throw new \InvalidArgumentException( 'Al massimo ' . AttachmentRules::MAX_PER_TX . ' allegati per movimento.' );
		}
		return $out;
	}

	/** Salva i file già controllati con prepare(). @return int[] id degli allegati */
	public static function add( int $tx_id, array $prepared ): array {
		if ( ! $prepared ) {
			return array();
		}
		$tx = self::db()->get_row( self::db()->prepare( 'SELECT id, voided_at FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx_id ), ARRAY_A );
		if ( ! $tx || $tx['voided_at'] ) {
			throw new \InvalidArgumentException( 'Movimento non trovato o annullato.' );
		}
		$dir = self::ensure_dir();
		$ids = array();
		foreach ( $prepared as $f ) {
			$stored = AttachmentRules::stored_name( bin2hex( random_bytes( 16 ) ) );
			$target = $dir . '/' . $stored;
			$ok     = is_uploaded_file( $f['tmp_name'] ) ? move_uploaded_file( $f['tmp_name'], $target ) : copy( $f['tmp_name'], $target );
			if ( ! $ok ) {
				throw new \InvalidArgumentException( 'Non riesco a salvare "' . $f['name'] . '".' );
			}
			chmod( $target, 0640 );
			self::db()->insert(
				Db::t( 'attachments' ),
				array(
					'transaction_id' => $tx_id, 'original_name' => $f['name'], 'stored_name' => $stored, 'mime' => $f['mime'], 'size_bytes' => $f['size'],
					'sha256' => $f['sha256'], 'uploaded_by' => get_current_user_id() ?: null, 'created_at' => Db::now(),
				)
			);
			$id    = (int) self::db()->insert_id;
			$ids[] = $id;
			Audit::log( 'attachment.added', 'transaction', $tx_id, array( 'attachment' => $id, 'bytes' => $f['size'], 'type' => $f['mime'] ) );
		}
		return $ids;
	}

	// ---------- Lettura ----------

	public static function get( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Allegati attivi di un movimento, in ordine di caricamento. */
	public static function list_for( int $tx_id ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT * FROM ' . Db::t( 'attachments' ) . ' WHERE transaction_id = %d AND removed_at IS NULL ORDER BY id', $tx_id ),
			ARRAY_A
		) ?: array();
	}

	/** @param int[] $tx_ids @return array<int,array[]> movimento => allegati attivi */
	public static function map_for( array $tx_ids ): array {
		$tx_ids = array_values( array_filter( array_map( 'intval', $tx_ids ) ) );
		if ( ! $tx_ids ) {
			return array();
		}
		$in   = implode( ',', $tx_ids );
		$rows = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'attachments' ) . " WHERE removed_at IS NULL AND transaction_id IN ($in) ORDER BY id", ARRAY_A ) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['transaction_id'] ][] = $r;
		}
		return $out;
	}

	public static function path_of( array $a ): string {
		return self::dir() . '/' . AttachmentRules::stored_name( (string) $a['stored_name'] );
	}

	public static function remove( int $id ): void {
		$a = self::get( $id );
		if ( ! $a || $a['removed_at'] ) {
			throw new \InvalidArgumentException( 'Allegato non trovato.' );
		}
		self::db()->update( Db::t( 'attachments' ), array( 'removed_at' => Db::now(), 'removed_by' => get_current_user_id() ?: null ), array( 'id' => $id ) );
		Audit::log( 'attachment.removed', 'transaction', (int) $a['transaction_id'], array( 'attachment' => $id ) );
	}

	// ---------- Apertura (solo con permesso) ----------

	public static function url( int $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_attachment', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'apse_attachment_' . $id );
	}

	public static function handle_download(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'apse_attachment_' . $id );
		$a    = self::get( $id );
		$path = $a ? self::path_of( $a ) : '';
		if ( ! $a || $a['removed_at'] || '' === basename( $path ) || ! is_readable( $path ) ) {
			wp_die( 'Allegato non trovato.', 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		$name = AttachmentRules::display_name( $a['original_name'] );
		header( 'Content-Type: ' . $a['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path );
		exit;
	}
}
