<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) sugli allegati dei movimenti: tipi ammessi, dimensione, nomi. Il resto sta in Attachments. */
final class AttachmentRules {

	const MAX_BYTES  = 10485760; // 10 MB per file (le foto vengono ridotte dal telefono prima dell'invio)
	const MAX_PER_TX = 10;

	/** Tipo reale del file (letto dal contenuto) => estensioni ammesse. */
	const TYPES = array(
		'application/pdf' => array( 'pdf' ),
		'image/jpeg'      => array( 'jpg', 'jpeg' ),
		'image/png'       => array( 'png' ),
		'image/webp'      => array( 'webp' ),
		'image/heic'      => array( 'heic' ),
		'image/heif'      => array( 'heif', 'heic' ),
	);

	public static function extension( string $name ): string {
		return strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	/** @return string|null messaggio d'errore, oppure null se il file va bene */
	public static function check( string $name, int $size, string $real_mime, int $max_bytes = self::MAX_BYTES ): ?string {
		if ( $size <= 0 ) {
			return 'Il file "' . self::display_name( $name ) . '" è vuoto.';
		}
		if ( $size > $max_bytes ) {
			return 'Il file "' . self::display_name( $name ) . '" supera i ' . (int) ( $max_bytes / 1048576 ) . ' MB.';
		}
		$ext = self::extension( $name );
		if ( ! isset( self::TYPES[ $real_mime ] ) || ! in_array( $ext, self::TYPES[ $real_mime ], true ) ) {
			return 'Il file "' . self::display_name( $name ) . '" non è ammesso: si accettano PDF e foto (JPG, PNG, WebP, HEIC).';
		}
		return null;
	}

	/** Nome da mostrare: senza percorsi né caratteri di controllo, lunghezza limitata. */
	public static function display_name( string $name ): string {
		$name = str_replace( array( '\\', '/' ), '/', $name );
		$name = basename( $name );
		$name = preg_replace( '/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name ) ?? '';
		$name = trim( $name );
		if ( '' === $name ) {
			return 'documento';
		}
		if ( strlen( $name ) > 120 ) {
			$ext  = self::extension( $name );
			$name = mb_strcut( $name, 0, 110, 'UTF-8' ) . ( '' !== $ext ? '.' . $ext : '' );
		}
		return $name;
	}

	/** Nome sul disco: casuale (32 caratteri esadecimali) e senza estensione, quindi non indovinabile né eseguibile. */
	public static function stored_name( string $random_hex ): string {
		return preg_match( '/^[0-9a-f]{32}$/', $random_hex ) ? $random_hex : '';
	}

	public static function format_size( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1, ',', '' ) . ' MB';
		}
		return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}

	/**
	 * Porta in un elenco di file uno o più file di un campo `<input type="file" multiple>` (struttura di $_FILES).
	 *
	 * @return array ['files' => [['name','tmp_name','size'], ...], 'errors' => string[]]
	 */
	public static function normalize_files( ?array $f ): array {
		$out = array( 'files' => array(), 'errors' => array() );
		if ( ! $f || ! isset( $f['name'] ) ) {
			return $out;
		}
		$names = (array) $f['name'];
		foreach ( $names as $i => $name ) {
			$err = (int) ( is_array( $f['error'] ) ? $f['error'][ $i ] : $f['error'] );
			if ( UPLOAD_ERR_NO_FILE === $err ) {
				continue;
			}
			$label = self::display_name( (string) $name );
			if ( UPLOAD_ERR_OK !== $err ) {
				$out['errors'][] = in_array( $err, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
					? 'Il file "' . $label . '" è troppo grande per il server.'
					: 'Caricamento di "' . $label . '" non riuscito: riprova.';
				continue;
			}
			$out['files'][] = array(
				'name'     => (string) $name,
				'tmp_name' => (string) ( is_array( $f['tmp_name'] ) ? $f['tmp_name'][ $i ] : $f['tmp_name'] ),
				'size'     => (int) ( is_array( $f['size'] ) ? $f['size'][ $i ] : $f['size'] ),
			);
		}
		return $out;
	}
}
