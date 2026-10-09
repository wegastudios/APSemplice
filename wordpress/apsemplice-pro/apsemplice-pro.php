<?php
/**
 * Plugin Name:       APSemplice Pro
 * Description:       Funzioni avanzate per APSemplice: pagamenti online, conti e fondi, contabilità e report, registri, comunicazioni, app e Wallet. Richiede APSemplice.
 * Version:           1.1.13
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  apsemplice
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * License:           GPL-2.0-or-later
 * Text Domain:       apsemplice-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'APSE_PRO_VERSION', '1.1.13' );
define( 'APSE_PRO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Installare e aggiornare APSemplice e APSemplice Pro si può fare in qualunque ordine: questo file non dà mai errori. Se APSemplice manca
 * o ha una versione diversa da questa, il Pro non carica le sue funzioni e dice cosa fare (le funzioni avanzate restano sospese, i dati
 * non si toccano); appena le versioni coincidono si attiva da solo.
 *
 * @return string '' se si può caricare, altrimenti il messaggio da mostrare
 */
function apse_pro_problem(): string {
	if ( ! class_exists( 'ApSemplice\\Edition' ) || ! defined( 'APSE_VERSION' ) ) {
		return '<strong>APSemplice Pro</strong> ha bisogno del plugin <strong>APSemplice</strong>: installalo e attivalo.';
	}
	if ( APSE_VERSION !== APSE_PRO_VERSION ) {
		return '<strong>APSemplice Pro</strong> (versione ' . esc_html( APSE_PRO_VERSION ) . ') non corrisponde ad APSemplice (versione ' . esc_html( APSE_VERSION ) . '): aggiorna quello che è più vecchio con il pacchetto della stessa versione. Intanto le funzioni avanzate sono sospese; il resto funziona e i dati sono al sicuro.';
	}
	return '';
}

// All'attivazione di Pro mancano ancora i dati di partenza delle funzioni avanzate (ad esempio il conto corrente accanto alla cassa).
register_activation_hook(
	__FILE__,
	function () {
		if ( '' === apse_pro_problem() ) {
			\ApSemplice\Edition::add_dir( plugin_dir_path( __FILE__ ) . 'includes' );
			\ApSemplice\Install::seed();
		}
	}
);

/**
 * Le funzioni avanzate sono le stesse classi di APSemplice (stesso spazio dei nomi) messe in una cartella a parte: il plugin gratuito
 * le trova da solo appena questa cartella gli viene indicata.
 */
add_action(
	'plugins_loaded',
	function () {
		$problem = apse_pro_problem();
		if ( '' !== $problem ) {
			add_action(
				'admin_notices',
				function () use ( $problem ) {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . wp_kses( $problem, array( 'strong' => array() ) ) . '</p></div>';
					}
				}
			);
			return;
		}
		\ApSemplice\Edition::add_dir( APSE_PRO_DIR . 'includes' );
	},
	5 // prima che APSemplice si avvii (priorità 10)
);
