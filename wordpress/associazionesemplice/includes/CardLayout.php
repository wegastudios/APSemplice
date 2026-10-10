<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tessera personalizzata: l'associazione sceglie le dimensioni in centimetri (orizzontale, verticale, quadrata o misura propria), un'immagine
 * di sfondo facoltativa e dove posizionare logo, nome e cognome, numero di tessera, data di scadenza e (se attivo) il QR. Posizioni in
 * percentuale della larghezza e dell'altezza; dimensioni in percentuale della larghezza della tessera, così la tessera si ridimensiona da sola
 * ed è uguale a schermo e in stampa (in stampa ha le dimensioni in centimetri scelte).
 */
final class CardLayout {

	const MODE_STANDARD = 'standard';
	const MODE_IMAGE    = 'image'; // tessera personalizzata (con o senza immagine di sfondo)

	const MIN_CM = 3.0;
	const MAX_CM = 30.0;

	/** Campo => etichetta. */
	const FIELDS = array(
		'logo'   => 'Logo',
		'name'   => 'Nome e cognome',
		'number' => 'Numero di tessera',
		'valid'  => 'Data di scadenza',
		'qr'     => 'QR della tessera',
	);

	/** Forme proposte: chiave => [etichetta, larghezza cm, altezza cm]. La misura propria si scrive a mano. */
	const SHAPES = array(
		'landscape' => array( 'Orizzontale (come una carta di credito)', 8.56, 5.4 ),
		'portrait'  => array( 'Verticale', 5.4, 8.56 ),
		'square'    => array( 'Quadrata', 6.0, 6.0 ),
	);

	/** Campi che si dimensionano in percentuale della larghezza della tessera (immagini) invece che come grandezza delle lettere. */
	const BOXED = array( 'logo', 'qr' );

	/** Posizioni e dimensioni predefinite (x e y in %, size in % della larghezza, color). */
	public static function defaults(): array {
		return array(
			'logo'   => array( 'show' => 1, 'x' => 6, 'y' => 8, 'size' => 24.0, 'color' => '#000000' ),
			'name'   => array( 'show' => 1, 'x' => 6, 'y' => 58, 'size' => 5.0, 'color' => '#222222' ),
			'number' => array( 'show' => 1, 'x' => 6, 'y' => 74, 'size' => 3.5, 'color' => '#222222' ),
			'valid'  => array( 'show' => 1, 'x' => 6, 'y' => 84, 'size' => 3.5, 'color' => '#222222' ),
			'qr'     => array( 'show' => 1, 'x' => 76, 'y' => 52, 'size' => 20.0, 'color' => '#000000' ),
		);
	}

	/** Ripulisce una disposizione ricevuta da un modulo o dalle impostazioni; ciò che manca prende il valore predefinito. */
	public static function clean( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();
		foreach ( $out as $k => $d ) {
			$r = isset( $in[ $k ] ) && is_array( $in[ $k ] ) ? $in[ $k ] : array();
			$c = Color::normalize( (string) ( $r['color'] ?? '' ) );
			$out[ $k ] = array(
				'show'  => array_key_exists( 'show', $r ) ? ( empty( $r['show'] ) ? 0 : 1 ) : $d['show'],
				'x'     => max( 0.0, min( 100.0, round( (float) ( $r['x'] ?? $d['x'] ), 1 ) ) ),
				'y'     => max( 0.0, min( 100.0, round( (float) ( $r['y'] ?? $d['y'] ), 1 ) ) ),
				'size'  => max( 1.0, min( 100.0, round( (float) ( $r['size'] ?? $d['size'] ), 1 ) ) ),
				'color' => '' !== $c ? $c : $d['color'],
			);
			if ( ! in_array( $k, self::BOXED, true ) ) {
				$out[ $k ]['size'] = min( 40.0, $out[ $k ]['size'] ); // le lettere non vanno oltre il 40% della larghezza
			}
		}
		return $out;
	}

	public static function layout(): array {
		return self::clean( Settings::get( 'card_layout' ) );
	}

	/** Una misura in centimetri dentro i limiti (con un decimale). */
	public static function clean_cm( $v, float $fallback ): float {
		$f = (float) str_replace( ',', '.', (string) $v );
		if ( $f <= 0 ) {
			$f = $fallback;
		}
		return max( self::MIN_CM, min( self::MAX_CM, round( $f, 2 ) ) );
	}

	/** @return array{w:float,h:float} larghezza e altezza della tessera in centimetri */
	public static function dims(): array {
		return array(
			'w' => self::clean_cm( Settings::get( 'card_w_cm' ), self::SHAPES['landscape'][1] ),
			'h' => self::clean_cm( Settings::get( 'card_h_cm' ), self::SHAPES['landscape'][2] ),
		);
	}

	/** Quale forma è quella scelta ('custom' se non coincide con nessuna proposta). */
	public static function shape(): string {
		$d = self::dims();
		foreach ( self::SHAPES as $key => $s ) {
			if ( abs( $s[1] - $d['w'] ) < 0.05 && abs( $s[2] - $d['h'] ) < 0.05 ) {
				return $key;
			}
		}
		return 'custom';
	}

	public static function bg_url(): string {
		$id = (int) Settings::get( 'card_bg_id' );
		$u  = $id > 0 ? wp_get_attachment_image_url( $id, 'large' ) : false;
		return $u ? (string) $u : '';
	}

	/** La tessera personalizzata è scelta? (l'immagine di sfondo è facoltativa: senza, la tessera è bianca) */
	public static function active(): bool {
		return self::MODE_IMAGE === (string) Settings::get( 'card_mode' );
	}

	/** Stile di un campo di testo posizionato. */
	private static function style( array $f, bool $with_color = true ): string {
		return 'left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;font-size:' . (float) $f['size'] . 'cqw;' . ( $with_color ? 'color:' . $f['color'] . ';' : '' );
	}

	/** Stile del contenitore: proporzioni e larghezza in stampa dalle misure in centimetri. */
	public static function box_style(): string {
		$d = self::dims();
		return 'aspect-ratio:' . $d['w'] . '/' . $d['h'] . ';--asemf-card-w:' . $d['w'] . 'cm;--asemf-card-px:' . (int) round( min( 640, $d['w'] * 74.8 ) ) . ';';
	}

	/**
	 * La tessera personalizzata con i dati sopra.
	 *
	 * @param string $name   nome e cognome
	 * @param string $number numero di tessera (testo, già pronto)
	 * @param string $valid  testo della scadenza (già pronto)
	 * @param string $qr_svg QR come SVG già pronto ('' se non c'è)
	 */
	public static function html( string $name, string $number, string $valid, string $qr_svg ): string {
		$l    = self::layout();
		$bg   = self::bg_url();
		$html = '<div class="asemf-memcard asemf-memcard-img' . ( '' === $bg ? ' asemf-card-plain' : '' ) . '" style="' . esc_attr( self::box_style() ) . '">'
			. ( '' !== $bg ? '<img class="asemf-card-bg" src="' . esc_url( $bg ) . '" alt="">' : '' );
		$logo = Frontend\Assets::logo_url();
		if ( $l['logo']['show'] && '' !== $logo ) {
			$html .= '<img class="asemf-card-f asemf-card-logo" src="' . esc_url( $logo ) . '" alt="" style="left:' . (float) $l['logo']['x'] . '%;top:' . (float) $l['logo']['y'] . '%;width:' . (float) $l['logo']['size'] . '%">';
		}
		if ( $l['name']['show'] && '' !== $name ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['name'] ) ) . '">' . esc_html( $name ) . '</span>';
		}
		if ( $l['number']['show'] && '' !== $number ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['number'] ) ) . '">' . esc_html( $number ) . '</span>';
		}
		if ( $l['valid']['show'] && '' !== $valid ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['valid'] ) ) . '">' . esc_html( $valid ) . '</span>';
		}
		if ( $l['qr']['show'] && '' !== $qr_svg ) {
			$html .= '<span class="asemf-card-f asemf-card-qr" style="left:' . (float) $l['qr']['x'] . '%;top:' . (float) $l['qr']['y'] . '%;width:' . (float) $l['qr']['size'] . '%">' . $qr_svg . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato dal plugin
		}
		return $html . '</div>';
	}

	/** Testi di esempio per l'anteprima e per il trascinamento nell'amministrazione. */
	public static function samples(): array {
		return array( 'logo' => 'Logo', 'name' => 'Nome Cognome', 'number' => 'N. 123', 'valid' => 'Valida fino al 31/12/' . ( (int) current_time( 'Y' ) + 1 ), 'qr' => 'QR' );
	}
}
