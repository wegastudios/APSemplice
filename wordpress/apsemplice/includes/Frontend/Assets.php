<?php
namespace ApSemplice\Frontend;

use ApSemplice\Color;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Stile e script del front-end: caricati solo quando serve (quando una vista o un riquadro viene stampato). */
final class Assets {

	/** CSS generato dalle impostazioni (colore d'accento). Stringa vuota = si usa il colore del tema. */
	public static function inline_css(): string {
		$accent = Color::normalize( (string) Settings::get( 'accent_color' ) );
		if ( '' === $accent ) {
			return '';
		}
		return '.apsf{--apsf-accent:' . $accent . ';--apsf-accent-text:' . Color::text_on( $accent ) . ';}';
	}

	public static function enqueue(): void {
		if ( wp_style_is( 'apse-frontend', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'apse-frontend', APSE_URL . 'assets/frontend.css', array(), \ApSemplice\Plugin::asset_version( 'frontend.css' ) );
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'apse-frontend', $css );
		}
		wp_enqueue_script( 'apse-frontend', APSE_URL . 'assets/frontend.js', array(), \ApSemplice\Plugin::asset_version( 'frontend.js' ), true );
	}
}
