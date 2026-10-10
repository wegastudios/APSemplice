<?php
/**
 * Plugin Name:       AssociazioneSemplice
 * Description:       Gestione di soci, attività, prima nota, cassa e bilancio per associazioni di promozione sociale (APS).
 * Version:           1.1.10
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * Text Domain:       associazionesemplice
 */

defined( 'ABSPATH' ) || exit;

define( 'ASEM_VERSION', '1.1.10' );
define( 'ASEM_API', 1 ); // livello di compatibilità con AssociazioneSemplice Pro: cambia solo se cambia ciò che i due plugin si scambiano
define( 'ASEM_FILE', __FILE__ );
define( 'ASEM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASEM_URL', plugin_dir_url( __FILE__ ) );

// Autoload semplice: AssociazioneSemplice\Foo => includes/Foo.php, AssociazioneSemplice\Admin\Bar => includes/Admin/Bar.php
// (il plugin Pro aggiunge la sua cartella con Edition::add_dir: le sue classi usano lo stesso spazio dei nomi)
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AssociazioneSemplice\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		$file = ASEM_DIR . 'includes/' . $rel;
		if ( ! is_readable( $file ) && class_exists( 'AssociazioneSemplice\\Edition', false ) ) {
			$file = (string) \AssociazioneSemplice\Edition::locate( $rel );
		}
		if ( '' !== $file && is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'AssociazioneSemplice\\Install', 'activate' ) );
add_action( 'plugins_loaded', array( 'AssociazioneSemplice\\Plugin', 'init' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'asem_check_pending_payments' );
	}
);
