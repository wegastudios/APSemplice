<?php
namespace ApSemplice\Admin;

use ApSemplice\Audit;

defined( 'ABSPATH' ) || exit;

/** Registro delle azioni: chi ha fatto cosa e quando. */
final class AuditPage {

	public static function render(): void {
		$prefix = Ui::get_str( 'type' );
		$rows   = Audit::recent( 200, $prefix );
		Ui::header( 'Registro azioni' );
		echo '<form method="get" class="aps-filters"><input type="hidden" name="page" value="aps-audit"><select name="type" onchange="this.form.submit()">'
			. Ui::options(
				array( 'person.' => 'Persone', 'membership.' => 'Iscrizioni', 'activity.' => 'Attività', 'tx.' => 'Movimenti', 'cashcount.' => 'Verifiche cassa', 'import.' => 'Import', 'settings.' => 'Impostazioni' ),
				$prefix,
				'Tutte le azioni'
			) . '</select></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped"><thead><tr><th>Quando</th><th>Chi</th><th>Azione</th><th>Oggetto</th><th>Dettagli</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessuna azione registrata.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$details = $r['details'] ? json_decode( $r['details'], true ) : array();
			$text    = array();
			foreach ( (array) $details as $k => $v ) {
				$text[] = $k . ': ' . ( is_array( $v ) ? implode( ' → ', $v ) : (string) $v );
			}
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ) . '</td><td>' . esc_html( (string) ( $r['display_name'] ?: '—' ) ) . '</td>'
				. '<td><code>' . esc_html( $r['action'] ) . '</code></td><td>' . esc_html( trim( $r['object_type'] . ' ' . $r['object_id'] ) ) . '</td><td>' . esc_html( implode( ' · ', $text ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}
}
