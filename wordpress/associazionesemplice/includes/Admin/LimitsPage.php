<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Limits;

defined( 'ABSPATH' ) || exit;

/** Impostazioni → Tecniche → Limiti e soglie: ogni limite del plugin con il suo valore predefinito e i rischi di alzarlo o abbassarlo. */
final class LimitsPage {

	public static function render(): void {
		Ui::header( 'Limiti e soglie' );
		echo '<p>Qui trovi i limiti, le dimensioni dei gruppi di invio e i comportamenti automatici del plugin. I <strong>valori predefiniti sono prudenti</strong> e adatti a un sito su un hosting condiviso: '
			. 'proteggono i soci da messaggi in eccesso, il sito dai rallentamenti e la reputazione dell\'indirizzo email dell\'associazione. '
			. 'Se hai un servizio di invio dedicato, un server più potente o esigenze particolari puoi cambiarli. Ogni valore ha un minimo e un massimo di sicurezza, e sotto ciascuno è spiegato cosa succede se lo alzi o lo abbassi.</p>';
		echo '<div class="notice notice-info inline"><p><strong>Come regolarti.</strong> Cambia un valore alla volta e osserva per qualche giorno. Alzare un limite non rende il sito più veloce: sposta il rischio dal plugin al server, al servizio di posta o ai gateway di pagamento. '
			. 'Abbassarlo aumenta la sicurezza ma può bloccare operazioni legittime. Il pulsante «Ripristina i valori predefiniti» riporta tutto com\'era.</p></div>';

		Ui::form_open( 'asem_save_limits', Ui::url( 'asem-limits' ) );
		foreach ( Limits::GROUPS as $gk => $gl ) {
			echo '<h2>' . esc_html( $gl ) . '</h2>';
			foreach ( Limits::defs() as $key => $d ) {
				if ( $d['group'] !== $gk ) {
					continue;
				}
				self::row( $key, $d );
			}
		}
		submit_button( 'Salva i limiti' );
		Ui::form_close();

		Ui::form_open( 'asem_reset_limits', Ui::url( 'asem-limits' ), false, 'asem-inline' );
		echo '<button class="button" data-confirm="Riportare tutti i limiti ai valori predefiniti?">Ripristina i valori predefiniti</button>';
		Ui::form_close();
		Ui::footer();
	}

	/** Un limite: etichetta, campo, predefinito e intervallo, e il riquadro con i rischi. */
	private static function row( string $key, array $d ): void {
		$value   = Limits::get( $key );
		$changed = $value !== (int) $d['default'];
		echo '<div class="asem-card asem-limit">';
		echo '<p><label for="asem-lim-' . esc_attr( $key ) . '"><strong>' . esc_html( $d['label'] ) . '</strong></label>' . ( $changed ? ' <span class="asem-warn">· modificato</span>' : '' ) . '</p>';
		if ( 'flag' === $d['unit'] ) {
			echo '<p><label><input type="checkbox" id="asem-lim-' . esc_attr( $key ) . '" name="lim[' . esc_attr( $key ) . ']" value="1"' . checked( 1 === $value, true, false ) . '> Consentito</label> '
				. '<span class="description">Predefinito: ' . ( 1 === (int) $d['default'] ? 'consentito' : 'non consentito' ) . '</span></p>';
		} else {
			echo '<p><input type="number" id="asem-lim-' . esc_attr( $key ) . '" name="lim[' . esc_attr( $key ) . ']" value="' . (int) $value . '" min="' . (int) $d['min'] . '" max="' . (int) $d['max'] . '" style="width:7em"> ' . esc_html( $d['unit'] )
				. ' <span class="description">Predefinito: ' . (int) $d['default'] . ' · consentito da ' . (int) $d['min'] . ' a ' . (int) $d['max'] . '</span></p>';
		}
		echo '<p class="description">' . esc_html( $d['what'] ) . '</p>';
		echo '<details><summary>Rischi di alzarlo o abbassarlo</summary>'
			. '<p><strong>' . ( 'flag' === $d['unit'] ? 'Se lo consenti' : 'Se lo alzi' ) . '.</strong> ' . esc_html( $d['raise'] ) . '</p>'
			. '<p><strong>' . ( 'flag' === $d['unit'] ? 'Se lo vieti' : 'Se lo abbassi' ) . '.</strong> ' . esc_html( $d['lower'] ) . '</p></details>';
		echo '</div>';
	}
}
