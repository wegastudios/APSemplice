<?php
namespace ApSemplice\Admin;

use ApSemplice\Frontend\Assets;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Aspetto: logo e colori principale e secondario. Di default si prendono dal sito (Elementor o tema); ognuno si può personalizzare.
 * Valgono per tessera, pulsanti e pagine di verifica dei soci. Solo amministratori.
 */
final class AppearancePage {

	public static function render(): void {
		wp_enqueue_media();
		$s       = Settings::all();
		$logo    = Assets::logo_url();
		$own_id  = (int) $s['logo_id'];
		$site_p  = Assets::theme_accent();
		$site_s  = Assets::theme_color( 'secondary' );
		$p_own   = '' !== (string) $s['accent_color'];
		$s_own   = '' !== (string) $s['secondary_color'];
		Ui::header( 'Impostazioni: aspetto' );
		echo '<p class="description">Logo e colori della tessera, dei pulsanti e delle pagine dei soci. Di default si prendono dal sito (Elementor o tema); se vuoi, scegli i tuoi.</p>';
		Ui::form_open( 'apse_save_look', Ui::url( 'apse-look' ) );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>Logo</th><td><div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">'
			. '<div id="apse-logo-preview" style="min-width:120px;min-height:56px;padding:8px;border:1px dashed #c3c4c7;background:#fff">'
			. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="max-height:56px;max-width:220px;display:block">' : '<span class="description">Nessun logo trovato</span>' ) . '</div>'
			. '<input type="hidden" name="logo_id" id="apse-logo-id" value="' . (int) $own_id . '">'
			. '<button type="button" class="button" id="apse-logo-pick">Scegli dalla libreria</button> '
			. '<button type="button" class="button" id="apse-logo-clear"' . ( $own_id ? '' : ' style="display:none"' ) . '>Usa il logo del sito</button></div>'
			. '<p class="description">' . ( $own_id ? 'Stai usando il logo scelto qui.' : 'Stai usando il logo del sito' . ( '' === $logo ? ' (non ne ho trovato uno: scegline uno tu).' : '.' ) )
			. ' Compare sulla tessera e nelle pagine di verifica, su un riquadro chiaro così si legge su qualunque colore.</p></td></tr>';

		echo '<tr><th>Colore principale</th><td><label><input type="checkbox" name="primary_custom" value="1"' . checked( $p_own, true, false ) . '> Personalizza</label> '
			. '<input type="color" name="accent_color" value="' . esc_attr( $p_own ? (string) $s['accent_color'] : Assets::accent( '#2271b1' ) ) . '">'
			. '<p class="description">Tessera e pulsanti. Senza spunta si usa il colore principale del sito' . ( '' !== $site_p ? ' (ora: <code>' . esc_html( $site_p ) . '</code>)' : ' (non lo trovo: uso un blu neutro)' ) . '.</p></td></tr>';

		echo '<tr><th>Colore secondario</th><td><label><input type="checkbox" name="secondary_custom" value="1"' . checked( $s_own, true, false ) . '> Personalizza</label> '
			. '<input type="color" name="secondary_color" value="' . esc_attr( $s_own ? (string) $s['secondary_color'] : Assets::secondary( Assets::accent( '#2271b1' ) ) ) . '">'
			. '<p class="description">Sfumatura della tessera e passaggio del mouse sui pulsanti. Senza spunta si usa il colore secondario del sito' . ( '' !== $site_s ? ' (ora: <code>' . esc_html( $site_s ) . '</code>)' : ' (non lo trovo: la tessera resta di un solo colore)' ) . '.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();

		$acc = Assets::accent( '#2271b1' );
		$sec = Assets::secondary( $acc );
		echo '<h2>Anteprima</h2><div style="max-width:380px;border-radius:16px;padding:20px 24px;color:' . esc_attr( \ApSemplice\Color::text_on( $acc ) ) . ';background:linear-gradient(135deg,' . esc_attr( $acc ) . ',' . esc_attr( $sec ) . ')">'
			. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="display:block;max-height:44px;max-width:170px;margin:0 0 10px;padding:4px 8px;border-radius:6px;background:rgba(255,255,255,.92)">' : '' )
			. '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85">' . esc_html( (string) Settings::get( 'association_name' ) ) . '</div>'
			. '<div style="font-size:24px;font-weight:700;margin-top:6px">Nome Cognome</div><div style="opacity:.9">Socio ordinario</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<script>(function(){var pick=document.getElementById("apse-logo-pick"),clr=document.getElementById("apse-logo-clear"),id=document.getElementById("apse-logo-id"),pv=document.getElementById("apse-logo-preview"),f;'
			. 'if(!pick||!window.wp||!wp.media){return;}'
			. 'pick.addEventListener("click",function(e){e.preventDefault();if(!f){f=wp.media({title:"Logo dell\'ente",button:{text:"Usa questo logo"},library:{type:"image"},multiple:false});'
			. 'f.on("select",function(){var a=f.state().get("selection").first().toJSON();id.value=a.id;var u=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;pv.innerHTML="<img src=\""+u+"\" alt=\"\" style=\"max-height:56px;max-width:220px;display:block\">";clr.style.display="";});}f.open();});'
			. 'clr.addEventListener("click",function(e){e.preventDefault();id.value="0";pv.innerHTML="<span class=\"description\">Sarà usato il logo del sito dopo il salvataggio</span>";clr.style.display="none";});})();</script>';
		Ui::footer();
	}
}
