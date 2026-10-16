<?php
namespace Portale;

/**
 * Pacchetti del plugin (gratuito e Pro) ospitati dal portale, ultime N versioni per tornare indietro.
 * I file stanno fuori dalla cartella pubblica (`data/releases/`) e si scaricano solo da {@see Releases::stream}
 * (`/scarica/{prodotto}/{versione}`), con accesso eseguito e licenza in regola: non esiste nessun indirizzo pubblico da condividere.
 */
final class Releases {

	/** prodotto → [cartella nel pacchetto, nome mostrato] */
	const PRODUCTS = array(
		'free' => array( 'associazionesemplice', 'Associazione Semplice (gratuito)' ),
		'pro'  => array( 'associazionesemplice-pro', 'Associazione Semplice Pro' ),
	);

	public static function dir( string $product = '' ): string {
		$base = (string) Config::get( 'releases_dir', dirname( __DIR__ ) . '/data/releases' );
		return $base . ( '' !== $product ? '/' . $product : '' );
	}

	/**
	 * Aggiunge una versione leggendo numero e cartella dal pacchetto. Una versione già presente non si sovrascrive.
	 * @return array riga inserita
	 */
	public static function add( Db $db, string $product, string $tmpFile, string $note, string $actor, ?int $now = null ): array {
		if ( ! isset( self::PRODUCTS[ $product ] ) ) {
			throw new UserError( 'Prodotto non valido.' );
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new UserError( 'Sul server manca l\'estensione zip di PHP.' );
		}
		$slug = self::PRODUCTS[ $product ][0];
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $tmpFile ) ) {
			throw new UserError( 'Il file non è uno zip valido.' );
		}
		$main = (string) $zip->getFromName( $slug . '/' . $slug . '.php' );
		$zip->close();
		if ( '' === $main ) {
			throw new UserError( 'Il pacchetto non contiene ' . $slug . '/' . $slug . '.php: controlla di aver scelto il file giusto.' );
		}
		$version = self::headerVersion( $main );
		if ( '' === $version ) {
			throw new UserError( 'Non trovo la versione nell\'intestazione del plugin.' );
		}
		if ( $db->one( 'SELECT 1 AS x FROM releases WHERE product = ? AND version = ?', array( $product, $version ) ) ) {
			throw new UserError( 'La versione ' . $version . ' è già presente: aumenta il numero di versione per pubblicarne una nuova.' );
		}
		$dir = self::dir( $product );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) ) {
			throw new UserError( 'Non riesco a creare la cartella dei pacchetti.' );
		}
		$dest = $dir . '/' . $version . '.zip';
		if ( ! copy( $tmpFile, $dest ) ) {
			throw new UserError( 'Non riesco a salvare il pacchetto.' );
		}
		@chmod( $dest, 0640 );
		$row = array(
			'product' => $product, 'version' => $version, 'size' => (int) filesize( $dest ), 'sha256' => hash_file( 'sha256', $dest ),
			'note' => mb_substr( trim( $note ), 0, 500 ), 'created_at' => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
		);
		$db->run( 'INSERT INTO releases (product, version, size, sha256, note, created_at) VALUES (?,?,?,?,?,?)', array_values( $row ) );
		Audit::log( $db, $actor, 'release.add', $product . ' ' . $version );
		self::prune( $db, $product, $actor );
		return $row;
	}

	/** Numero di versione dall'intestazione del file principale del plugin («Version: 1.2.3»). */
	public static function headerVersion( string $source ): string {
		return preg_match( '/^[ \t\/*#@]*Version:\s*(\d+\.\d+\.\d+)\s*$/mi', substr( $source, 0, 8192 ), $m ) ? $m[1] : '';
	}

	/** Versioni del prodotto, dalla più recente. */
	public static function all( Db $db, string $product ): array {
		$rows = $db->all( 'SELECT * FROM releases WHERE product = ?', array( $product ) );
		usort( $rows, function ( $a, $b ) { return version_compare( $b['version'], $a['version'] ); } );
		return $rows;
	}

	public static function get( Db $db, string $product, string $version ): ?array {
		return $db->one( 'SELECT * FROM releases WHERE product = ? AND version = ?', array( $product, $version ) );
	}

	/** Tiene le ultime N versioni (limite «releases_keep»); le altre si cancellano. */
	public static function prune( Db $db, string $product, string $actor ): void {
		foreach ( array_slice( self::all( $db, $product ), Settings::limit( 'releases_keep' ) ) as $old ) {
			self::remove( $db, $product, $old['version'], $actor );
		}
	}

	public static function remove( Db $db, string $product, string $version, string $actor ): void {
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! isset( self::PRODUCTS[ $product ] ) ) {
			return;
		}
		$db->run( 'DELETE FROM releases WHERE product = ? AND version = ?', array( $product, $version ) );
		@unlink( self::dir( $product ) . '/' . $version . '.zip' );
		Audit::log( $db, $actor, 'release.remove', $product . ' ' . $version );
	}

	/**
	 * Prodotti che il cliente può scaricare: il gratuito con qualunque licenza in regola, il Pro con un piano a pagamento
	 * (o in prova) in regola. Una licenza scaduta o con pagamento non riuscito non dà accesso.
	 * @param array $subs licenze del cliente
	 * @return string[]
	 */
	public static function entitled( array $subs ): array {
		$out = array();
		foreach ( $subs as $s ) {
			if ( 'Attiva' !== sub_status( $s )[0] ) {
				continue;
			}
			$out['free'] = 'free';
			if ( 'free' !== $s['plan_code'] ) {
				$out['pro'] = 'pro';
			}
		}
		return array_values( $out );
	}

	/** Scarichi del cliente nelle ultime 24 ore. */
	public static function recentDownloads( Db $db, string $customerId, ?int $now = null ): int {
		$r = $db->one( 'SELECT COUNT(*) AS n FROM downloads WHERE customer_id = ? AND at >= ?', array( $customerId, gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - 86400 ) ) );
		return $r ? (int) $r['n'] : 0;
	}

	/** Controlla accesso e limite, registra lo scarico e restituisce il percorso del file. @throws UserError */
	public static function authorize( Db $db, string $customerId, bool $isAdmin, array $subs, string $product, string $version, ?int $now = null ): string {
		$rel = self::get( $db, $product, $version );
		if ( ! $rel ) {
			throw new UserError( 'Questa versione non è disponibile.' );
		}
		if ( ! $isAdmin ) {
			if ( ! in_array( $product, self::entitled( $subs ), true ) ) {
				throw new UserError( 'Per scaricare questo pacchetto serve una licenza attiva' . ( 'pro' === $product ? ' di un piano Pro.' : '.' ) );
			}
			if ( self::recentDownloads( $db, $customerId, $now ) >= Settings::limit( 'download_day' ) ) {
				throw new UserError( 'Hai raggiunto il limite di scarichi di oggi: riprova domani.' );
			}
		}
		$file = self::dir( $product ) . '/' . $rel['version'] . '.zip';
		if ( ! is_file( $file ) ) {
			throw new UserError( 'Il file di questa versione non è più sul server.' );
		}
		$db->run( 'INSERT INTO downloads (id, customer_id, at, product, version) VALUES (?,?,?,?,?)', array( Db::id(), $customerId, gmdate( 'Y-m-d H:i:s', $now ?? time() ), $product, $rel['version'] ) );
		return $file;
	}

	/** Invia il file al browser. */
	public static function stream( string $file, string $product, string $version ): void {
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . self::PRODUCTS[ $product ][0] . '-' . $version . '.zip"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
	}
}
