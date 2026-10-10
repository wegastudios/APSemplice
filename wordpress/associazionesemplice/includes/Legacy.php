<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Chi aveva installato il plugin con il vecchio nome (APSemplice, prefisso `apse_`) non perde nulla: al primo avvio tabelle, opzioni,
 * ruoli, metadati, lavori periodici e shortcode passano al nuovo nome (AssociazioneSemplice, prefisso `asem_`). Il passaggio è unico e sicuro da ripetere.
 */
final class Legacy {

	/** Opzione che c'era già con il vecchio nome ma non ancora con il nuovo: c'è un'installazione da convertire. */
	public static function pending(): bool {
		return false !== get_option( 'apse_db_version', false ) && false === get_option( Install::DB_VERSION_OPTION, false );
	}

	public static function run(): void {
		if ( self::pending() ) {
			self::convert();
		}
	}

	/** Esegue la conversione dei dati dal vecchio nome al nuovo. */
	public static function convert(): void {
		$db = Db::db();
		self::tables( $db );
		self::options( $db );
		self::roles( $db );
		self::metadata( $db );
		self::content( $db );
		self::cron();
		self::folder();
		self::deactivate_old_plugins();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$GLOBALS['wp_roles'] = null; // i ruoli si rileggono dal database
		wp_roles();
	}

	private static function tables( \wpdb $db ): void {
		$old_prefix = $db->prefix . 'apse_';
		$names      = $db->get_col( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $old_prefix ) . '%' ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- una tantum
		foreach ( $names as $old ) {
			$suffix = substr( (string) $old, strlen( $old_prefix ) );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $suffix ) ) {
				continue;
			}
			$new = $db->prefix . 'asem_' . $suffix;
			if ( $new === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $new ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- una tantum
				continue; // esiste già con il nuovo nome: non si tocca
			}
			$db->query( "RENAME TABLE `$old` TO `$new`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nomi di tabella controllati sopra
		}
	}

	private static function options( \wpdb $db ): void {
		$o = $db->options;
		$db->query( $db->prepare( "UPDATE IGNORE $o SET option_name = CONCAT(%s, SUBSTRING(option_name, 6)) WHERE option_name LIKE %s", 'asem_', $db->esc_like( 'apse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
		$db->query( $db->prepare( "DELETE FROM $o WHERE option_name LIKE %s OR option_name LIKE %s", $db->esc_like( '_transient_apse_' ) . '%', $db->esc_like( '_transient_timeout_apse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
	}

	/** Ruoli e permessi: `apse_` e `asem_` hanno la stessa lunghezza, quindi il testo serializzato resta valido. */
	private static function roles( \wpdb $db ): void {
		$o = $db->options;
		$u = $db->usermeta;
		$db->query( $db->prepare( "UPDATE $o SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = %s", 'apse_', 'asem_', $db->prefix . 'user_roles' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
		$db->query( $db->prepare( "UPDATE $u SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key = %s AND meta_value LIKE %s", 'apse_', 'asem_', $db->prefix . 'capabilities', '%' . $db->esc_like( 'apse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
	}

	/** Metadati di utenti e pagine (regole di accesso, campi di appoggio). */
	private static function metadata( \wpdb $db ): void {
		$db->query( $db->prepare( "UPDATE IGNORE {$db->usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", 'asem_', $db->esc_like( 'apse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
		$db->query( $db->prepare( "UPDATE {$db->postmeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", '_asem_', $db->esc_like( '_aps_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
		$db->query( $db->prepare( "UPDATE {$db->postmeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", 'asem_', $db->esc_like( 'apse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
	}

	/** Shortcode, blocchi e widget Elementor già messi nelle pagine. */
	private static function content( \wpdb $db ): void {
		$pairs = array(
			'[apsemplice_'        => '[associazionesemplice_',
			'<!-- wp:apsemplice/'  => '<!-- wp:associazionesemplice/',
			'<!-- /wp:apsemplice/' => '<!-- /wp:associazionesemplice/',
		);
		foreach ( $pairs as $from => $to ) {
			$db->query( $db->prepare( "UPDATE {$db->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $from, $to, '%' . $db->esc_like( $from ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
		}
		$db->query( $db->prepare( "UPDATE {$db->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key = %s AND meta_value LIKE %s", 'apsemplice_', 'associazionesemplice_', '_elementor_data', '%' . $db->esc_like( 'apsemplice_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- una tantum
	}

	/** I vecchi lavori periodici non servono più: quelli nuovi li pianifica il plugin. */
	private static function cron(): void {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return;
		}
		$changed = false;
		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( array_keys( $hooks ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'apse_' ) ) {
					unset( $cron[ $ts ][ $hook ] );
					$changed = true;
				}
			}
			if ( empty( $cron[ $ts ] ) ) {
				unset( $cron[ $ts ] );
			}
		}
		if ( $changed ) {
			_set_cron_array( $cron );
		}
	}

	/** La cartella privata degli allegati prende il nuovo nome. */
	private static function folder(): void {
		$u = wp_upload_dir( null, false );
		$b = rtrim( (string) $u['basedir'], '/\\' );
		if ( is_dir( $b . '/apsemplice-private' ) && ! file_exists( $b . '/' . Attachments::DIR_NAME ) ) {
			@rename( $b . '/apsemplice-private', $b . '/' . Attachments::DIR_NAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- una tantum
		}
	}

	/** I vecchi plugin (cartelle `apsemplice` e `apsemplice-pro`) vengono disattivati: non devono lavorare sugli stessi dati. */
	private static function deactivate_old_plugins(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( array( 'apsemplice/apsemplice.php', 'apsemplice-pro/apsemplice-pro.php' ), true );
	}
}
