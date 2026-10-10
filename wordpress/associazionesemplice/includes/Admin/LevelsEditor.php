<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Levels;
use AssociazioneSemplice\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Modifica dei livelli di socio (nome, base, quota propria, attivo). Fa parte delle funzioni avanzate: nell'edizione gratuita questo file
 * non c'è e i soci hanno un'unica quota, quella proposta nelle impostazioni, con l'opzione del socio fondatore
 * ({@see \AssociazioneSemplice\Edition}, funzione «levels»). La struttura dei livelli è la stessa nelle due edizioni.
 */
final class LevelsEditor {

	/** Livelli di socio: righe dinamiche (nome, base, quota, attivo), si aggiungono senza limiti. */
	public static function section(): void {
		$rows = array();
		foreach ( Levels::all() as $lv ) {
			$rows[] = array( 'id' => (int) $lv['id'], 'name' => $lv['name'], 'base' => $lv['base_type'], 'fee' => null === $lv['fee_cents'] ? '' : Money::plain( (int) $lv['fee_cents'] ), 'active' => (bool) (int) $lv['active'], 'used' => Levels::in_use( (int) $lv['id'] ) );
		}
		echo '<h2>Livelli di socio</h2><p class="description">Ogni livello ha il suo nome (come appare sulla tessera e negli elenchi), una base che ne decide il comportamento e, se serve, una quota propria. '
			. 'Lascia vuota la quota per usare quella proposta qui sopra: così puoi avere, ad esempio, soci ordinari, soci ridotti, sostenitori o soci onorari con quote diverse. '
			. 'Un livello con dei soci non si cancella: se lo togli resta, ma non si può più assegnare.</p>';
		Ui::form_open( 'asem_save_levels', Ui::url( 'asem-settings' ) );
		echo '<div class="asem-levels" data-bases="' . esc_attr( wp_json_encode( Levels::bases() ) ) . '" data-rows="' . esc_attr( wp_json_encode( $rows ) ) . '"><div class="asem-levels-rows"></div>'
			. '<p><button type="button" class="button" data-add="1">+ Aggiungi livello</button></p></div>';
		echo '<p><button class="button button-primary">Salva i livelli</button></p>';
		Ui::form_close();
	}
}
