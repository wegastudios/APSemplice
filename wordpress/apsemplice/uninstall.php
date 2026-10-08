<?php
/**
 * Eliminazione del plugin da WordPress.
 *
 * Di default i dati restano (soci, prima nota, allegati, impostazioni). Si cancellano solo se l'amministratore ha acceso
 * l'opzione «Cancella tutti i dati se il plugin viene eliminato» nelle impostazioni.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Cancella i dati del plugin del sito corrente. */
function apse_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'apse_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	foreach ( array( 'apse_send_reminders', 'apse_release_holds', 'apse_broadcast_batch', 'apse_check_pending_payments' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Tabelle del plugin.
	$like = $wpdb->esc_like( $wpdb->prefix . 'apse_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Opzioni e dati temporanei.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'apse_' ) . '%', $wpdb->esc_like( '_transient_apse_' ) . '%', $wpdb->esc_like( '_transient_timeout_apse_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'apse_' ) . '%' ) );

	// Ruoli del plugin: gli utenti che li avevano restano su WordPress.
	remove_role( 'apse_member' );
	remove_role( 'apse_secretary' );
	foreach ( array( 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( 0 === strpos( $cap, 'apse_' ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	// Cartella privata (allegati e copie di sicurezza).
	$upload = wp_upload_dir( null, false );
	$dir    = rtrim( (string) $upload['basedir'], '/\' ) . '/apsemplice-private';
	if ( is_dir( $dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $dir, true );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $apse_blog_id ) {
		switch_to_blog( $apse_blog_id );
		apse_uninstall_site();
		restore_current_blog();
	}
} else {
	apse_uninstall_site();
}
