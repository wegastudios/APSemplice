<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Azzeramento dei dati dell'associazione, per ripartire da zero.
 *
 *  - «data»: cancella soci, ospiti, attività, prima nota, registri, pagamenti, comunicazioni, ricevute e allegati; restano impostazioni,
 *    testi, pagine e registro azioni. Si ricreano conti, voci e livelli predefiniti.
 *  - «factory»: ripristino di fabbrica, come appena installato: cancella anche impostazioni, testi, pagine create dalla procedura
 *    e registro azioni, e rilancia la configurazione guidata.
 *
 * Prima di cancellare si salva sul sito una copia completa (con gli allegati): se la copia non riesce non si cancella nulla.
 * Le tabelle si svuotano in un'unica transazione. Gli utenti WordPress non si toccano, tranne (a scelta) chi ha il solo ruolo «Socio».
 */
final class Reset {

	const DATA    = 'data';
	const FACTORY = 'factory';

	/** Frase da scrivere per confermare. */
	const PHRASE = 'AZZERA TUTTO';

	/** Opzioni del plugin che il ripristino di fabbrica cancella. */
	const FACTORY_OPTIONS = array( 'asem_settings', 'asem_texts', 'asem_pages', 'asem_access_requests', 'asem_wizard_status', 'asem_backup_last' );

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Tabelle da svuotare (nomi senza prefisso). Il registro azioni resta, salvo nel ripristino di fabbrica. */
	public static function tables( string $mode ): array {
		return array_values( array_filter( Backup::tables(), function ( $t ) use ( $mode ) {
			return self::FACTORY === $mode || 'asem_audit_log' !== $t;
		} ) );
	}

	/** Utenti WordPress che hanno soltanto il ruolo «Socio» e sono collegati a una persona (mai l'utente che sta operando). @return int[] */
	public static function member_only_users(): array {
		$out = array();
		foreach ( self::db()->get_col( 'SELECT DISTINCT wp_user_id FROM ' . Db::t( 'people' ) . ' WHERE wp_user_id IS NOT NULL AND wp_user_id > 0' ) as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( $u && (int) $uid !== get_current_user_id() && Gatekeeper::is_member_only( (array) $u->roles ) ) {
				$out[] = (int) $uid;
			}
		}
		return $out;
	}

	/** Quanto verrebbe cancellato. @return array<string,int> */
	public static function preview(): array {
		$db = self::db();
		$n  = function ( string $table, string $where = '1=1' ) use ( $db ) {
			return (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE ' . $where );
		};
		$files = 0;
		foreach ( glob( Attachments::dir() . '/*' ) ?: array() as $f ) {
			if ( is_file( $f ) && '.htaccess' !== basename( $f ) && 'index.php' !== basename( $f ) ) {
				$files++;
			}
		}
		return array(
			'people'       => $n( 'people', "deleted_at IS NULL AND type <> 'guest'" ),
			'guests'       => $n( 'people', "deleted_at IS NULL AND type = 'guest'" ),
			'activities'   => $n( 'activities' ),
			'transactions' => $n( 'transactions', 'voided_at IS NULL' ),
			'memberships'  => $n( 'memberships', 'deleted_at IS NULL' ),
			'minutes'      => $n( 'minutes' ),
			'attachments'  => $n( 'attachments' ),
			'files'        => $files,
			'users'        => count( self::member_only_users() ),
		);
	}

	/**
	 * Esegue l'azzeramento.
	 *
	 * @return array riepilogo: tabelle, utenti eliminati, file eliminati
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public static function run( string $mode, bool $delete_users ): array {
		if ( ! in_array( $mode, array( self::DATA, self::FACTORY ), true ) ) {
			throw new \InvalidArgumentException( 'Scegli che tipo di azzeramento fare.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- lettura/scrittura in streaming di file grandi
		}
		if ( ! Backup::downloaded_recently() ) { // la copia non resta sul sito (conterrebbe i dati che si vogliono cancellare): va scaricata e conservata da chi azzera
			throw new \InvalidArgumentException( 'Scarica prima una copia completa dei dati e conservala: non è stato cancellato nulla.' );
		}
		$users = $delete_users ? self::member_only_users() : array();
		$db    = self::db();
		$names = self::tables( $mode );
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( $names as $name ) {
				$tbl = $db->prefix . $name;
				$db->query( "DELETE FROM `$tbl`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw new \RuntimeException( 'Azzeramento non riuscito, nulla è stato cancellato: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		foreach ( $names as $name ) { // numerazione di nuovo da 1 (fuori dalla transazione)
			$db->query( 'ALTER TABLE `' . $db->prefix . $name . '` AUTO_INCREMENT = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Allegati (scontrini e fatture): si tolgono i file, non la cartella delle copie di sicurezza.
		$files = 0;
		foreach ( glob( Attachments::dir() . '/*' ) ?: array() as $f ) {
			if ( is_file( $f ) && '.htaccess' !== basename( $f ) && 'index.php' !== basename( $f ) ) {
				wp_delete_file( $f );
			}
			if ( ! file_exists( $f ) ) {
				$files++;
			}
		}

		// Ruoli e riferimenti alle persone che non ci sono più.
		foreach ( array( Access::TREASURER_META, Access::STAFF_META ) as $meta ) {
			delete_metadata( 'user', 0, $meta, '', true );
		}
		delete_metadata( 'post', 0, '_asem_access_activities', '', true );

		if ( self::FACTORY === $mode ) {
			foreach ( self::FACTORY_OPTIONS as $o ) {
				delete_option( $o );
			}
		}
		$removed = 0;
		if ( $users ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $users as $uid ) {
				if ( wp_delete_user( $uid ) ) {
					$removed++;
				}
			}
		}
		Backup::delete_saved(); // anche le copie salvate sul sito: i dati azzerati non devono restare altrove
		delete_user_meta( get_current_user_id(), Backup::META_DOWNLOADED );
		Install::seed(); // conti, voci, livelli e anno solare predefiniti
		if ( self::FACTORY === $mode ) {
			Wizard::schedule_first_run();
		}
		Audit::log( 'system.reset', 'settings', 0, array( 'mode' => $mode, 'users' => $removed, 'files' => $files ) );
		return array( 'tables' => count( $names ), 'users' => $removed, 'files' => $files, 'mode' => $mode );
	}
}
