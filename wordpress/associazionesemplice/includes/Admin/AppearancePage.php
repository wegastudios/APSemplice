<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\CardLayout;
use AssociazioneSemplice\Frontend\Assets;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Aspetto: logo e colori principale e secondario. Di default si prendono dal sito (Elementor o tema); ognuno si può personalizzare.
 * Valgono per tessera, pulsanti e pagine di verifica dei soci. Solo amministratori.
 */
final class AppearancePage {

	/** Scelta della tessera: quella generata dal sito oppure personalizzata (misure, immagine di sfondo facoltativa, dati e logo trascinabili). */
	private static function card_section( array $s ): void {
		$bg     = CardLayout::bg_url();
		$layout = CardLayout::layout();
		$sample = CardLayout::samples();
		$dims   = CardLayout::dims();
		$shape  = CardLayout::shape();
		$mode   = CardLayout::MODE_IMAGE === (string) $s['card_mode'] ? CardLayout::MODE_IMAGE : CardLayout::MODE_STANDARD;
		$acc    = Assets::accent( '#2271b1' );
		$sec    = Assets::secondary( $acc );
		$logo   = Assets::logo_url();
		echo '<h2>Tessera</h2><input type="hidden" name="card_present" value="1">';
		echo '<p><label><input type="radio" name="card_mode" value="standard"' . checked( CardLayout::MODE_STANDARD === $mode, true, false ) . '> <strong>Tessera standard</strong>: bianca, con il logo e i testi nel colore principale.</label><br>'
			. '<label><input type="radio" name="card_mode" value="image"' . checked( CardLayout::MODE_IMAGE === $mode, true, false ) . '> <strong>Tessera personalizzata</strong>: scegli le misure e trascina logo, nome, numero, scadenza e QR dove vuoi, sulla tessera generata dal sito oppure su un\'immagine tua.</label></p>';
		echo '<div id="asem-card-image"><h3>Misure</h3><p>'
			. '<select name="card_shape" id="asem-card-shape">';
		foreach ( CardLayout::SHAPES as $key => $sh ) {
			echo '<option value="' . esc_attr( $key ) . '" data-w="' . (float) $sh[1] . '" data-h="' . (float) $sh[2] . '"' . selected( $shape, $key, false ) . '>' . esc_html( $sh[0] ) . '</option>';
		}
		echo '<option value="custom"' . selected( $shape, 'custom', false ) . '>Misura propria</option></select> '
			. 'larghezza <input type="number" step="0.01" min="' . (float) CardLayout::MIN_CM . '" max="' . (float) CardLayout::MAX_CM . '" name="card_w_cm" id="asem-card-w" value="' . (float) $dims['w'] . '" style="width:80px"> cm '
			. 'altezza <input type="number" step="0.01" min="' . (float) CardLayout::MIN_CM . '" max="' . (float) CardLayout::MAX_CM . '" name="card_h_cm" id="asem-card-h" value="' . (float) $dims['h'] . '" style="width:80px"> cm '
			. '<span class="description">In stampa la tessera ha queste misure.</span></p>';
		echo '<h3>Sfondo e posizione dei dati</h3><input type="hidden" name="card_bg_id" id="asem-card-bg-id" value="' . (int) $s['card_bg_id'] . '">'
			. '<p><button type="button" class="button" id="asem-cardbg-pick">Scegli un\'immagine di sfondo</button> <button type="button" class="button" id="asem-cardbg-clear"' . ( '' !== $bg ? '' : ' style="display:none"' ) . '>Usa la tessera generata dal sito</button> '
			. '<span class="description">Facoltativa: senza immagine la tessera è quella generata dal sito (bianca, con i colori scelti sopra). Con un\'immagine, meglio con le stesse proporzioni delle misure e con uno spazio libero dove vanno i dati. Trascina le scritte dove vuoi, poi regola dimensione e colore.</span></p>';
		$max = (int) round( min( 640, $dims['w'] * 74.8 ) );
		echo '<div id="asem-cardedit" class="' . ( '' === $bg ? 'asem-plain' : '' ) . '" data-acc="' . esc_attr( $acc ) . '" data-sec="' . esc_attr( $sec ) . '" style="position:relative;width:100%;max-width:' . absint( $max ) . 'px;container-type:inline-size;aspect-ratio:' . (float) $dims['w'] . '/' . (float) $dims['h'] . ';border:1px solid #c3c4c7;background:#fff;box-sizing:border-box;border-radius:14px;overflow:hidden;' . ( '' === $bg ? 'border:2px solid ' . esc_attr( $acc ) . ';border-top:8px solid ' . esc_attr( $sec ) . ';' : '' ) . '">';
		echo '<img id="asem-cardedit-img" src="' . esc_url( $bg ) . '" alt="" style="display:' . ( '' !== $bg ? 'block' : 'none' ) . ';position:absolute;inset:0;width:100%;height:100%;object-fit:cover">';
		foreach ( CardLayout::FIELDS as $k => $label ) {
			$f = $layout[ $k ];
			if ( 'qr' === $k ) {
				echo '<span class="asem-chip" data-f="qr" style="position:absolute;left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;width:' . (float) $f['size'] . '%;aspect-ratio:1;background:#fff;border:1px dashed #333;cursor:move;display:flex;align-items:center;justify-content:center;font-size:3cqw;color:#333">QR</span>';
			} elseif ( 'logo' === $k ) {
				echo '<span class="asem-chip" data-f="logo" style="position:absolute;left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;width:' . (float) $f['size'] . '%;cursor:move;outline:1px dashed rgba(0,0,0,.45);line-height:0">'
					. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="width:100%;height:auto;display:block;pointer-events:none">' : '<span style="display:block;padding:2cqw;font-size:3cqw;line-height:1.2;color:#333">Logo</span>' ) . '</span>';
			} else {
				echo '<span class="asem-chip" data-f="' . esc_attr( $k ) . '" style="position:absolute;left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;font-size:' . (float) $f['size'] . 'cqw;color:' . esc_attr( $f['color'] ) . ';cursor:move;white-space:nowrap;font-weight:600;outline:1px dashed rgba(0,0,0,.45)">' . esc_html( $sample[ $k ] ) . '</span>';
			}
		}
		echo '</div>';
		echo '<table class="widefat striped" style="max-width:640px;margin-top:10px"><thead><tr><th>Dato</th><th>Mostra</th><th>Dimensione</th><th>Colore</th><th>Posizione (%)</th></tr></thead><tbody>';
		foreach ( CardLayout::FIELDS as $k => $label ) {
			$f     = $layout[ $k ];
			$boxed = in_array( $k, CardLayout::BOXED, true );
			echo '<tr><td>' . esc_html( $label ) . '</td>'
				. '<td><input type="hidden" name="layout[' . esc_attr( $k ) . '][show]" value="0"><input type="checkbox" name="layout[' . esc_attr( $k ) . '][show]" value="1"' . checked( ! empty( $f['show'] ), true, false ) . '></td>'
				. '<td><input type="number" step="any" min="1" max="' . ( $boxed ? 100 : 40 ) . '" name="layout[' . esc_attr( $k ) . '][size]" value="' . (float) $f['size'] . '" style="width:70px">' . ( $boxed ? ' <span class="description">% larghezza</span>' : '' ) . '</td>'
				. '<td>' . ( $boxed ? '—' : '<input type="color" name="layout[' . esc_attr( $k ) . '][color]" value="' . esc_attr( $f['color'] ) . '">' ) . '</td>'
				. '<td>x <input type="number" step="any" min="0" max="100" name="layout[' . esc_attr( $k ) . '][x]" value="' . (float) $f['x'] . '" style="width:70px"> y <input type="number" step="any" min="0" max="100" name="layout[' . esc_attr( $k ) . '][y]" value="' . (float) $f['y'] . '" style="width:70px"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table><p class="description">Per logo e QR la dimensione è la larghezza in percentuale della tessera; per i testi è la grandezza delle lettere (percentuale della larghezza). La tessera si stampa uguale a come la vedi qui.</p>'
			. '<p><button type="submit" class="button button-primary" id="asem-card-save">Salva la tessera</button></p></div>';
	}

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
		Ui::form_open( 'asem_save_look', Ui::url( 'asem-look' ) );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>Logo</th><td><div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">'
			. '<div id="asem-logo-preview" style="min-width:120px;min-height:56px;padding:8px;border:1px dashed #c3c4c7;background:#fff">'
			. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="max-height:56px;max-width:220px;display:block">' : '<span class="description">Nessun logo trovato</span>' ) . '</div>'
			. '<input type="hidden" name="logo_id" id="asem-logo-id" value="' . (int) $own_id . '">'
			. '<button type="button" class="button" id="asem-logo-pick">Scegli dalla libreria</button> '
			. '<button type="button" class="button" id="asem-logo-clear"' . ( $own_id ? '' : ' style="display:none"' ) . '>Usa il logo del sito</button></div>'
			. '<p class="description">' . ( $own_id ? 'Stai usando il logo scelto qui.' : 'Stai usando il logo del sito' . ( '' === $logo ? ' (non ne è stato trovato uno: scegline uno).' : '.' ) )
			. ' Compare sulla tessera e nelle pagine di verifica.</p></td></tr>';

		echo '<tr><th>Colore principale</th><td><label><input type="checkbox" name="primary_custom" value="1"' . checked( $p_own, true, false ) . '> Personalizza</label> '
			. '<input type="color" name="accent_color" value="' . esc_attr( $p_own ? (string) $s['accent_color'] : Assets::accent( '#2271b1' ) ) . '">'
			. '<p class="description">Tessera e pulsanti. Senza spunta si usa il colore principale del sito' . ( '' !== $site_p ? ' (ora: <code>' . esc_html( $site_p ) . '</code>)' : ' (non rilevato: si usa un blu neutro)' ) . '.</p></td></tr>';

		echo '<tr><th>Colore secondario</th><td><label><input type="checkbox" name="secondary_custom" value="1"' . checked( $s_own, true, false ) . '> Personalizza</label> '
			. '<input type="color" name="secondary_color" value="' . esc_attr( $s_own ? (string) $s['secondary_color'] : Assets::secondary( Assets::accent( '#2271b1' ) ) ) . '">'
			. '<p class="description">Filetto in alto sulla tessera e passaggio del mouse sui pulsanti. Senza spunta si usa il colore secondario del sito' . ( '' !== $site_s ? ' (ora: <code>' . esc_html( $site_s ) . '</code>)' : ' (non rilevato: il filetto ha il colore principale)' ) . '.</p></td></tr>';
		echo '</tbody></table>';
		self::card_section( $s );
		submit_button( 'Salva' );
		Ui::form_close();

		$acc = Assets::accent( '#2271b1' );
		$sec = Assets::secondary( $acc );
		if ( CardLayout::active() ) { // la tessera personalizzata come l'hanno salvata: misure, sfondo, logo e posizioni
			wp_enqueue_style( 'asem-frontend', ASEM_URL . 'assets/frontend.css', array(), \AssociazioneSemplice\Plugin::asset_version( 'frontend.css' ) );
			$vars = Assets::inline_css();
			if ( '' !== $vars ) {
				wp_add_inline_style( 'asem-frontend', $vars );
			}
			$samples = CardLayout::samples();
			try {
				$qr = \AssociazioneSemplice\QrCode::svg( home_url( '/' ), 4, 'QR di esempio' );
			} catch ( \InvalidArgumentException $e ) {
				$qr = '';
			}
			echo '<h2>Anteprima della tessera</h2><p class="description">È la tessera salvata: ' . esc_html( (string) CardLayout::dims()['w'] ) . ' × ' . esc_html( (string) CardLayout::dims()['h'] ) . ' cm. I soci la vedono così e la stampano con queste misure.</p>'
				. '<div style="--asemf-accent:' . esc_attr( $acc ) . ';--asemf-accent-2:' . esc_attr( $sec ) . '">' . CardLayout::html( $samples['name'], $samples['number'], $samples['valid'], $qr ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto da CardLayout
		} else {
		echo '<h2>Anteprima della tessera</h2><div style="max-width:380px;border-radius:14px;padding:20px 24px;background:#fff;color:' . esc_attr( $acc ) . ';border:2px solid ' . esc_attr( $acc ) . ';border-top:8px solid ' . esc_attr( $sec ) . '">'
			. ( '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="display:block;max-height:52px;max-width:190px;margin:0 0 10px">' : '' )
			. '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase">' . esc_html( (string) Settings::get( 'association_name' ) ) . '</div>'
			. '<div style="font-size:24px;font-weight:700;margin-top:6px">Nome Cognome</div><div style="opacity:.9">Socio ordinario</div>'
			. '<div style="display:flex;gap:32px;margin-top:14px"><div><div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.8">Tessera n.</div><div style="font-size:20px;font-weight:600">123</div></div>'
			. '<div><div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.8">Valida fino al</div><div style="font-size:20px;font-weight:600">31/12/' . ( (int) current_time( 'Y' ) + 1 ) . '</div></div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<script>(function(){var pick=document.getElementById("asem-logo-pick"),clr=document.getElementById("asem-logo-clear"),id=document.getElementById("asem-logo-id"),pv=document.getElementById("asem-logo-preview"),f;'
			. 'if(!pick){return;}'
			. 'pick.addEventListener("click",function(e){e.preventDefault();if(!window.wp||!wp.media){window.alert("La libreria media non è disponibile: ricarica la pagina.");return;}if(!f){f=wp.media({title:"Logo dell\'ente",button:{text:"Usa questo logo"},library:{type:"image"},multiple:false});'
			. 'f.on("select",function(){var a=f.state().get("selection").first().toJSON();id.value=a.id;var u=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;pv.innerHTML="<img src=\""+u+"\" alt=\"\" style=\"max-height:56px;max-width:220px;display:block\">";clr.style.display="";});}f.open();});'
			. 'clr.addEventListener("click",function(e){e.preventDefault();id.value="0";pv.innerHTML="<span class=\"description\">Sarà usato il logo del sito dopo il salvataggio</span>";clr.style.display="none";});})();</script>';
		Ui::footer();
	}
}
