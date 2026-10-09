<?php
/**
 * Plugin Name:       APSemplice Pro
 * Description:       Funzioni avanzate per APSemplice: pagamenti online, conti e fondi, contabilità e report, registri, comunicazioni, app e Wallet. Richiede APSemplice.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  apsemplice
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * License:           GPL-2.0-or-later
 * Text Domain:       apsemplice-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'APSE_PRO_VERSION', '1.1.1' );
define( 'APSE_PRO_API', 1 ); // livello di compatibilità richiesto: deve coincidere con APSE_API di APSemplice
define( 'APSE_PRO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Installare e aggiornare APSemplice e APSemplice Pro si può fare in qualunque ordine e anche uno solo dei due: questo file non dà mai
 * errori. Le versioni sono indipendenti; contano solo la presenza di APSemplice e il livello di compatibilità (`APSE_API`), che cambia
 * di rado. Se non ci sono o non corrispondono il Pro non carica le sue funzioni e dice cosa fare (i dati non si toccano).
 *
 * @return string '' se si può caricare, altrimenti il messaggio da mostrare
 */
function apse_pro_problem(): string {
	if ( ! class_exists( 'ApSemplice\Edition' ) || ! defined( 'APSE_VERSION' ) ) {
		return '<strong>APSemplice Pro</strong> ha bisogno del plugin <strong>APSemplice</strong>: installalo e attivalo.';
	}
	if ( ! defined( 'APSE_API' ) || APSE_API !== APSE_PRO_API ) {
		return '<strong>APSemplice Pro</strong> non è compatibile con questa versione di APSemplice: aggiorna APSemplice Pro e APSemplice all\'ultima versione. Intanto le funzioni avanzate sono sospese; il resto funziona e i dati sono al sicuro.';
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
