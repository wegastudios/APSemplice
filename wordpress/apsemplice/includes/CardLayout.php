<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tessera su un'immagine propria: l'associazione carica il disegno della tessera e sceglie dove posizionarci sopra nome e cognome, numero
 * di tessera, data di scadenza e (se attivo) il QR. Posizioni in percentuale della larghezza e dell'altezza; dimensioni in percentuale della
 * larghezza della tessera, così la tessera si ridimensiona da sola ed è uguale a schermo e in stampa.
 */
final class CardLayout {

	const MODE_STANDARD = 'standard';
	const MODE_IMAGE    = 'image';

	/** Campo => etichetta. */
	const FIELDS = array(
		'name'   => 'Nome e cognome',
		'number' => 'Numero di tessera',
		'valid'  => 'Data di scadenza',
		'qr'     => 'QR della tessera',
	);

	/** Posizioni e dimensioni predefinite (x e y in %, size in % della larghezza, color). */
	public static function defaults(): array {
		return array(
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
				'size'  => max( 1.0, min( 40.0, round( (float) ( $r['size'] ?? $d['size'] ), 1 ) ) ),
				'color' => '' !== $c ? $c : $d['color'],
			);
		}
		return $out;
	}

	public static function layout(): array {
		return self::clean( Settings::get( 'card_layout' ) );
	}

	public static function bg_url(): string {
		$id = (int) Settings::get( 'card_bg_id' );
		$u  = $id > 0 ? wp_get_attachment_image_url( $id, 'large' ) : false;
		return $u ? (string) $u : '';
	}

	/** La tessera su immagine è scelta e l'immagine c'è? */
	public static function active(): bool {
		return self::MODE_IMAGE === (string) Settings::get( 'card_mode' ) && '' !== self::bg_url();
	}

	/** Stile di un campo posizionato. */
	private static function style( array $f, bool $with_color = true ): string {
		return 'left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;font-size:' . (float) $f['size'] . 'cqw;' . ( $with_color ? 'color:' . $f['color'] . ';' : '' );
	}

	/**
	 * La tessera su immagine con i dati sopra.
	 *
	 * @param string $name   nome e cognome
	 * @param string $number numero di tessera (testo, già pronto)
	 * @param string $valid  testo della scadenza (già pronto)
	 * @param string $qr_svg QR come SVG già pronto ('' se non c'è)
	 */
	public static function html( string $name, string $number, string $valid, string $qr_svg ): string {
		$l    = self::layout();
		$html = '<div class="apsf-memcard apsf-memcard-img"><img class="apsf-card-bg" src="' . esc_url( self::bg_url() ) . '" alt="">';
		if ( $l['name']['show'] && '' !== $name ) {
			$html .= '<span class="apsf-card-f" style="' . esc_attr( self::style( $l['name'] ) ) . '">' . esc_html( $name ) . '</span>';
		}
		if ( $l['number']['show'] && '' !== $number ) {
			$html .= '<span class="apsf-card-f" style="' . esc_attr( self::style( $l['number'] ) ) . '">' . esc_html( $number ) . '</span>';
		}
		if ( $l['valid']['show'] && '' !== $valid ) {
			$html .= '<span class="apsf-card-f" style="' . esc_attr( self::style( $l['valid'] ) ) . '">' . esc_html( $valid ) . '</span>';
		}
		if ( $l['qr']['show'] && '' !== $qr_svg ) {
			$html .= '<span class="apsf-card-f apsf-card-qr" style="left:' . (float) $l['qr']['x'] . '%;top:' . (float) $l['qr']['y'] . '%;width:' . (float) $l['qr']['size'] . '%">' . $qr_svg . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato dal plugin
		}
		return $html . '</div>';
	}

	/** Testi di esempio per l'anteprima e per il trascinamento nell'amministrazione. */
	public static function samples(): array {
		return array( 'name' => 'Nome Cognome', 'number' => 'N. 123', 'valid' => 'Valida fino al 31/12/' . ( (int) current_time( 'Y' ) + 1 ), 'qr' => 'QR' );
	}
}
