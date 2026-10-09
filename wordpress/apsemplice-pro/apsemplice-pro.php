<?php
/**
 * Plugin Name:       APSemplice Pro
 * Description:       Funzioni avanzate per APSemplice: pagamenti online, conti e fondi, contabilità e report, registri, comunicazioni, app e Wallet. Richiede APSemplice.
 * Version:           1.1.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  apsemplice
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * License:           GPL-2.0-or-later
 * Text Domain:       apsemplice-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'APSE_PRO_VERSION', '1.1.9' );
define( 'APSE_PRO_DIR', plugin_dir_path( __FILE__ ) );

// All'attivazione di Pro mancano ancora i dati di partenza delle funzioni avanzate (ad esempio il conto corrente accanto alla cassa).
register_activation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'ApSemplice\\Edition' ) ) {
			\ApSemplice\Edition::add_dir( plugin_dir_path( __FILE__ ) . 'includes' );
			\ApSemplice\Install::seed();
		}
	}
);

/**
 * Le funzioni avanzate sono le stesse classi di APSemplice (stesso spazio dei nomi) messe in una cartella a parte: il plugin gratuito
 * le trova da solo appena questa cartella gli viene indicata. Senza APSemplice attivo non si fa niente e si avvisa.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'ApSemplice\Edition' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>APSemplice Pro</strong> ha bisogno del plugin <strong>APSemplice</strong>: installalo e attivalo.</p></div>';
					}
				}
			);
			return;
		}
		\ApSemplice\Edition::add_dir( APSE_PRO_DIR . 'includes' );
	},
	5 // prima che APSemplice si avvii (priorità 10)
);
