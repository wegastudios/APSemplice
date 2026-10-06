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
		// Tipo di ente e termini
		$s     = \ApSemplice\Settings::all();
		$ents  = \ApSemplice\Terms::entity_types( (string) $s['entity_types_custom'] );
		$mems  = \ApSemplice\Terms::member_terms( (string) $s['member_terms_custom'] );
		$e_opt = array();
		foreach ( $ents as $n => $gen ) {
			$e_opt[ $n ] = $n . ' (' . ( 'f' === $gen ? 'femminile' : 'maschile' ) . ')';
		}
		$m_opt = array();
		foreach ( $mems as $n => $d ) {
			$m_opt[ $n ] = $n . ' / ' . $d[0] . ' (' . ( 'f' === $d[1] ? 'femminile' : 'maschile' ) . ')';
		}
		echo '<h2>Versione base</h2><p class="description">Due versioni pronte: <strong>femminile</strong> (associazione, socie) e <strong>maschile</strong> (comitato, soci). Poi puoi comunque cambiare ogni scelta qui sotto.</p>';
		Ui::form_open( 'apse_save_terms', Ui::url( 'apse-texts' ), false, 'apse-inline' );
		echo '<button class="button" name="preset" value="femminile">Usa la versione al femminile</button> <button class="button" name="preset" value="maschile">Usa la versione al maschile</button>';
		Ui::form_close();
		echo '<h2>Tipo di ente e termini</h2><p class="description">I testi sono scritti per un\'«associazione» con «soci». Scegli com\'è fatto il tuo ente e come chiami chi partecipa: tutti i testi si adattano da soli, con gli articoli giusti. '
			. 'Il genere (femminile o maschile) serve per «la/il», «della/del», «le/i»…</p>';
		Ui::form_open( 'apse_save_terms', Ui::url( 'apse-texts' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Tipo di ente</th><td><select name="entity_type">' . Ui::options( $e_opt, (string) $s['entity_type'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">Altri tipi, uno per riga: <code>nome;f</code> oppure <code>nome;m</code> (f = femminile come «associazione», m = maschile come «comitato»). Es. <code>fondazione;f</code></p>'
			. '<textarea name="entity_types_custom" rows="3" class="large-text" placeholder="fondazione;f">' . esc_textarea( (string) $s['entity_types_custom'] ) . '</textarea></td></tr>';
		echo '<tr><th>Chi partecipa sono</th><td><select name="member_term">' . Ui::options( $m_opt, (string) $s['member_term'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">Altri termini, uno per riga: <code>singolare;plurale;f|m</code>. Es. <code>tesserato;tesserati;m</code> oppure <code>amica;amiche;f</code></p>'
			. '<textarea name="member_terms_custom" rows="3" class="large-text" placeholder="tesserato;tesserati;m">' . esc_textarea( (string) $s['member_terms_custom'] ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva tipo di ente e termini' );
		Ui::form_close();
		$tmap = \ApSemplice\Terms::map();
		echo '<p><strong>Anteprima</strong> <span class="description">(come si leggono ora i testi)</span></p><ul>';
		foreach ( array( 'Il socio ha rinnovato la tessera dell\'associazione.', 'Ai soci e ai nuovi soci arriva un avviso dall\'associazione.', 'Benvenuto, nuovo socio! Nell\'associazione tutti i soci hanno gli stessi diritti.', 'Per l\'associazione, il Presidente' ) as $sample ) {
			echo '<li>' . esc_html( \ApSemplice\Terms::apply_map( $sample, $tmap ) ) . '</li>';
		}
		echo '</ul><p class="description">Si cambiano parole intere (mai «soci» dentro «sociale») e gli aggettivi o i participi collegati possono restare al genere originale: se serve, correggili qui sotto nei testi personalizzati.</p><hr>';

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
			echo '<table class="widefat striped apse-texts"><thead><tr><th style="width:12%">Gruppo</th><th style="width:26%">Originale</th><th style="width:26%">Versione in uso</th><th>Personalizzato</th></tr></thead><tbody>';
			foreach ( $slice as $r ) {
				echo '<tr><td class="description">' . esc_html( $r['group'] ) . '</td><td>' . esc_html( $r['text'] ) . '</td><td>' . esc_html( \ApSemplice\Terms::apply( $r['text'] ) ) . '</td>'
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
