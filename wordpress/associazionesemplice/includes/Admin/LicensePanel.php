<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\License;
use AssociazioneSemplice\LicenseClient;
use AssociazioneSemplice\LicensePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Sezione «Licenza» delle impostazioni: chiave, stato, dominio, controllo immediato e i collegamenti al portale (chiave gratuita, rinnovo,
 * cambio di dominio, prova del Pro). Il plugin funziona anche senza chiave: la licenza abilita i servizi collegati e le funzioni Pro.
 */
final class LicensePanel {

	public static function render(): void {
		$lic = License::status();
		echo '<h2 id="asem-license">Licenza</h2><div class="asem-card">';
		if ( $lic['local'] ) {
			echo '<p>Questo è un ambiente di sviluppo locale: non richiede licenza.</p>';
		} elseif ( ! $lic['enabled'] ) {
			echo '<p>La verifica della licenza non è ancora attiva su questo sito: tutte le funzioni installate sono disponibili.</p>';
		} else {
			echo '<p><strong>Livello:</strong> ' . esc_html( $lic['plan_label'] ) . ' · ' . esc_html( $lic['note'] ) . '</p>';
			if ( '' !== $lic['reason'] && LicensePolicy::STATUS_ACTIVE !== $lic['status'] ) {
				echo '<p>' . esc_html( $lic['reason'] ) . '</p>';
			}
			if ( '' !== $lic['error'] ) {
				echo '<p class="description">L\'ultimo controllo non è riuscito: ' . esc_html( $lic['error'] ) . ' Restano valide le impostazioni dell\'ultimo controllo riuscito' . ( $lic['checked_at'] ? ' (' . esc_html( mysql2date( 'd/m/Y H:i', (string) $lic['checked_at'] ) ) . ')' : '' ) . '.</p>';
			}
		}
		echo '<p>Dominio della licenza: <code>' . esc_html( $lic['domain'] ) . '</code>. La chiave vale per questo dominio; si cambia dall\'area riservata del portale.</p>';
		Ui::form_open( 'asem_save_license', Ui::url( 'asem-settings' ) );
		echo '<p><label for="asem_license_key"><strong>Chiave di licenza</strong></label><br><input type="text" id="asem_license_key" name="license_key" value="' . esc_attr( License::key() ) . '" class="regular-text" autocomplete="off" placeholder="WEGA-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"> ';
		submit_button( 'Salva e verifica', 'primary', 'submit', false );
		echo '</p><p class="description">La chiave gratuita si ottiene dal portale, in due minuti. Con un piano Pro la chiave del piano acquistato sostituisce quella gratuita.</p>';
		Ui::form_close();
		echo '<p>';
		echo '<a class="button" href="' . esc_url( License::free_url() ) . '" target="_blank" rel="noopener">Ottieni la chiave gratuita</a> ';
		echo '<a class="button" href="' . esc_url( (string) apply_filters( 'asem_license_portal_url', 'https://associazionesemplice.it/accedi' ) ) . '" target="_blank" rel="noopener">Gestisci la licenza e il piano</a>';
		echo '</p>';
		if ( LicenseClient::enabled() && '' !== License::key() ) {
			Ui::form_open( 'asem_license_check', Ui::url( 'asem-settings' ) );
			echo '<p><button class="button">Controlla adesso</button> <span class="description">Verifica la licenza e cerca nuove versioni del plugin. Il controllo avviene da solo una volta al giorno.</span></p>';
			echo '<p class="description">' . esc_html( \AssociazioneSemplice\UpdateCheck::summary() ) . '</p>';
			Ui::form_close();
		}
		echo '</div>';
	}
}
