<?php
namespace ApSemplice\Admin;

use ApSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Testi personalizzati. */
final class TextsPage {

	const PER_PAGE = 40;

	public static function render(): void {
		$all    = Texts::rows();
		$groups = array();
		foreach ( $all as $r ) {
			$groups[ $r['group'] ] = ( $groups[ $r['group'] ] ?? 0 ) + 1;
		}
		$g      = Ui::get_str( 'g' );
		$q      = Ui::get_str( 'q' );
		$custom = '1' === Ui::get_str( 'custom' );
		$rows   = array_values(
			array_filter(
				$all,
				function ( $r ) use ( $g, $q, $custom ) {
					return ( '' === $g || $r['group'] === $g )
						&& ( ! $custom || '' !== $r['custom'] )
						&& ( '' === $q || false !== mb_stripos( $r['text'] . ' ' . $r['custom'], $q ) );
				}
			)
		);
		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = max( 1, min( $pages, Ui::get_int( 'paged', 1 ) ) );
		$slice  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$here   = Ui::url( 'apse-texts', array_filter( array( 'g' => $g, 'q' => $q, 'custom' => $custom ? '1' : '', 'paged' => $paged > 1 ? $paged : '' ) ) );
		$n_cust = count( Texts::overrides() );

		Ui::header( 'Testi personalizzati' );
		echo '<p>Cambia qui qualsiasi testo che il sito mostra: pagine dei soci, email e promemoria, ricevute in PDF, messaggi di conferma ed errore, etichette e amministrazione. '
			. 'Scrivi nella colonna <strong>Personalizzato</strong> il testo che vuoi al posto dell\'originale; lascia vuoto per tenere quello di default. '
			. 'Per modificarne molti insieme <strong>esporta il file</strong> (si apre in Excel), cambia la colonna Personalizzato e <strong>importalo</strong>.</p>';
		echo '<p class="description">La sostituzione vale ovunque compaia quel testo (anche dentro frasi più lunghe che lo contengono). Testi con nomi, date e importi variabili sono fatti di più pezzi: ogni pezzo si cambia a parte. '
			. 'Nelle email e nei PDF il testo è semplice (niente formattazione). Se un pezzo non è nell\'elenco, aggiungilo in fondo con «Aggiungi una sostituzione».</p>';

		echo '<p><a class="button button-primary" href="' . esc_url( Texts::export_url() ) . '">Esporta tutti i testi (CSV)</a> '
			. '<a class="button" href="' . esc_url( Texts::export_url( true ) ) . '">Esporta solo i personalizzati (' . (int) $n_cust . ')</a></p>';
		Ui::form_open( 'apse_import_texts', $here, true, 'apse-inline' );
		echo '<p><strong>Importa:</strong> <input type="file" name="texts_file" accept=".csv,.xlsx" required> <button class="button">Importa il file</button> '
			. '<span class="description">Il file è quello esportato (CSV o Excel): vale la colonna «Personalizzato». Una riga con Personalizzato vuoto toglie la sostituzione.</span></p>';
		Ui::form_close();

		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-texts">'
			. '<select name="g"><option value="">Tutti i gruppi</option>';
		foreach ( $groups as $name => $c ) {
			echo '<option value="' . esc_attr( $name ) . '"' . selected( $g, $name, false ) . '>' . esc_html( $name . ' (' . $c . ')' ) . '</option>';
		}
		echo '</select> <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Cerca nei testi"> '
			. '<label><input type="checkbox" name="custom" value="1"' . checked( $custom, true, false ) . '> Solo personalizzati</label> <button class="button">Filtra</button> '
			. '<span class="description">' . (int) $total . ' testi</span></form>';

		if ( ! $slice ) {
			echo '<p>Nessun testo con questi filtri.</p>';
		} else {
			Ui::form_open( 'apse_save_texts', $here );
			echo '<table class="widefat striped apse-texts"><thead><tr><th style="width:16%">Gruppo</th><th style="width:40%">Originale</th><th>Personalizzato</th></tr></thead><tbody>';
			foreach ( $slice as $r ) {
				echo '<tr><td class="description">' . esc_html( $r['group'] ) . '</td><td>' . esc_html( $r['text'] ) . '</td>'
					. '<td><textarea name="t[' . esc_attr( md5( $r['text'] ) ) . ']" rows="' . ( strlen( $r['text'] ) > 70 ? 3 : 1 ) . '" class="large-text" maxlength="' . (int) Texts::MAX_LEN . '">' . esc_textarea( $r['custom'] ) . '</textarea></td></tr>';
			}
			echo '</tbody></table>';
			submit_button( 'Salva i testi di questa pagina' );
			Ui::form_close();
			if ( $pages > 1 ) {
				echo '<p class="tablenav">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					$u = Ui::url( 'apse-texts', array_filter( array( 'g' => $g, 'q' => $q, 'custom' => $custom ? '1' : '', 'paged' => $i > 1 ? $i : '' ) ) );
					echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( $u ) . '">' . (int) $i . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</p>';
			}
		}

		echo '<h2>Aggiungi una sostituzione</h2><p class="description">Per un pezzo di testo che non trovi nell\'elenco: scrivi com\'è oggi (almeno 3 caratteri) e come lo vuoi.</p>';
		Ui::form_open( 'apse_add_text', $here, false, 'apse-inline' );
		echo '<input type="text" name="original" placeholder="Testo di oggi" class="regular-text" required> → <input type="text" name="custom" placeholder="Testo nuovo" class="regular-text" required> <button class="button">Aggiungi</button>';
		Ui::form_close();

		echo '<h2>Ripristino</h2>';
		Ui::form_open( 'apse_reset_texts', $here, false, 'apse-inline' );
		echo '<button class="button" data-confirm="Togliere TUTTE le sostituzioni e tornare ai testi originali?">Ripristina tutti i testi originali</button>';
		Ui::form_close();
		Ui::footer();
	}
}
