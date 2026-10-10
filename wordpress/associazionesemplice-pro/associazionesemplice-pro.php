<?php
/**
 * Plugin Name:       AssociazioneSemplice Pro
 * Description:       Funzioni avanzate per AssociazioneSemplice: pagamenti online, conti e fondi, contabilità e report, registri, comunicazioni, app e Wallet. Richiede AssociazioneSemplice.
 * Version:           1.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  associazionesemplice
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * License:           GPL-2.0-or-later
 * Text Domain:       associazionesemplice-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'ASEM_PRO_VERSION', '1.1.3' );
define( 'ASEM_PRO_API', 1 ); // livello di compatibilità richiesto: deve coincidere con ASEM_API di AssociazioneSemplice
define( 'ASEM_PRO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Installare e aggiornare AssociazioneSemplice e AssociazioneSemplice Pro si può fare in qualunque ordine e anche uno solo dei due: questo file non dà mai
 * errori. Le versioni sono indipendenti; contano solo la presenza di AssociazioneSemplice e il livello di compatibilità (`ASEM_API`), che cambia
 * di rado. Se non ci sono o non corrispondono il Pro non carica le sue funzioni e dice cosa fare (i dati non si toccano).
 *
 * @return string '' se si può caricare, altrimenti il messaggio da mostrare
 */
function asem_pro_problem(): string {
	if ( ! class_exists( 'AssociazioneSemplice\Edition' ) || ! defined( 'ASEM_VERSION' ) ) {
		return '<strong>AssociazioneSemplice Pro</strong> ha bisogno del plugin <strong>AssociazioneSemplice</strong>: installalo e attivalo.';
	}
	if ( ! defined( 'ASEM_API' ) || ASEM_API !== ASEM_PRO_API ) {
		return '<strong>AssociazioneSemplice Pro</strong> non è compatibile con questa versione di AssociazioneSemplice: aggiorna AssociazioneSemplice Pro e AssociazioneSemplice all\'ultima versione. Intanto le funzioni avanzate sono sospese; il resto funziona e i dati sono al sicuro.';
	}
	return '';
}

// All'attivazione di Pro mancano ancora i dati di partenza delle funzioni avanzate (ad esempio il conto corrente accanto alla cassa).
register_activation_hook(
	__FILE__,
	function () {
		if ( '' === asem_pro_problem() ) {
			\AssociazioneSemplice\Edition::add_dir( plugin_dir_path( __FILE__ ) . 'includes' );
			\AssociazioneSemplice\Install::seed();
		}
	}
);

/**
 * Le funzioni avanzate sono le stesse classi di AssociazioneSemplice (stesso spazio dei nomi) messe in una cartella a parte: il plugin gratuito
 * le trova da solo appena questa cartella gli viene indicata.
 */
add_action(
	'plugins_loaded',
	function () {
		$problem = asem_pro_problem();
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
		\AssociazioneSemplice\Edition::add_dir( ASEM_PRO_DIR . 'includes' );
	},
	5 // prima che AssociazioneSemplice si avvii (priorità 10)
);
