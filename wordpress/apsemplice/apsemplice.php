<?php
/**
 * Plugin Name:       APSemplice
 * Description:       Gestione di soci, attività, prima nota, cassa e bilancio per associazioni di promozione sociale (APS).
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * Text Domain:       apsemplice
 */

defined( 'ABSPATH' ) || exit;

define( 'APSE_VERSION', '1.1.1' );
define( 'APSE_FILE', __FILE__ );
define( 'APSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'APSE_URL', plugin_dir_url( __FILE__ ) );

// Autoload semplice: ApSemplice\Foo => includes/Foo.php, ApSemplice\Admin\Bar => includes/Admin/Bar.php
// (il plugin Pro aggiunge la sua cartella con Edition::add_dir: le sue classi usano lo stesso spazio dei nomi)
spl_autoload_register(
	function ( $class ) {
		$prefix = 'ApSemplice\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		$file = APSE_DIR . 'includes/' . $rel;
		if ( ! is_readable( $file ) && class_exists( 'ApSemplice\\Edition', false ) ) {
			$file = (string) \ApSemplice\Edition::locate( $rel );
		}
		if ( '' !== $file && is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ApSemplice\\Install', 'activate' ) );
add_action( 'plugins_loaded', array( 'ApSemplice\\Plugin', 'init' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'apse_check_pending_payments' );
	}
);
