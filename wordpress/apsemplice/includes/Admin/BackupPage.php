<?php
namespace ApSemplice\Admin;

use ApSemplice\Backup;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Copia di sicurezza. */
final class BackupPage {

	public static function render(): void {
		Ui::header( 'Copia di sicurezza' );
		$last = Backup::last();
		echo '<p>Scarica un file con <strong>tutti i dati</strong> del plugin (soci, attività, prima nota, fondi, ricevute, comunicazioni…) e le impostazioni. Conservalo in un posto sicuro, fuori dal sito. '
			. 'Le chiavi segrete dei pagamenti online non sono incluse.</p>';
		echo '<p>' . ( $last ? 'Ultima copia scaricata: <strong>' . esc_html( wp_date( 'd/m/Y H:i', $last ) ) . '</strong>' . ( time() - $last > 30 * DAY_IN_SECONDS ? ' <span class="apse-warn">(più di 30 giorni fa)</span>' : '' ) : '<span class="apse-warn">Nessuna copia scaricata finora.</span>' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Backup::download_url( false ) ) . '">Scarica la copia dei dati</a> '
			. '<a class="button" href="' . esc_url( Backup::download_url( true ) ) . '">Scarica la copia con gli allegati (scontrini e fatture)</a></p>';
		if ( ! class_exists( '\ZipArchive' ) ) {
			echo '<div class="notice notice-error"><p>Questo server non ha l\'estensione zip di PHP: la copia non si può creare.</p></div>';
		}

		echo '<h2>Ripristino</h2><p class="description">Ripristina una copia scaricata da questo sito: <strong>i dati attuali del plugin vengono sostituiti</strong> da quelli della copia. '
			. 'Prima del ripristino il sito salva da solo una copia dello stato attuale (qui sotto), e se qualcosa non va non viene cambiato nulla. '
			. 'Gli utenti WordPress non fanno parte della copia: il ripristino è pensato per lo stesso sito.</p>';
		Ui::form_open( 'apse_backup_restore', Ui::url( 'apse-backup' ), true );
		echo '<p><input type="file" name="backup_file" accept=".zip" required></p>'
			. '<p><label><input type="checkbox" name="confirm" value="1" required> Ho capito: i dati attuali verranno sostituiti da quelli della copia.</label></p>'
			. '<p><button class="button" data-confirm="Sostituire i dati attuali con quelli della copia?">Ripristina dalla copia</button></p>';
		Ui::form_close();

		$saved = Backup::saved();
		if ( $saved ) {
			echo '<h2>Copie di sicurezza fatte prima dei ripristini</h2><ul>';
			foreach ( $saved as $s ) {
				echo '<li><a href="' . esc_url( Backup::saved_url( $s['name'] ) ) . '">' . esc_html( $s['name'] ) . '</a> <span class="description">' . esc_html( wp_date( 'd/m/Y H:i', $s['time'] ) ) . ' · ' . esc_html( size_format( $s['size'] ) ) . '</span></li>';
			}
			echo '</ul><p class="description">Si conservano le ultime ' . (int) Backup::KEEP . '. Per tornare indietro scarica il file e ripristinalo da qui.</p>';
		}
		Ui::footer();
	}
}
